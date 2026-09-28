"""MESSI engine core: the rules, with no framework attached.

Deliberately free of web, ORM and Odoo imports so the things most likely to be got wrong
— when a cycle opens, which period it belongs to, when a promise counts as broken — are
readable and testable on their own. Both the standalone app and the Odoo addon call in
here rather than restating them.
"""

from . import cadence, lifecycle  # noqa: F401

__all__ = ["cadence", "lifecycle"]
