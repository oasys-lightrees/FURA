"""Browser-test harness for the MESSI web app.

The page runs as a claude.ai artifact, where `window.claude` supplies the shared
database and the viewer's identity. Here both are faked (see stub.js) with an
in-memory store kept in localStorage, so the player and the manager can be driven
against the SAME data — which is what most of these tests are actually about.

Run one suite:   python3 web/tests/test_player.py
Run all:         python3 web/tests/run_all.py
Needs: pip install playwright   (Chromium must already be available)
"""

from __future__ import annotations

import json
import os
import sys
import tempfile
from pathlib import Path

from playwright.sync_api import sync_playwright  # noqa: F401  (re-exported)

HERE = Path(__file__).resolve().parent
WEB = HERE.parent
APP = WEB / "messi.html"
STUB = (HERE / "stub.js").read_text()
SHOTS = Path(tempfile.gettempdir()) / "messi-shots"
SHOTS.mkdir(exist_ok=True)

# The artifact host wraps the page in this skeleton at publish time; reproduce it so
# the local render matches what a viewer sees.
_SKELETON = (
    "<!doctype html><html><head><meta charset=utf8>"
    '<meta name=viewport content="width=device-width,initial-scale=1,viewport-fit=cover">'
    "<style>:root{color-scheme:light}body{margin:0;font:14px system-ui;background:#fafaf9}"
    "img{max-width:100%}[hidden]{display:none!important}</style></head><body>{body}</body></html>"
)

# Freezes the clock, so weekend / before-opening / after-deadline behaviour is testable.
_CLOCK = """(function(){ const F=new Date(%s).getTime(), R=Date;
  function D(...a){ return a.length ? new R(...a) : new R(F); }
  D.now=()=>F; D.parse=R.parse; D.UTC=R.UTC; D.prototype=R.prototype; window.Date=D; })();"""


def preview_url() -> str:
    out = SHOTS / "preview.html"
    out.write_text(_SKELETON.replace("{body}", APP.read_text()))
    return "file://" + str(out)


def launch(pw):
    """Playwright's own Chromium when it is installed, otherwise one supplied by the
    environment (MESSI_CHROMIUM, or the image's pre-installed build)."""
    for path in (os.environ.get("MESSI_CHROMIUM"), "/opt/pw-browsers/chromium"):
        if path and Path(path).exists():
            return pw.chromium.launch(executable_path=path)
    return pw.chromium.launch()


results: list[tuple[bool, str, object]] = []


def check(label, got, want=True):
    ok = want(got) if callable(want) else (got == want)
    results.append((ok, label, got))
    print(("PASS " if ok else "FAIL ") + label + ("" if ok else f"   -> {got!r}"))
    return ok


def new_page(ctx, who, store=None, reset=True, at=None, enter="messi", config=None,
             seen="messi", teams=None, team=None):
    """A page acting as `who`. `store` seeds the shared database; `at` freezes the clock.

    Pages created from the SAME context share localStorage, which is how the
    cross-person tests prove one person's submission reaches another's screen.

    The page opens on the module catalogue, so by default this walks straight into
    MESSI with its explainer already read — which is what a returning user sees.
    Pass `enter=None` to stay on the catalogue, `seen=None` to arrive as somebody who
    has never opened the module (so its explainer shows), `config` to serve the page a
    settings document the way the server does, and `teams`/`team` to serve it the teams
    this viewer may read and which one is theirs.
    """
    pg = ctx.new_page()
    pg.on("pageerror", lambda e: results.append((False, "JS error: " + str(e), "")))
    if at:
        pg.add_init_script(_CLOCK % json.dumps(at))
    if config is not None:
        pg.add_init_script(f"window.FURA_CONFIG={json.dumps(config)};")
    if teams is not None:
        pg.add_init_script(f"window.FURA_TEAMS={json.dumps(teams)};"
                           f"window.FURA_TEAM={json.dumps(team)};")
    pg.add_init_script(
        f"window.__WHO__={json.dumps(who)};"
        f"window.__SEED__={json.dumps(store or {})};"
        f"window.__RESET__={json.dumps(reset)};"
    )
    if seen:
        pg.add_init_script(
            f'try {{ localStorage.setItem("fura.seen.{seen}", "1"); }} catch (e) {{}}')
    pg.add_init_script(STUB)
    pg.goto(preview_url())
    # Ditunggu sampai aplikasinya benar-benar menggambar sesuatu, bukan sampai sekian
    # milidetik lewat. boot() membaca simpanan bersama dulu, dan lamanya tidak tetap —
    # jeda yang dipatok membuat suite ini rewel persis ketika mesinnya sedang sibuk.
    ready(pg)
    if enter:
        pg.click(f"[data-mod={enter}]")
        pg.wait_for_timeout(250)
    return pg


def ready(pg, timeout=15_000):
    """Menunggu aplikasinya menggambar sesuatu.

    boot() membaca simpanan bersama dulu, dan lamanya tidak tetap. Jeda yang dipatok
    membuat suite rewel persis ketika mesinnya sedang sibuk — dan rewel yang sesekali
    lebih buruk daripada gagal yang jujur, karena orang berhenti mempercayai keduanya.
    """
    try:
        pg.wait_for_selector("#view *", timeout=timeout)
    except Exception:
        pass
    pg.wait_for_timeout(120)
    return pg


def stored_until(pg, body, timeout=10_000):
    """Menunggu sampai simpanan bersama memenuhi syaratnya; `body` membaca `s`.

    Yang ditunggu bukan "sudah ada isinya" tapi "sudah ada isi yang ini" — layar
    berikutnya sering bergantung pada perubahan status, bukan pada jumlah baris.
    """
    pg.wait_for_function(
        "() => { try { const s = JSON.parse(localStorage.getItem('__fake_db__') || 'null');"
        f"        return !!s && ({body}); }} catch (e) {{ return false; }} }}",
        timeout=timeout)


def stored(pg, collection="cycles", n=1, timeout=10_000):
    """Waits until the fake database on disk really holds `n` docs in `collection`.

    The page writes without awaiting the store, so opening a second identity right
    after a submission can outrun the write — which on screen looks exactly like data
    not being shared between people. Waiting on the stored copy removes the race
    instead of papering over it with a longer sleep.
    """
    pg.wait_for_function(
        """([c, want]) => { try { return Object.keys(JSON.parse(
             localStorage.getItem("__fake_db__") || "{}")[c] || {}).length >= want; }
           catch (e) { return false; } }""",
        arg=[collection, n], timeout=timeout)


def context(browser):
    return browser.new_context(viewport={"width": 400, "height": 860})


def store_of(pg):
    return pg.evaluate("window.__STORE__")


def txt(pg, sel):
    return pg.inner_text(sel) if pg.query_selector(sel) else ""


def report(title=""):
    bad = [r for r in results if not r[0]]
    print(f"\n{len(results) - len(bad)}/{len(results)} lolos{(' — ' + title) if title else ''}")
    return 1 if bad else 0
