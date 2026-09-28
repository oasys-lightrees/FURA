"""MESSI web app. Server-rendered, no build step — the point is that it runs."""

from __future__ import annotations

from datetime import datetime, timezone
from pathlib import Path

from fastapi import FastAPI, Form, Request
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.templating import Jinja2Templates

import engine
from auth import COOKIE_NAME, create_session, destroy_session, user_for_session, verify_password
from db import connect

app = FastAPI(title="MESSI")
templates = Jinja2Templates(directory=str(Path(__file__).parent / "templates"))


def current_user(request: Request, conn):
    return user_for_session(conn, request.cookies.get(COOKIE_NAME))


def login_redirect():
    return RedirectResponse("/login", status_code=303)


@app.get("/health")
def health():
    with connect() as conn:
        conn.execute("SELECT 1")
    return {"status": "ok"}


@app.get("/login", response_class=HTMLResponse)
def login_form(request: Request, error: str | None = None):
    return templates.TemplateResponse(request, "login.html", {"error": error})


@app.post("/login")
def login(request: Request, email: str = Form(...), password: str = Form(...)):
    with connect() as conn:
        row = conn.execute(
            "SELECT id, password_hash FROM users WHERE email = %s", (email.strip().lower(),)
        ).fetchone()
        if not row or not verify_password(password, row["password_hash"]):
            # Same message either way: which half was wrong is not the caller's business.
            return templates.TemplateResponse(
                request, "login.html", {"error": "Email atau password salah."}, status_code=401
            )
        token = create_session(conn, row["id"])
        conn.commit()
    response = RedirectResponse("/", status_code=303)
    response.set_cookie(COOKIE_NAME, token, httponly=True, samesite="lax")
    return response


@app.post("/logout")
def logout(request: Request):
    with connect() as conn:
        destroy_session(conn, request.cookies.get(COOKIE_NAME))
        conn.commit()
    response = RedirectResponse("/login", status_code=303)
    response.delete_cookie(COOKIE_NAME)
    return response


@app.get("/", response_class=HTMLResponse)
def inbox(request: Request):
    with connect() as conn:
        user = current_user(request, conn)
        if not user:
            return login_redirect()

        overdue = conn.execute(
            """SELECT c.id, c.action_text, c.due_at, m.key AS module,
                      COALESCE(s.name, 'kamu') AS subject
               FROM commitments c
               JOIN enrolments e ON e.id = c.enrolment_id
               JOIN modules m ON m.id = e.module_id
               LEFT JOIN subjects s ON s.id = e.subject_id
               WHERE c.owner_id = %s AND c.status = 'open' AND c.due_at < now()
               ORDER BY c.due_at""",
            (user["id"],),
        ).fetchall()

        cycles = conn.execute(
            """SELECT cy.id, cy.period_key, cy.due_at, m.key AS module, m.name AS module_name,
                      COALESCE(s.name, 'kamu') AS subject, cm.action_text AS asking_about
               FROM cycles cy
               JOIN enrolments e ON e.id = cy.enrolment_id
               JOIN modules m ON m.id = e.module_id
               LEFT JOIN subjects s ON s.id = e.subject_id
               LEFT JOIN commitments cm ON cm.id = cy.triggered_by_commitment_id
               WHERE e.player_id = %s AND cy.status = 'pending'
               ORDER BY cy.due_at, m.key""",
            (user["id"],),
        ).fetchall()

    return templates.TemplateResponse(
        request, "inbox.html",
        {"user": user, "overdue": overdue, "cycles": cycles, "now": datetime.now(timezone.utc)},
    )


@app.get("/cycle/{cycle_id}", response_class=HTMLResponse)
def cycle_detail(request: Request, cycle_id: int, error: str | None = None):
    with connect() as conn:
        user = current_user(request, conn)
        if not user:
            return login_redirect()
        cycle = _load_cycle(conn, cycle_id, user["id"])
        if not cycle:
            return HTMLResponse("Not found", status_code=404)
        answers = {
            r["question_key"]: r["value_text"]
            for r in conn.execute(
                "SELECT question_key, value_text FROM answers WHERE cycle_id = %s", (cycle_id,)
            ).fetchall()
        }
    return templates.TemplateResponse(
        request, "cycle.html", {"user": user, "c": cycle, "answers": answers, "error": error}
    )


