"""Cadence: when a cycle opens, when it is due, and which period it belongs to.

See docs/14-followup-engine.md section 14.6. ``period_key`` is the idempotency key for
cycle generation: a unique constraint on (enrolment, period_key) means the cron can fire
twice, or two workers can race, and still produce exactly one cycle.
"""

from __future__ import annotations

import re
from dataclasses import dataclass
from datetime import date, datetime, time, timedelta, timezone
from zoneinfo import ZoneInfo

UTC = timezone.utc

WEEKDAY_NAMES = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"]
WEEKDAYS_ONLY = ["mon", "tue", "wed", "thu", "fri"]


class CadenceError(ValueError):
    """Raised for a cadence definition that cannot be honoured."""


@dataclass(frozen=True)
class Occurrence:
    period_key: str
    opens_at: datetime
    due_at: datetime


_DURATION_RE = re.compile(r"^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$")


def parse_duration(text: str) -> timedelta:
    """Parses the ISO-8601 durations module definitions use: PT9H, P1D, PT30M, P1DT2H."""
    if not isinstance(text, str):
        raise CadenceError(f"bad duration: {text!r}")
    match = _DURATION_RE.match(text)
    if not match or text in ("P", "PT"):
        raise CadenceError(f"bad duration: {text!r}")
    days, hours, minutes, seconds = (int(g) if g else 0 for g in match.groups())
    if not any(g is not None for g in match.groups()):
        raise CadenceError(f"bad duration: {text!r}")
    return timedelta(days=days, hours=hours, minutes=minutes, seconds=seconds)


def parse_at(text: str) -> time:
    for fmt in ("%H:%M", "%H:%M:%S"):
        try:
            return datetime.strptime(text, fmt).time()
        except (ValueError, TypeError):
            continue
    raise CadenceError(f"bad time of day: {text!r}")


def _last_day_of_month(year: int, month: int) -> int:
    first_next = date(year + 1, 1, 1) if month == 12 else date(year, month + 1, 1)
    return (first_next - timedelta(days=1)).day


def _local_to_utc(tz: ZoneInfo, day: date, at: time) -> datetime:
    """Resolves a local wall-clock time to an instant, stepping forward over a DST gap
    instead of failing. Jakarta has no DST, but tenants elsewhere will."""
    for extra_hours in range(4):
        naive = datetime.combine(day, at) + timedelta(hours=extra_hours)
        local = naive.replace(tzinfo=tz)
        # A time inside a DST gap round-trips to a different wall clock.
        if local.astimezone(UTC).astimezone(tz).hour == local.hour:
            return local.astimezone(UTC)
    raise CadenceError(f"no valid local time for {day} {at} in {tz}")


def occurrence_for(cadence: dict, now_utc: datetime, tz_name: str) -> Occurrence | None:
    """The calendar occurrence covering ``now``, or None when this cadence does not
    generate today (wrong weekday, off-cycle day)."""
    if not isinstance(cadence, dict) or "kind" not in cadence:
        raise CadenceError(f"cadence must be an object with a kind: {cadence!r}")

    kind = cadence["kind"]
    tz = ZoneInfo(tz_name)
    local_now = now_utc.astimezone(tz)
    today = local_now.date()

    at_text = cadence.get("at", "09:00")
    due_after_text = cadence.get("due_after", "PT9H")

    if kind == "on_commitment":
        fallback = cadence.get("fallback")
        if not fallback:
            # ADR-0008: a module without a fallback would silently forget quiet subjects.
            raise CadenceError("on_commitment requires a fallback cadence")
        if fallback.get("kind") == "on_commitment":
            raise CadenceError("on_commitment fallback cannot itself be on_commitment")
        return occurrence_for(fallback, now_utc, tz_name)

    if kind == "once":
        return Occurrence("once", now_utc, now_utc + timedelta(days=1))

    if kind == "daily":
        days = cadence.get("days", WEEKDAYS_ONLY)
        if WEEKDAY_NAMES[today.weekday()] not in days:
            return None
        key = today.isoformat()

    elif kind == "weekly":
        wanted = cadence.get("weekday", "mon")
        if WEEKDAY_NAMES[today.weekday()] != wanted:
            return None
        iso_year, iso_week, _ = today.isocalendar()
        key = f"{iso_year}-W{iso_week:02d}"

    elif kind == "every_n_days":
        n = int(cadence.get("n", 0))
        if n <= 0:
            raise CadenceError("every_n_days: n must be > 0")
        anchor = cadence.get("anchor")
        anchor = date.fromisoformat(anchor) if isinstance(anchor, str) else anchor
        if anchor is None:
            raise CadenceError("every_n_days requires an anchor date")
        elapsed = (today - anchor).days
        if elapsed < 0 or elapsed % n != 0:
            return None
        key = today.isoformat()

    elif kind == "monthly":
        wanted_day = int(cadence.get("day", 1))
        # Clamp so day 31 still fires in February rather than never.
        effective = min(wanted_day, _last_day_of_month(today.year, today.month))
        if today.day != effective:
            return None
        key = f"{today.year}-{today.month:02d}"

    else:
        raise CadenceError(f"unknown cadence kind: {kind!r}")

    opens_at = _local_to_utc(tz, today, parse_at(at_text))
    return Occurrence(key, opens_at, opens_at + parse_duration(due_after_text))


def commitment_period_key(commitment_id) -> str:
    """Namespaced so a commitment-triggered cycle can never collide with a calendar one."""
    return f"c:{commitment_id}"


def validate(cadence: dict) -> None:
    """Publish-time check. The one rule that blocks publishing (ADR-0008) is a missing
    fallback on a commitment-driven module."""
    occurrence_for(cadence, datetime(2026, 1, 5, 3, 0, tzinfo=UTC), "Asia/Jakarta")
