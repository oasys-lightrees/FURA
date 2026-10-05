# Running MESSI

A standalone Python app: FastAPI, PostgreSQL, server-rendered HTML. No build step, no
Docker, no Odoo.

```bash
./run.sh          # postgres + schema + seed + server on http://127.0.0.1:8000
pytest -q         # 29 engine tests, no database needed
```

Sign in as `nicho@lightrees.com` (player) or `chief@lightrees.com` (leader), password
`messi`.

## What it does today

| | |
|---|---|
| Modules, versions, questions | Seeded from the real Telegram reports of 25/09/2026 |
| Enrolments, subjects | 7 projects, 3 leads, one squad |
| Cycle generation | Hourly tick, idempotent per period |
| **Commitment-driven cadence** | The next ask lands on the date the player promised |
| Answering, "no change", validation | Working |
| Reaper | Records missed cycles and broken promises |
| Leader dashboard | Exceptions only: what was missed, what was promised and not done |
| Login, sessions, role check | Working |

## The behaviour worth seeing

```
Mon 28 Sep  Nicho answers PRISTA/Onboarding App, promises "Final project report" by Fri
Tue 29 Sep  silent — nothing was promised today
Wed 30 Sep  silent
Thu 01 Oct  silent
Fri 02 Oct  ASKS: "Final project report" — gimana?
Sat 03 Oct  unanswered -> promise recorded BROKEN, leader dashboard shows it
```

Nobody had to ask. That is the entire product.

Reproduce it with the time-travel demo:

```bash
python3 - <<'PY'
from datetime import datetime, timezone
from db import connect
import engine
with connect() as conn:
    engine.tick(conn, now=datetime(2026, 10, 2, 3, 0, tzinfo=timezone.utc))
PY
```

## Layout

```
web/               THE PAGE PEOPLE USE — messi.html, plus the flow spec and the demo
web/tests/         122 browser checks (player, manager, edge cases, keyboard)
php/               the same page, deployable: PHP + MySQL, cPanel, Telegram, hourly cron
php/tests/         235 checks — rules, a real MySQL, a simulated week, the running app
core/messi_core/   pure rules: cadence, period keys, state machines. No web, no ORM.
app/               FastAPI app, schema.sql, templates, seed, CLI
tests/             pytest over core/ — runs without a database
odoo-addon/        the same engine as an Odoo module, written but never run (see its README)
docs/              the design this implements
```

`web/` and `app/` have diverged: `web/` carries the current MESSI question set and the
stepped flow, `app/` carries the server pieces. See [web/README.md](web/README.md).

[`php/`](php/README.md) is the one meant to go live. It serves `web/messi.html` unchanged
— a byte-for-byte copy, checked by `php/tests/check_sync.php` — so the 122 browser checks
keep describing what ships, and replaces only what was underneath it. Unlike `app/`, it is
built to face a network: every write is re-checked server-side, the day and the deadline
come from the server's clock, and the pages that are not pages are blocked by `.htaccess`.

`core/` is deliberately framework-free: the things most likely to be got wrong — which
period a cycle belongs to in the player's own timezone, when a promise counts as broken —
are readable and testable on their own, and both the app and the Odoo addon call into it
rather than restating the rules.

## Not done yet, in the order it matters

1. **Security in `app/`.** Sessions and scrypt passwords work, but there is no MFA, no
   refresh-token rotation, no CSRF token, no rate limiting, and no row-level security.
   `organization_id` is on every table but queries do not yet filter by it — the app
   assumes one org. **Do not expose `app/` to a network**; `php/` is the build meant for
   that, and its remaining gaps are login rate limiting and MFA.
2. **Migrations.** One `schema.sql` applied with `CREATE TABLE IF NOT EXISTS`. Needs
   ordered migration files before any data matters.
3. **No scheduler.** `run.sh` ticks once at startup; `POST /tick` runs it on demand. Needs
   a real loop or cron.
4. **The authoring console.** Modules are seeded from Python, not authored in the UI. This
   is the actual product (doc 16) and the largest remaining piece.
5. **Question types.** `long_text`, `date`, `number`, `select` render; `checklist`,
   `thread_list`, `stage` and conditional questions do not.
6. **Outcomes.** An answer cannot yet become an approval, a signature, a meeting or a
   project. Only commitments are captured.
7. **No analysis.** Cause aggregation and the bottleneck views in doc 17 are not built.
