"""Cycles: one occurrence of one ask, and the answers to it.

Generation is idempotent through the (enrolment, period_key) unique constraint, so the
cron can fire twice or two workers can race and still produce exactly one cycle
(doc 14 section 14.6).
"""

import logging
from datetime import date, datetime, timedelta

from odoo import _, api, fields, models
from odoo.exceptions import UserError

from messi_core import cadence as cadence_lib
from messi_core import lifecycle

_logger = logging.getLogger(__name__)

# How far ahead a commitment coming due is turned into a cycle to answer.
COMMITMENT_LOOKAHEAD = timedelta(hours=12)


class MessiCycle(models.Model):
    _name = "messi.cycle"
    _description = "Follow-up Cycle"
    _inherit = ["mail.thread"]
    _order = "due_at, id"

    enrolment_id = fields.Many2one("messi.enrolment", required=True, ondelete="cascade", index=True)
    module_id = fields.Many2one(related="enrolment_id.module_id", store=True, index=True)
    module_version_id = fields.Many2one("messi.module.version", required=True)
    player_id = fields.Many2one(related="enrolment_id.player_id", store=True, index=True)
    subject_name = fields.Char(related="enrolment_id.subject_name", store=True)

    period_key = fields.Char(required=True, index=True)
    opens_at = fields.Datetime(required=True)
    due_at = fields.Datetime(required=True, index=True)
    state = fields.Selection(
        [("pending", "Pending"), ("submitted", "Submitted"), ("late", "Late"),
         ("missed", "Missed"), ("skipped", "Skipped")],
        default="pending", required=True, index=True, tracking=True,
    )
    submitted_at = fields.Datetime(readonly=True)
    submitted_by = fields.Many2one("res.users", readonly=True)
    no_change = fields.Boolean(default=False)
    skip_reason = fields.Char()
    triggered_by_commitment_id = fields.Many2one("messi.commitment", ondelete="set null",
                                                 help="Set when this cycle exists to chase a promise.")
    answer_ids = fields.One2many("messi.answer", "cycle_id")
    commitment_ids = fields.One2many("messi.commitment", "cycle_id")
    company_id = fields.Many2one(related="enrolment_id.company_id", store=True)

    _sql_constraints = [
        ("uniq_period", "unique (enrolment_id, period_key)",
         "A cycle already exists for this enrolment and period."),
    ]

    def name_get(self):
        return [(c.id, f"{c.module_id.key} · {c.subject_name} · {c.period_key}") for c in self]

    # ------------------------------------------------------------ generation

    @api.model
    def _cron_generate(self):
        """Calendar generation. Runs hourly; safe to run more often."""
        now = fields.Datetime.now().replace(tzinfo=cadence_lib.UTC)
        created = 0
        enrolments = self.env["messi.enrolment"].search([("active", "=", True)])
        for enrolment in enrolments:
            if not enrolment.is_generating(date.today()):
                continue
            try:
                occurrence = cadence_lib.occurrence_for(
                    enrolment.effective_cadence(), now, enrolment.tz)
            except cadence_lib.CadenceError:
                _logger.exception("messi: bad cadence on enrolment %s", enrolment.id)
                continue
            if occurrence and self._upsert(enrolment, occurrence):
                created += 1
        if created:
            _logger.info("messi: generated %s cycle(s)", created)
        return created

    @api.model
    def _cron_spawn_commitment_cycles(self):
        """ADR-0008: the next ask lands on the day the player said something would happen."""
        now = fields.Datetime.now()
        due = self.env["messi.commitment"].search([
            ("state", "=", "open"),
            ("due_at", "<=", now + COMMITMENT_LOOKAHEAD),
            ("spawned_cycle_id", "=", False),
        ])
        created = 0
        for commitment in due:
            enrolment = commitment.enrolment_id
            if not enrolment.is_generating(date.today()):
                continue
            occurrence = cadence_lib.Occurrence(
                period_key=cadence_lib.commitment_period_key(commitment.id),
                opens_at=commitment.due_at.replace(tzinfo=cadence_lib.UTC),
                due_at=(commitment.due_at + timedelta(hours=9)).replace(tzinfo=cadence_lib.UTC),
            )
            cycle = self._upsert(enrolment, occurrence, commitment=commitment)
            if cycle:
                commitment.spawned_cycle_id = cycle.id
                created += 1
        return created

    @api.model
    def _upsert(self, enrolment, occurrence, commitment=None):
        """Returns the new cycle, or False when this period already has one."""
        existing = self.search([
            ("enrolment_id", "=", enrolment.id),
            ("period_key", "=", occurrence.period_key),
        ], limit=1)
        if existing:
            return False
        return self.create({
            "enrolment_id": enrolment.id,
            "module_version_id": enrolment.module_version_id.id,
            "period_key": occurrence.period_key,
            "opens_at": occurrence.opens_at.replace(tzinfo=None),
            "due_at": occurrence.due_at.replace(tzinfo=None),
            "triggered_by_commitment_id": commitment.id if commitment else False,
        })

    @api.model
    def _cron_reap(self):
        """Records what did not happen. A missed cycle is a fact on the record, not an
        absent row — that is what lets the leader stop asking 'did you do it?'."""
        now = fields.Datetime.now()
        stale = self.search([("state", "=", "pending"),
                             ("due_at", "<", now - lifecycle.DEFAULT_GRACE)])
        for cycle in stale:
            if lifecycle.should_mark_missed("pending", cycle.due_at, now):
                cycle.state = "missed"
        self.env["messi.commitment"]._cron_break_overdue()
        return len(stale)

    # ---------------------------------------------------------------- answering

    def action_submit(self):
        self.ensure_one()
        now = fields.Datetime.now()
        try:
            lifecycle.check_can_submit(self.state)
        except lifecycle.TransitionError as exc:
            raise UserError(str(exc)) from exc

        self._check_required_answered()
        self.write({
            "state": lifecycle.submit_status(self.due_at, now),
            "submitted_at": now,
            "submitted_by": self.env.user.id,
        })
        if self.triggered_by_commitment_id:
            self.triggered_by_commitment_id.resolve(resolved_cycle=self)
        self._capture_commitment()
        return True

    def action_no_change(self):
        """One tap. A real 'no change' is data; prose typed to escape a required field is
        not (doc 14 section 14.8)."""
        self.ensure_one()
        self.no_change = True
        self.enrolment_id.no_change_streak += 1
        return self.action_submit()

    def action_snooze(self, reason=None):
        self.ensure_one()
        reason = reason or self.skip_reason
        try:
            lifecycle.check_can_snooze(self.state, reason or "")
        except lifecycle.TransitionError as exc:
            raise UserError(str(exc)) from exc
        self.write({"state": "skipped", "skip_reason": reason})
        return True

    def _check_required_answered(self):
        self.ensure_one()
        if self.no_change:
            return
        answered = {a.question_key for a in self.answer_ids if a.has_value()}
        missing = [q.get("label") or q["key"]
                   for q in self.module_version_id.questions()
                   if q.get("required") and q.get("key") not in answered]
        if missing:
            raise UserError(_("Please answer: %s") % ", ".join(missing))

    def _capture_commitment(self):
        """Turns 'next action' + 'when' into a promise the system will chase."""
        self.ensure_one()
        mapping = self.module_version_id.commitment_mapping()
        if not mapping:
            return False
        answers = {a.question_key: a for a in self.answer_ids}
        action = answers.get(mapping.get("action", "next_action"))
        due = answers.get(mapping.get("due", "next_due"))
        if not action or not due or not action.has_value() or not due.has_value():
            return False
        due_at = due.as_datetime()
        if not due_at:
            return False
        owner_answer = answers.get(mapping.get("owner", "owner"))
        owner_id = owner_answer.as_user_id() if owner_answer else None
        return self.env["messi.commitment"].create({
            "cycle_id": self.id,
            "enrolment_id": self.enrolment_id.id,
            "action_text": action.value_text,
            "due_at": due_at,
            "owner_id": owner_id or self.player_id.id,
        })


