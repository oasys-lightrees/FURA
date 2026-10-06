# MESSI — web

The app people actually use. Mobile-first, no build step, no install: a link is posted to
the team's chat space each morning and this is what opens.

It opens on the FURA module catalogue rather than straight into a form — each live module's
card carries its state for today, so the catalogue answers "what is waiting for me?" before
anyone taps anything. MESSI is the first module; on a first visit it explains itself, and
that explainer is built from the settings in force, so it can never describe a threshold or
an opening hour the app no longer uses.

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

235 checks across five suites. They drive a real browser against `messi.html` with
`window.claude` faked (`tests/stub.js`): an in-memory store held in localStorage plus a
switchable identity, so a submission made as one person can be read as another.

| Suite | Covers |
|---|---|
| `test_player.py` (48) | Login, the 2-vs-3 step flow, every rejection, live totals, draft survival across a reload, promise capture, the generated report, and correcting a report already sent |
| `test_manager.py` (59) | The recap screen: every person with their figures, any past workday, a drill-down into one person's full report; escalations by name, overdue promises, repeat absentees; and that a player sees nobody else's data |
| `test_edge.py` (46) | Weekend, before opening, after the deadline, one person's submission reaching another's screen, and the plan list: adding and dropping rows, the five-row cap, one promise per row, and corrections that keep each promise on its own date |
| `test_keyboard.py` (16) | Grid navigation: Enter and arrows move between cells and never change a number; the wheel cannot either |
| `test_catalog.py` (66) | The module catalogue and what each card says today, the MESSI explainer on a first visit, the sticky header measured at three widths, and a settings document changing every question, threshold, hour and channel the page asks about |

Set `MESSI_CHROMIUM=/path/to/chromium` if Playwright's own browser is not installed.

## Two things the page cannot do

Both belong to a server, not a browser:

1. **The 09:00 post to the squad space and the afternoon reminder.** Without a cron, a day
   that was never reported is *derived* on read instead of written. The result is the same;
   the reminder is what is missing.
2. **Sending anything to Google Chat.** There is a Copy button and no Send button, because
   a button that does nothing is worse than no button. In the deployed build the leader
   reads the recap on the site itself, so nothing is pasted into a chat any more.

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
