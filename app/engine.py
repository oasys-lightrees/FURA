"""The follow-up engine: generate cycles, ask about promises coming due, record what
did not happen, and capture the next promise from an answer.

All rules come from messi_core; this module is the plumbing between them and Postgres.
"""

from __future__ import annotations

import json
from datetime import date, datetime, timedelta, timezone

from messi_core import cadence as cadence_lib
from messi_core import lifecycle

from db import record_event

UTC = timezone.utc
COMMITMENT_LOOKAHEAD = timedelta(hours=12)


def _now() -> datetime:
    return datetime.now(UTC)


# ------------------------------------------------------------------ generation

def generate_cycles(conn, now: datetime | None = None) -> int:
    """Calendar generation. Idempotent through UNIQUE (enrolment_id, period_key), so
    running it twice, or from two workers at once, still yields one cycle."""
    now = now or _now()
    created = 0
    rows = conn.execute(
        """SELECT e.id, e.tz, e.cadence AS override, e.module_version_id, e.paused_until,
                  e.organization_id, v.cadence AS module_cadence
           FROM enrolments e
           JOIN modules m ON m.id = e.module_id
           JOIN module_versions v ON v.id = e.module_version_id
           WHERE e.archived_at IS NULL AND m.status = 'active'"""
    ).fetchall()

    for row in rows:
        if row["paused_until"] and row["paused_until"] >= date.today():
            continue  # explained silence, not a missed cycle
        spec = row["override"] or row["module_cadence"]
        # The fallback of a commitment-driven module is a safety net for subjects with
        # nothing promised. Firing it while a promise is already open is the daily
        # drumbeat the design exists to avoid (doc 14 section 14.7).
        if spec.get("kind") == "on_commitment" and _has_open_commitment(conn, row["id"]):
            continue
        try:
            occurrence = cadence_lib.occurrence_for(spec, now, row["tz"])
        except cadence_lib.CadenceError:
            continue
        if occurrence and _upsert_cycle(conn, row, occurrence):
            created += 1
    conn.commit()
    return created


def _has_open_commitment(conn, enrolment_id) -> bool:
    return conn.execute(
        "SELECT 1 FROM commitments WHERE enrolment_id = %s AND status = 'open' LIMIT 1",
        (enrolment_id,),
    ).fetchone() is not None


def spawn_commitment_cycles(conn, now: datetime | None = None) -> int:
    """ADR-0008: the next ask lands on the day the player said something would happen,
    not on a blind daily drumbeat."""
    now = now or _now()
    created = 0
    rows = conn.execute(
        """SELECT c.id AS commitment_id, c.due_at, e.id, e.tz, e.module_version_id,
                  e.organization_id, e.paused_until
           FROM commitments c
           JOIN enrolments e ON e.id = c.enrolment_id
           WHERE c.status = 'open' AND c.spawned_cycle_id IS NULL
             AND c.due_at <= %s AND e.archived_at IS NULL""",
        (now + COMMITMENT_LOOKAHEAD,),
    ).fetchall()

    for row in rows:
        if row["paused_until"] and row["paused_until"] >= date.today():
            continue
        occurrence = cadence_lib.Occurrence(
            period_key=cadence_lib.commitment_period_key(row["commitment_id"]),
            opens_at=row["due_at"],
            due_at=row["due_at"] + timedelta(hours=9),
        )
        cycle_id = _upsert_cycle(conn, row, occurrence, commitment_id=row["commitment_id"])
        if cycle_id:
            conn.execute(
                "UPDATE commitments SET spawned_cycle_id = %s WHERE id = %s",
                (cycle_id, row["commitment_id"]),
            )
            created += 1
    conn.commit()
    return created


def _upsert_cycle(conn, enrolment, occurrence, commitment_id=None):
    """Returns the new cycle id, or None when this period already has one."""
    row = conn.execute(
        """INSERT INTO cycles (organization_id, enrolment_id, module_version_id,
                               period_key, opens_at, due_at, triggered_by_commitment_id)
           VALUES (%s,%s,%s,%s,%s,%s,%s)
           ON CONFLICT (enrolment_id, period_key) DO NOTHING
           RETURNING id""",
        (enrolment["organization_id"], enrolment["id"], enrolment["module_version_id"],
         occurrence.period_key, occurrence.opens_at, occurrence.due_at, commitment_id),
    ).fetchone()
    if row:
        record_event(conn, enrolment["organization_id"], "cycle.generated", "cycle", row["id"])
        return row["id"]
    return None


def reap(conn, now: datetime | None = None) -> dict:
    """Records what did not happen. A missed cycle is a fact on the record, not an
    absent row — that is what lets a leader stop asking "did you do it?"."""
    now = now or _now()
    cutoff = now - lifecycle.DEFAULT_GRACE

    missed = conn.execute(
        """UPDATE cycles SET status = 'missed'
           WHERE status = 'pending' AND due_at < %s RETURNING id, organization_id""",
        (cutoff,),
    ).fetchall()
    for row in missed:
        record_event(conn, row["organization_id"], "cycle.missed", "cycle", row["id"])

    broken = conn.execute(
        """UPDATE commitments SET status = 'broken', resolved_at = %s
           WHERE status = 'open' AND due_at < %s RETURNING id, organization_id""",
        (now, cutoff),
    ).fetchall()
    for row in broken:
        record_event(conn, row["organization_id"], "commitment.broken", "commitment", row["id"])

    conn.commit()
    return {"missed": len(missed), "broken": len(broken)}