class MessiAnswer(models.Model):
    _name = "messi.answer"
    _description = "Follow-up Answer"
    _order = "cycle_id, id"

    cycle_id = fields.Many2one("messi.cycle", required=True, ondelete="cascade", index=True)
    question_key = fields.Char(required=True)
    value_text = fields.Text()
    value_number = fields.Float()
    origin = fields.Selection(
        [("human", "Typed"), ("integration", "From an integration"), ("ai_extracted", "AI extracted")],
        default="human", required=True,
        help="Lets the dashboard show which figures are verified and which are self-reported.")
    confidence = fields.Float()
    company_id = fields.Many2one(related="cycle_id.company_id", store=True)

    _sql_constraints = [
        ("uniq_answer", "unique (cycle_id, question_key)", "One answer per question per cycle."),
    ]

    def has_value(self):
        self.ensure_one()
        return bool((self.value_text or "").strip()) or bool(self.value_number)

    def as_datetime(self):
        """Accepts a date (due at end of the working day) or a full timestamp."""
        self.ensure_one()
        raw = (self.value_text or "").strip()
        if not raw:
            return False
        for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%dT%H:%M:%S", "%Y-%m-%d", "%d/%m/%Y"):
            try:
                parsed = datetime.strptime(raw, fmt)
            except ValueError:
                continue
            if fmt in ("%Y-%m-%d", "%d/%m/%Y"):
                parsed = parsed.replace(hour=17)
            return parsed
        return False

    def as_user_id(self):
        self.ensure_one()
        raw = (self.value_text or "").strip()
        if raw.isdigit():
            user = self.env["res.users"].browse(int(raw)).exists()
            return user.id if user else None
        user = self.env["res.users"].search([("name", "=ilike", raw)], limit=1)
        return user.id if user else None
