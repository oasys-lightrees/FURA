# MESSI — web

The MESSI module as a page people actually use. Mobile-first, no build step, no install:
the Telegram bot sends a link and this is what opens.

| File | What it is | Live |
|---|---|---|
| `messi.html` | **The app.** Real clock, real identities, several players, no demo controls | [open](https://claude.ai/artifact/BZsrJRqs4LTknACYkBoyxi) |
| `flow.html` | Flow and layout spec — seven phone screens from the 09:00 trigger to the manager's page | [open](https://claude.ai/artifact/D4jfKBdR2Hg8GX4ddZWDYv) |
| `demo.html` | Earlier demo with a simulated clock, for seeing multi-day behaviour in seconds | [open](https://claude.ai/artifact/YLGw47dDLq4AWuuAthKC8r) |

The pages are published as claude.ai artifacts, which supply the shared database and the
viewer's identity through `window.claude`. They are private; sharing is done from each
page's own Share menu.

## Running the tests

```bash
pip install playwright && playwright install chromium
python3 web/tests/run_all.py
```

89 checks across four suites. They drive a real browser against `messi.html` with
`window.claude` faked (`tests/stub.js`): an in-memory store held in localStorage plus a
switchable identity, so a submission made as one person can be read as another.

| Suite | Covers |
|---|---|
| `test_player.py` (38) | Login, the 2-vs-3 step flow, every rejection, live totals, draft survival across a reload, promise capture, the generated report, and correcting a report already sent |
| `test_manager.py` (24) | Owner-only tabs, the counts, escalations by name, overdue promises, repeat absentees — and that a manager never sees everyone's full report, while a player sees nobody else's data |
| `test_edge.py` (11) | Weekend, before opening, after the deadline, and one person's submission reaching another's screen |
| `test_keyboard.py` (16) | Grid navigation: Enter and arrows move between cells and never change a number; the wheel cannot either |

Set `MESSI_CHROMIUM=/path/to/chromium` if Playwright's own browser is not installed.

## Two things the page cannot do

Both belong to a server, not a browser:

1. **The 09:00 Telegram trigger and the afternoon reminder.** Without a cron, a day that
   was never reported is *derived* on read instead of written. The result is the same;
   the reminder is what is missing.
2. **Posting the report back to the Telegram group.** There is a Copy button and no Send
   button, because a button that does nothing is worse than no button.

The first is built in [`php/`](../php/README.md), which serves this exact page with a
PHP + MySQL back end and an hourly job. This page still runs on its own without it.

## Relationship to `app/`

`app/` is the FastAPI + PostgreSQL implementation from earlier in the project. It shares
the engine rules in `core/messi_core/` but its MESSI question set is the older one, and
its UI is a single long form rather than the stepped flow here.

**This page is the version that has been designed, tested and used.** The server pieces
it needed — a database, accounts, a scheduler, the bot — were built in
[`php/`](../php/README.md) instead, because that is what the hosting the squad already
pays for can run. `app/` remains the richer engine, and is where a second module will
start.
