"""Unit tests for the pure engine logic. These run without Odoo: `pytest odoo-addon`."""

import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "messi"))

from lib import cadence, lifecycle  # noqa: E402

UTC = timezone.utc
JKT = "Asia/Jakarta"
WEEKDAYS = cadence.WEEKDAYS_ONLY


def t(text):
    return datetime.fromisoformat(text).astimezone(UTC)


# ------------------------------------------------------------------ durations

def test_parses_iso_durations():
    assert cadence.parse_duration("PT9H") == timedelta(hours=9)
    assert cadence.parse_duration("P1D") == timedelta(days=1)
    assert cadence.parse_duration("PT30M") == timedelta(minutes=30)
    assert cadence.parse_duration("P1DT2H") == timedelta(days=1, hours=2)


@pytest.mark.parametrize("bad", ["9H", "PT9X", "PT", "P", "", None, "1 day"])
def test_rejects_bad_durations(bad):
    with pytest.raises(cadence.CadenceError):
        cadence.parse_duration(bad)


# --------------------------------------------------------------------- daily

def test_daily_skips_the_weekend():
    c = {"kind": "daily", "days": WEEKDAYS, "at": "09:00", "due_after": "PT9H"}
    assert cadence.occurrence_for(c, t("2026-09-26T03:00:00+00:00"), JKT) is None  # Saturday
    occ = cadence.occurrence_for(c, t("2026-09-28T03:00:00+00:00"), JKT)  # Monday
    assert occ.period_key == "2026-09-28"


def test_daily_opens_at_local_nine_not_utc_nine():
    c = {"kind": "daily", "days": WEEKDAYS, "at": "09:00", "due_after": "PT9H"}
    occ = cadence.occurrence_for(c, t("2026-09-28T03:00:00+00:00"), JKT)
    assert occ.opens_at == t("2026-09-28T02:00:00+00:00")  # Jakarta is UTC+7
    assert occ.due_at == t("2026-09-28T11:00:00+00:00")


def test_period_key_uses_the_players_local_day():
    """23:30Z on the 28th is already 06:30 on the 29th in Jakarta. Keying by UTC would
    give a player two cycles on one working day, or none."""
    c = {"kind": "daily", "days": WEEKDAYS, "at": "09:00", "due_after": "PT9H"}
    occ = cadence.occurrence_for(c, t("2026-09-28T23:30:00+00:00"), JKT)
    assert occ.period_key == "2026-09-29"


def test_generation_is_idempotent_within_a_period():
    c = {"kind": "daily", "days": WEEKDAYS, "at": "09:00", "due_after": "PT9H"}
    morning = cadence.occurrence_for(c, t("2026-09-28T02:30:00+00:00"), JKT)
    evening = cadence.occurrence_for(c, t("2026-09-28T09:30:00+00:00"), JKT)
    assert morning == evening


# -------------------------------------------------------- weekly / n-days / monthly

def test_weekly_fires_once_and_keys_by_iso_week():
    c = {"kind": "weekly", "weekday": "mon", "at": "09:00", "due_after": "P1D"}
    assert cadence.occurrence_for(c, t("2026-09-29T03:00:00+00:00"), JKT) is None
    assert cadence.occurrence_for(c, t("2026-09-28T03:00:00+00:00"), JKT).period_key == "2026-W40"


def test_every_n_days_is_anchor_aligned():
    c = {"kind": "every_n_days", "n": 3, "anchor": "2026-09-28"}
    assert cadence.occurrence_for(c, t("2026-09-28T03:00:00+00:00"), JKT) is not None
    assert cadence.occurrence_for(c, t("2026-09-29T03:00:00+00:00"), JKT) is None
    assert cadence.occurrence_for(c, t("2026-10-01T03:00:00+00:00"), JKT) is not None


def test_monthly_clamps_to_the_last_day_of_a_short_month():
    c = {"kind": "monthly", "day": 31}
    assert cadence.occurrence_for(c, t("2026-02-28T03:00:00+00:00"), JKT).period_key == "2026-02"
    assert cadence.occurrence_for(c, t("2026-02-27T03:00:00+00:00"), JKT) is None


# -------------------------------------------------------------- on_commitment

def test_on_commitment_falls_back_so_a_quiet_subject_is_not_forgotten():
    c = {"kind": "on_commitment", "fallback": {"kind": "weekly", "weekday": "mon"}}
    assert cadence.occurrence_for(c, t("2026-09-28T03:00:00+00:00"), JKT).period_key == "2026-W40"
    assert cadence.occurrence_for(c, t("2026-09-30T03:00:00+00:00"), JKT) is None


def test_on_commitment_without_a_fallback_is_refused():
    with pytest.raises(cadence.CadenceError):
        cadence.validate({"kind": "on_commitment"})


def test_on_commitment_fallback_cannot_recurse():
    with pytest.raises(cadence.CadenceError):
        cadence.validate({"kind": "on_commitment", "fallback": {"kind": "on_commitment"}})


def test_commitment_keys_cannot_collide_with_calendar_keys():
    key = cadence.commitment_period_key(42)
    assert key == "c:42"
    assert not key[0].isdigit()


def test_unknown_cadence_kind_is_refused():
    with pytest.raises(cadence.CadenceError):
        cadence.occurrence_for({"kind": "fortnightly"}, t("2026-09-28T03:00:00+00:00"), JKT)


# ------------------------------------------------------------------ lifecycle

def test_submitting_before_due_is_on_time_after_is_late():
    due = t("2026-09-28T11:00:00+00:00")
    assert lifecycle.submit_status(due, t("2026-09-28T08:00:00+00:00")) == "submitted"
    assert lifecycle.submit_status(due, t("2026-09-28T12:00:00+00:00")) == "late"


def test_a_cycle_cannot_be_answered_twice():
    with pytest.raises(lifecycle.TransitionError):
        lifecycle.check_can_submit("submitted")


def test_reaper_waits_for_the_grace_window_and_is_idempotent():
    due = t("2026-09-28T11:00:00+00:00")
    assert not lifecycle.should_mark_missed("pending", due, t("2026-09-28T11:30:00+00:00"))
    assert lifecycle.should_mark_missed("pending", due, t("2026-09-28T12:30:00+00:00"))
    assert not lifecycle.should_mark_missed("missed", due, t("2026-09-30T00:00:00+00:00"))


def test_reaper_never_touches_an_answered_cycle():
    due = t("2026-09-28T11:00:00+00:00")
    assert not lifecycle.should_mark_missed("submitted", due, t("2026-09-30T00:00:00+00:00"))


def test_snooze_requires_a_reason():
    with pytest.raises(lifecycle.TransitionError):
        lifecycle.check_can_snooze("pending", "   ")
    lifecycle.check_can_snooze("pending", "player on leave")


def test_a_promise_met_late_was_not_met():
    due = t("2026-10-02T10:00:00+00:00")
    assert lifecycle.commitment_outcome(due, t("2026-10-02T09:00:00+00:00")) == "kept"
    assert lifecycle.commitment_outcome(due, t("2026-10-03T09:00:00+00:00")) == "broken"


def test_a_resolved_commitment_cannot_be_resolved_again():
    with pytest.raises(lifecycle.TransitionError):
        lifecycle.check_can_resolve("kept")


def test_rate_is_none_not_zero_when_there_is_nothing_to_divide():
    assert lifecycle.rate(0, 0) is None
    assert lifecycle.rate(3, 4) == 0.75
