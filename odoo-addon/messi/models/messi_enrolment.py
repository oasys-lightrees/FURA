"""Enrolments: one standing obligation — this player, this subject, this cadence.

Cadence lives here, not only on the module, so a hot lead can be daily while a cold one
is monthly under the same module (doc 14 section 14.8).
"""

import json

from odoo import _, api, fields, models
from odoo.exceptions import ValidationError

from ..lib import cadence as cadence_lib


class MessiEnrolment(models.Model):
    _name = "messi.enrolment"
    _description = "Follow-up Enrolment"
    _order = "module_id, player_id"

    module_id = fields.Many2one("messi.module", required=True, ondelete="cascade", index=True)
    module_version_id = fields.Many2one("messi.module.version", required=True)
    player_id = fields.Many2one("res.users", string="Player", required=True, index=True)
    subject_kind = fields.Selection(related="module_id.subject_kind", store=True)
    subject_model = fields.Char(index=True)
    subject_res_id = fields.Integer(index=True)
    subject_name = fields.Char(compute="_compute_subject_name", store=True)

    cadence_json = fields.Text(help="Overrides the module cadence for this subject.")
    tz = fields.Char(default=lambda s: s.env.user.tz or "Asia/Jakarta", required=True)
    active_from = fields.Date(default=fields.Date.context_today, required=True)
    active_until = fields.Date()
    paused_until = fields.Date()
    pause_reason = fields.Char()
    no_change_streak = fields.Integer(default=0, readonly=True,
                                      help="Consecutive 'no change' answers; drives cadence decay.")
    cycle_ids = fields.One2many("messi.cycle", "enrolment_id")
    active = fields.Boolean(default=True)
    company_id = fields.Many2one(related="module_id.company_id", store=True)

    _sql_constraints = [
        ("uniq_enrolment", "unique (module_id, player_id, subject_model, subject_res_id)",
         "This player is already enrolled for this subject in this module."),
    ]

    @api.depends("subject_model", "subject_res_id", "player_id")
    def _compute_subject_name(self):
        for enrolment in self:
            if not enrolment.subject_model or not enrolment.subject_res_id:
                enrolment.subject_name = enrolment.player_id.name or _("you")
                continue
            record = self.env[enrolment.subject_model].browse(enrolment.subject_res_id).exists()
            enrolment.subject_name = record.display_name if record else _("(deleted)")

    @api.constrains("subject_model", "subject_res_id", "module_id")
    def _check_subject(self):
        """A 'self' module must carry no subject, and every other kind must carry one."""
        for enrolment in self:
            expected = enrolment.module_id.subject_model()
            if expected is None:
                if enrolment.subject_model or enrolment.subject_res_id:
                    raise ValidationError(_("A 'self' module takes no subject."))
            else:
                if not enrolment.subject_res_id:
                    raise ValidationError(
                        _("Module %s requires a subject.") % enrolment.module_id.key)
                if enrolment.subject_model != expected:
                    raise ValidationError(_(
                        "Module %(m)s follows %(expected)s, not %(given)s.",
                        m=enrolment.module_id.key, expected=expected,
                        given=enrolment.subject_model))

    @api.constrains("cadence_json")
    def _check_cadence(self):
        for enrolment in self:
            if not enrolment.cadence_json:
                continue
            try:
                cadence_lib.validate(json.loads(enrolment.cadence_json))
            except (cadence_lib.CadenceError, ValueError) as exc:
                raise ValidationError(_("Invalid cadence override: %s") % exc) from exc

    def effective_cadence(self):
        self.ensure_one()
        if self.cadence_json:
            return json.loads(self.cadence_json)
        return self.module_version_id.cadence()

    def is_generating(self, on_date):
        """Paused, expired and not-yet-started enrolments produce no cycles. Pausing is
        explained silence and must stay distinguishable from someone ignoring the ask."""
        self.ensure_one()
        if not self.active or self.module_id.state != "active":
            return False
        if self.active_from and on_date < self.active_from:
            return False
        if self.active_until and on_date > self.active_until:
            return False
        if self.paused_until and on_date <= self.paused_until:
            return False
        return True
