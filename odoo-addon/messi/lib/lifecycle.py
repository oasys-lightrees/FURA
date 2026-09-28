"""Cycle and commitment state machines, as pure functions over values.

Kept free of Odoo so the rules are testable and readable on their own; the Odoo models
in ../models call into these rather than reimplementing them.
"""

from __future__ import annotations

from datetime import datetime, timedelta

CYCLE_PENDING = "pending"
CYCLE_SUBMITTED = "submitted"
CYCLE_LATE = "late"
CYCLE_MISSED = "missed"
CYCLE_SKIPPED = "skipped"

TERMINAL_CYCLE_STATES = {CYCLE_SUBMITTED, CYCLE_LATE, CYCLE_MISSED, CYCLE_SKIPPED}

COMMITMENT_OPEN = "open"
COMMITMENT_KEPT = "kept"
COMMITMENT_BROKEN = "broken"
COMMITMENT_CANCELLED = "cancelled"
COMMITMENT_SUPERSEDED = "superseded"

DEFAULT_GRACE = timedelta(hours=1)


class TransitionError(ValueError):
    pass


def submit_status(due_at: datetime, now: datetime) -> str:
    """On time before the deadline, late after it. Both are answers — the distinction is
    what separates discipline from participation in the KPIs (doc 10 section 10.3)."""
    return CYCLE_SUBMITTED if now <= due_at else CYCLE_LATE


def check_can_submit(status: str) -> None:
    if status in TERMINAL_CYCLE_STATES:
        raise TransitionError(f"cycle already {status}")


def should_mark_missed(status: str, due_at: datetime, now: datetime,
                       grace: timedelta = DEFAULT_GRACE) -> bool:
    """A missed cycle is a recorded fact, not an absent row. Idempotent: a cycle already
    marked missed returns False, so the reaper can run as often as it likes."""
    return status == CYCLE_PENDING and now > due_at + grace


def check_can_snooze(status: str, reason: str) -> None:
    """Snoozing is explained silence, and must stay distinguishable from the unexplained
    kind — so it needs a reason."""
    if status in TERMINAL_CYCLE_STATES:
        raise TransitionError(f"cycle already {status}")
    if not (reason or "").strip():
        raise TransitionError("a reason is required to snooze")


def commitment_outcome(due_at: datetime, now: datetime) -> str:
    """A promise met after its date was not met. Recording that honestly is the whole
    point of commitment-kept rate (ADR-0008)."""
    return COMMITMENT_KEPT if now <= due_at else COMMITMENT_BROKEN


def check_can_resolve(status: str) -> None:
    if status != COMMITMENT_OPEN:
        raise TransitionError(f"commitment already {status}")


def should_mark_broken(status: str, due_at: datetime, now: datetime,
                       grace: timedelta = DEFAULT_GRACE) -> bool:
    return status == COMMITMENT_OPEN and now > due_at + grace


def rate(numerator: int, denominator: int):
    """None rather than zero when there is nothing to divide — an empty week is not a
    0% week, and a dashboard that says 0% invites the wrong conversation."""
    if denominator == 0:
        return None
    return round(numerator / denominator, 4)
