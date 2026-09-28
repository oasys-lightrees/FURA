"""Follow-up modules: the definition a leader authors once.

A module is not a form. It carries a subject kind, a versioned question set, a cadence,
and the outcomes an answer may produce. MESSI, PRISTA, LESTARI and NADI are all rows in
this table — the product is the machine, not any one of them (ADR-0007).
"""

import json
import logging

from odoo import _, api, fields, models
from odoo.exceptions import ValidationError

from ..lib import cadence as cadence_lib

_logger = logging.getLogger(__name__)

SUBJECT_KINDS = [
    ("self", "The player themself"),
    ("partner", "Contact / lead"),
    ("project", "Project"),
    ("task", "Task"),
    ("custom", "Other record"),
]

SUBJECT_MODEL_BY_KIND = {
    "self": None,
    "partner": "res.partner",
    "project": "project.project",
    "task": "project.task",
}


class MessiModule(models.Model):
    _name = "messi.module"
    _description = "Follow-up Module"
    _inherit = ["mail.thread"]
    _order = "key"

    key = fields.Char(required=True, tracking=True, help="Short code used in URLs and events, e.g. MESSI.")
    name = fields.Char(required=True, tracking=True)
    description = fields.Text()
    subject_kind = fields.Selection(SUBJECT_KINDS, required=True, default="self", tracking=True)
    state = fields.Selection(
        [("draft", "Draft"), ("active", "Active"), ("paused", "Paused"), ("archived", "Archived")],
        default="draft", required=True, tracking=True,
    )
    version_ids = fields.One2many("messi.module.version", "module_id", string="Versions")
    current_version_id = fields.Many2one("messi.module.version", compute="_compute_current_version", store=True)
    enrolment_ids = fields.One2many("messi.enrolment", "module_id", string="Enrolments")
    enrolment_count = fields.Integer(compute="_compute_counts")
    active = fields.Boolean(default=True)

    _sql_constraints = [
        ("key_uniq", "unique (key, company_id)", "A module key must be unique per company."),
    ]

    company_id = fields.Many2one("res.company", required=True, default=lambda s: s.env.company)

    @api.depends("version_ids.version", "version_ids.published_at")
    def _compute_current_version(self):
        for module in self:
            published = module.version_ids.filtered("published_at").sorted("version", reverse=True)
            module.current_version_id = published[:1]

    @api.depends("enrolment_ids")
    def _compute_counts(self):
        for module in self:
            module.enrolment_count = len(module.enrolment_ids)

    def subject_model(self):
        self.ensure_one()
        return SUBJECT_MODEL_BY_KIND.get(self.subject_kind)

    def action_activate(self):
        for module in self:
            if not module.current_version_id:
                raise ValidationError(_("Publish a version before activating %s.") % module.key)
            module.state = "active"


class MessiModuleVersion(models.Model):
    _name = "messi.module.version"
    _description = "Follow-up Module Version"
    _order = "module_id, version desc"

    module_id = fields.Many2one("messi.module", required=True, ondelete="cascade", index=True)
    version = fields.Integer(required=True, default=1)
    questions_json = fields.Text(required=True, default="[]",
                                 help="Ordered question definitions. See docs/16-question-design.md.")
    cadence_json = fields.Text(required=True, default='{"kind": "daily"}')
    commitment_mapping_json = fields.Text(
        help='Maps answers to a commitment, e.g. {"action": "next_action", "due": "next_due", "owner": "owner"}.')
    outcomes = fields.Char(help="Comma-separated: approval,signature,schedule,task,project")
    published_at = fields.Datetime(readonly=True)
    company_id = fields.Many2one(related="module_id.company_id", store=True)

    _sql_constraints = [
        ("version_uniq", "unique (module_id, version)", "Version numbers must be unique per module."),
    ]

    # Versions are immutable once used, so answers stay comparable over time and the
    # analysis in docs/17 can group by version (doc 14 section 14.4).
    def write(self, vals):
        locked = {"questions_json", "cadence_json", "commitment_mapping_json"}
        if locked & set(vals):
            for version in self:
                if version.published_at and self.env["messi.cycle"].search_count(
                        [("module_version_id", "=", version.id)]):
                    raise ValidationError(_(
                        "Version %(v)s of %(m)s has been answered and cannot be edited. "
                        "Create a new version instead.",
                        v=version.version, m=version.module_id.key))
        return super().write(vals)

    def questions(self):
        self.ensure_one()
        return json.loads(self.questions_json or "[]")

    def cadence(self):
        self.ensure_one()
        return json.loads(self.cadence_json or "{}")

    def commitment_mapping(self):
        self.ensure_one()
        return json.loads(self.commitment_mapping_json or "null")

    @api.constrains("cadence_json")
    def _check_cadence(self):
        """The one rule that blocks publishing: a commitment-driven module with no
        fallback would silently forget quiet subjects (ADR-0008)."""
        for version in self:
            try:
                cadence_lib.validate(version.cadence())
            except (cadence_lib.CadenceError, ValueError) as exc:
                raise ValidationError(_("Invalid cadence: %s") % exc) from exc

    @api.constrains("questions_json", "commitment_mapping_json")
    def _check_questions(self):
        for version in self:
            try:
                questions = version.questions()
            except ValueError as exc:
                raise ValidationError(_("Questions must be valid JSON: %s") % exc) from exc
            if not isinstance(questions, list):
                raise ValidationError(_("Questions must be a list."))
            keys = [q.get("key") for q in questions]
            if len(keys) != len(set(keys)):
                raise ValidationError(_("Question keys must be unique within a version."))
            mapping = version.commitment_mapping()
            if mapping:
                for role in ("action", "due"):
                    if mapping.get(role) and mapping[role] not in keys:
                        raise ValidationError(_(
                            "commitment_mapping.%(role)s points at '%(key)s', which is not a question.",
                            role=role, key=mapping[role]))

    def action_publish(self):
        for version in self:
            version.published_at = fields.Datetime.now()