def tick(conn, now: datetime | None = None) -> dict:
    """One pass of everything the scheduler does. Safe to run as often as you like."""
    return {
        "generated": generate_cycles(conn, now),
        "spawned": spawn_commitment_cycles(conn, now),
        **reap(conn, now),
    }


# ------------------------------------------------------------------- answering

def save_answers(conn, cycle_id: int, values: dict[str, str]) -> None:
    for key, value in values.items():
        conn.execute(
            """INSERT INTO answers (cycle_id, question_key, value_text)
               VALUES (%s,%s,%s)
               ON CONFLICT (cycle_id, question_key)
               DO UPDATE SET value_text = EXCLUDED.value_text""",
            (cycle_id, key, (value or "").strip() or None),
        )
    conn.commit()


class SubmitError(Exception):
    pass


def submit(conn, cycle_id: int, user_id: int, no_change: bool = False,
           now: datetime | None = None) -> dict:
    """Records the answer, resolves the promise this cycle was chasing, and captures the
    next one. This is where the engine stops being a form."""
    now = now or _now()
    cycle = conn.execute(
        """SELECT c.*, v.questions, v.commitment_mapping, e.player_id, e.id AS enrolment_id
           FROM cycles c
           JOIN module_versions v ON v.id = c.module_version_id
           JOIN enrolments e ON e.id = c.enrolment_id
           WHERE c.id = %s""",
        (cycle_id,),
    ).fetchone()
    if not cycle:
        raise SubmitError("cycle not found")
    if cycle["player_id"] != user_id:
        raise SubmitError("this follow-up belongs to someone else")
    try:
        lifecycle.check_can_submit(cycle["status"])
    except lifecycle.TransitionError as exc:
        raise SubmitError(str(exc)) from exc

    answers = {
        r["question_key"]: (r["value_text"] or "")
        for r in conn.execute(
            "SELECT question_key, value_text FROM answers WHERE cycle_id = %s", (cycle_id,)
        ).fetchall()
    }

    if not no_change:
        missing = [
            q.get("label") or q["key"]
            for q in cycle["questions"]
            if q.get("required") and not answers.get(q["key"], "").strip()
        ]
        if missing:
            raise SubmitError("Belum dijawab: " + ", ".join(missing))

    status = lifecycle.submit_status(cycle["due_at"], now)
    conn.execute(
        """UPDATE cycles SET status = %s, submitted_at = %s, no_change = %s
           WHERE id = %s AND status = 'pending'""",
        (status, now, no_change, cycle_id),
    )
    if no_change:
        conn.execute(
            "UPDATE enrolments SET no_change_streak = no_change_streak + 1 WHERE id = %s",
            (cycle["enrolment_id"],),
        )
    else:
        conn.execute(
            "UPDATE enrolments SET no_change_streak = 0 WHERE id = %s",
            (cycle["enrolment_id"],),
        )

    resolved = _resolve_triggering_commitment(conn, cycle, now)
    created = _capture_commitment(conn, cycle, answers, user_id, now)

    record_event(conn, cycle["organization_id"], f"cycle.{status}", "cycle", cycle_id, user_id)
    conn.commit()
    return {"status": status, "resolved": resolved, "commitment": created}


def _resolve_triggering_commitment(conn, cycle, now):
    if not cycle["triggered_by_commitment_id"]:
        return None
    row = conn.execute(
        "SELECT id, due_at, status FROM commitments WHERE id = %s",
        (cycle["triggered_by_commitment_id"],),
    ).fetchone()
    if not row or row["status"] != "open":
        return None
    outcome = lifecycle.commitment_outcome(row["due_at"], now)
    conn.execute(
        """UPDATE commitments SET status = %s, resolved_at = %s, resolved_cycle_id = %s
           WHERE id = %s""",
        (outcome, now, cycle["id"], row["id"]),
    )
    record_event(conn, cycle["organization_id"], f"commitment.{outcome}",
                 "commitment", row["id"])
    return outcome


def _capture_commitment(conn, cycle, answers, user_id, now):
    """Turns 'next action' + 'when' into a promise the system will come back about."""
    mapping = cycle["commitment_mapping"]
    if not mapping:
        return None
    action = (answers.get(mapping.get("action", "next_action")) or "").strip()
    due_raw = (answers.get(mapping.get("due", "next_due")) or "").strip()
    if not action or not due_raw:
        return None
    due_at = parse_due(due_raw)
    if not due_at:
        return None
    row = conn.execute(
        """INSERT INTO commitments (organization_id, cycle_id, enrolment_id,
                                    action_text, due_at, owner_id)
           VALUES (%s,%s,%s,%s,%s,%s) RETURNING id, action_text, due_at""",
        (cycle["organization_id"], cycle["id"], cycle["enrolment_id"], action, due_at, user_id),
    ).fetchone()
    record_event(conn, cycle["organization_id"], "commitment.created",
                 "commitment", row["id"], user_id)
    return dict(row)


def parse_due(raw: str):
    """Accepts 2026-10-02, 02/10/2026, or a full timestamp. A bare date means the end of
    that working day rather than midnight, which is almost never what anyone means."""
    from zoneinfo import ZoneInfo

    raw = raw.strip()
    for fmt in ("%Y-%m-%dT%H:%M", "%Y-%m-%d %H:%M", "%Y-%m-%d", "%d/%m/%Y"):
        try:
            parsed = datetime.strptime(raw, fmt)
        except ValueError:
            continue
        if fmt in ("%Y-%m-%d", "%d/%m/%Y"):
            parsed = parsed.replace(hour=17)
        return parsed.replace(tzinfo=ZoneInfo("Asia/Jakarta")).astimezone(UTC)
    return None
