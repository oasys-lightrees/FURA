"""Commitments: a promise extracted from an answer — this action, by this date, by whom.

The commitment is what makes the engine a chaser rather than a form. It also produces the
one number that measures follow-through: commitment-kept rate (ADR-0008).
"""

from odoo import _, api, fields, models
from odoo.exceptions import UserError

from ..lib import lifecycle


class MessiCommitment(models.Model):
    _name = "messi.commitment"
    _description = "Follow-up Commitment"
    _inherit = ["mail.thread"]
    _order = "due_at, id"

    cycle_id = fields.Many2one("messi.cycle", required=True, ondelete="cascade", index=True)
    enrolment_id = fields.Many2one("messi.enrolment", required=True, ondelete="cascade", index=True)
    module_id = fields.Many2one(related="enrolment_id.module_id", store=True)
    subject_name = fields.Char(related="enrolment_id.subject_name", store=True)
    action_text = fields.Text(required=True, tracking=True)
    due_at = fields.Datetime(required=True, index=True, tracking=True)
    owner_id = fields.Many2one("res.users", required=True, index=True, tracking=True)
    state = fields.Selection(
        [("open", "Open"), ("kept", "Kept"), ("broken", "Broken"),
         ("cancelled", "Cancelled"), ("superseded", "Superseded")],
        default="open", required=True, index=True, tracking=True,
    )
    resolved_at = fields.Datetime(readonly=True)
    resolved_cycle_id = fields.Many2one("messi.cycle", readonly=True)
    spawned_cycle_id = fields.Many2one("messi.cycle", readonly=True,
                                       help="The cycle created to chase this promise.")
    is_overdue = fields.Boolean(compute="_compute_is_overdue", search="_search_is_overdue")
    company_id = fields.Many2one(related="enrolment_id.company_id", store=True)

    @api.depends("due_at", "state")
    def _compute_is_overdue(self):
        now = fields.Datetime.now()
        for commitment in self:
            commitment.is_overdue = commitment.state == "open" and commitment.due_at < now

    def _search_is_overdue(self, operator, value):
        now = fields.Datetime.now()
        domain = [("state", "=", "open"), ("due_at", "<", now)]
        if (operator == "=" and not value) or (operator == "!=" and value):
            return ["!"] + domain
        return domain

    def resolve(self, resolved_cycle=None):
        """Kept if answered by its date, broken if not. A promise met late was not met —
        recording that honestly is what makes the rate mean anything."""
        now = fields.Datetime.now()
        for commitment in self:
            try:
                lifecycle.check_can_resolve(commitment.state)
            except lifecycle.TransitionError as exc:
                raise UserError(str(exc)) from exc
            commitment.write({
                "state": lifecycle.commitment_outcome(commitment.due_at, now),
                "resolved_at": now,
                "resolved_cycle_id": resolved_cycle.id if resolved_cycle else False,
            })
        return True

    def action_cancel(self):
        for commitment in self:
            lifecycle.check_can_resolve(commitment.state)
            commitment.write({"state": "cancelled", "resolved_at": fields.Datetime.now()})

    @api.model
    def _cron_break_overdue(self):
        """Escalation runs on its own. This is the event a leader should read; a kept
        promise needs no attention at all."""
        now = fields.Datetime.now()
        overdue = self.search([("state", "=", "open"),
                               ("due_at", "<", now - lifecycle.DEFAULT_GRACE)])
        broken = overdue.filtered(
            lambda c: lifecycle.should_mark_broken("open", c.due_at, now))
        for commitment in broken:
            commitment.state = "broken"
            commitment.message_post(body=_(
                "Promise not met by %s. Escalated.", fields.Datetime.to_string(commitment.due_at)))
        return len(broken)