def _load_cycle(conn, cycle_id, user_id):
    return conn.execute(
        """SELECT cy.*, v.questions, m.key AS module, m.name AS module_name,
                  COALESCE(s.name, 'kamu') AS subject, cm.action_text AS asking_about,
                  cm.due_at AS promised_for
           FROM cycles cy
           JOIN enrolments e ON e.id = cy.enrolment_id
           JOIN modules m ON m.id = e.module_id
           JOIN module_versions v ON v.id = cy.module_version_id
           LEFT JOIN subjects s ON s.id = e.subject_id
           LEFT JOIN commitments cm ON cm.id = cy.triggered_by_commitment_id
           WHERE cy.id = %s AND e.player_id = %s""",
        (cycle_id, user_id),
    ).fetchone()


@app.post("/cycle/{cycle_id}/submit")
async def cycle_submit(request: Request, cycle_id: int):
    form = await request.form()
    with connect() as conn:
        user = current_user(request, conn)
        if not user:
            return login_redirect()
        values = {k[2:]: str(v) for k, v in form.items() if k.startswith("q_")}
        engine.save_answers(conn, cycle_id, values)
        no_change = "no_change" in form
        try:
            engine.submit(conn, cycle_id, user["id"], no_change=no_change)
        except engine.SubmitError as exc:
            conn.rollback()
            return RedirectResponse(f"/cycle/{cycle_id}?error={exc}", status_code=303)
    return RedirectResponse("/", status_code=303)


@app.get("/leader", response_class=HTMLResponse)
def leader(request: Request):
    """Exceptions only. An all-green day should require no reading."""
    with connect() as conn:
        user = current_user(request, conn)
        if not user:
            return login_redirect()
        if user["role"] not in ("leader", "admin"):
            return HTMLResponse("Halaman ini untuk leader.", status_code=403)
        org = user["organization_id"]

        stats = conn.execute(
            """SELECT
                 count(*) FILTER (WHERE status IN ('submitted','late')) AS answered,
                 count(*) FILTER (WHERE status = 'late')    AS late,
                 count(*) FILTER (WHERE status = 'missed')  AS missed,
                 count(*) FILTER (WHERE status = 'pending') AS pending
               FROM cycles WHERE organization_id = %s""",
            (org,),
        ).fetchone()
        promises = conn.execute(
            """SELECT count(*) FILTER (WHERE status = 'kept')   AS kept,
                      count(*) FILTER (WHERE status = 'broken') AS broken,
                      count(*) FILTER (WHERE status = 'open')   AS open
               FROM commitments WHERE organization_id = %s""",
            (org,),
        ).fetchone()
        broken = conn.execute(
            """SELECT c.action_text, c.due_at, u.display_name AS owner,
                      m.key AS module, COALESCE(s.name,'—') AS subject
               FROM commitments c
               JOIN users u ON u.id = c.owner_id
               JOIN enrolments e ON e.id = c.enrolment_id
               JOIN modules m ON m.id = e.module_id
               LEFT JOIN subjects s ON s.id = e.subject_id
               WHERE c.organization_id = %s AND c.status = 'broken'
               ORDER BY c.due_at DESC LIMIT 20""",
            (org,),
        ).fetchall()
        missed = conn.execute(
            """SELECT cy.period_key, cy.due_at, u.display_name AS player,
                      m.key AS module, COALESCE(s.name,'—') AS subject
               FROM cycles cy
               JOIN enrolments e ON e.id = cy.enrolment_id
               JOIN users u ON u.id = e.player_id
               JOIN modules m ON m.id = e.module_id
               LEFT JOIN subjects s ON s.id = e.subject_id
               WHERE cy.organization_id = %s AND cy.status = 'missed'
               ORDER BY cy.due_at DESC LIMIT 20""",
            (org,),
        ).fetchall()

    from messi_core.lifecycle import rate

    return templates.TemplateResponse(
        request, "leader.html",
        {"user": user, "stats": stats, "promises": promises, "broken": broken, "missed": missed,
         "response_rate": rate(stats["answered"], stats["answered"] + stats["missed"]),
         "kept_rate": rate(promises["kept"], promises["kept"] + promises["broken"])},
    )


@app.get("/modules", response_class=HTMLResponse)
def modules(request: Request):
    with connect() as conn:
        user = current_user(request, conn)
        if not user:
            return login_redirect()
        rows = conn.execute(
            """SELECT m.key, m.name, m.description, m.subject_kind, m.status,
                      v.questions, v.cadence,
                      (SELECT count(*) FROM enrolments e WHERE e.module_id = m.id) AS enrolled
               FROM modules m
               JOIN module_versions v ON v.module_id = m.id AND v.version = 1
               WHERE m.organization_id = %s ORDER BY m.key""",
            (user["organization_id"],),
        ).fetchall()
    return templates.TemplateResponse(request, "modules.html", {"user": user, "modules": rows})


@app.post("/tick")
def run_tick():
    """Normally a cron. Exposed so the whole loop can be demonstrated on demand."""
    with connect() as conn:
        return engine.tick(conn)
