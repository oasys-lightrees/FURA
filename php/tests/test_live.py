"""End to end against the real PHP app: Apache's job done by php -S, MySQL underneath.

This is the test that answers the only question that matters about the rewrite — does
the page the squad already tested still work when its data comes from MySQL instead of
the artifact platform? It logs in as a real user, files a real report, and reads it back
from the database as somebody else.

    MESSI_TEST_SOCKET=/var/run/mysqld/mysqld.sock python3 php/tests/test_live.py

Skips cleanly (exit 0) when there is no database or no browser to use.
"""

from __future__ import annotations

import json
import os
import socket
import subprocess
import sys
import tempfile
import time
from pathlib import Path

HERE = Path(__file__).resolve().parent
PHP = HERE.parent
sys.path.insert(0, str(PHP.parent / "web" / "tests"))

try:
    from harness import check, launch, report, results, sync_playwright
except ImportError as exc:                                  # pragma: no cover
    print(f"Playwright tidak tersedia ({exc}) — dilewati.")
    sys.exit(0)

SOCKET = os.environ.get("MESSI_TEST_SOCKET", "")
HOST = os.environ.get("MESSI_TEST_HOST", "" if SOCKET else "localhost")
USER = os.environ.get("MESSI_TEST_USER", "root")
PASS = os.environ.get("MESSI_TEST_PASS", "")
DB = os.environ.get("MESSI_TEST_DB", "messi_live_test")
PASSWORD = "kata-sandi-panjang"


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


port = free_port()
base = f"http://127.0.0.1:{port}"
workdir = Path(tempfile.mkdtemp(prefix="messi-live-"))
config = workdir / "config.php"
# Written as JSON the PHP side decodes, rather than hand-built PHP syntax — a base_url
# with a "://" in it makes naive string surgery produce a config that does not parse.
config.write_text(
    "<?php return json_decode(<<<'JSON'\n"
    + json.dumps({
        "db": {"host": HOST, "socket": SOCKET, "name": DB, "user": USER, "pass": PASS},
        "base_url": base,
        "telegram_token": "",
        "cron_key": "test-key",
        "first_day": "2026-09-28",
        "session_days": 30,
    }, indent=2)
    + "\nJSON, true);\n"
)

env = {**os.environ, "MESSI_CONFIG_FILE": str(config)}

seed = subprocess.run(["php", str(HERE / "seed_live.php")],
                      env=env, capture_output=True, text=True)
if seed.returncode != 0:
    print("Tidak bisa menyiapkan database — dilewati.\n" + (seed.stderr or seed.stdout).strip())
    sys.exit(0)
print(seed.stdout.strip())

server = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", str(PHP)],
                          env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
for _ in range(60):
    try:
        with socket.create_connection(("127.0.0.1", port), 0.2):
            break
    except OSError:
        time.sleep(0.1)


def sign_in(ctx, email):
    pg = ctx.new_page()
    pg.on("pageerror", lambda e: results.append((False, "JS error: " + str(e), "")))
    pg.goto(base + "/login.php")
    pg.fill("#email", email)
    pg.fill("#password", PASSWORD)
    pg.click("button[type=submit]")
    pg.wait_for_load_state("networkidle")
    pg.wait_for_timeout(600)
    return pg


try:
    with sync_playwright() as p:
        browser = launch(p)

        print("\n=== masuk ===")
        anon = browser.new_context(viewport={"width": 400, "height": 900})
        guest = anon.new_page()
        guest.goto(base + "/index.php")
        check("belum masuk dialihkan ke login", guest.url, lambda u: u.endswith("/login.php"))
        guest.fill("#email", "nicho@example.test")
        guest.fill("#password", "salah-sekali")
        guest.click("button[type=submit]")
        guest.wait_for_load_state("networkidle")
        check("password salah ditolak", guest.inner_text(".err"), lambda s: "salah" in s)
        anon.close()

        print("\n=== pemain mengisi laporan ===")
        ctx = browser.new_context(viewport={"width": 400, "height": 900})
        pg = sign_in(ctx, "nicho@example.test")
        check("masuk ke aplikasi", pg.url, lambda u: u.rstrip("/").endswith("index.php") or u.endswith(str(port) + "/"))
        check("halaman pertama adalah formnya", pg.inner_text("h1"), "Berapa banyak hari ini?")
        check("namanya terbaca dari database", pg.inner_text("body"), lambda s: "Nicho" in s)
        check("tidak ada tanda offline",
              pg.evaluate("!document.body.innerText.includes('offline')"))

        def cell(row, col, value):
            pg.fill(f"[data-row={row}][data-col={col}]", str(value))

        for row, open_n in [("WAG", 13), ("TGG", 4), ("GCG", 4)]:
            cell(row, "open", open_n)
        cell("WAG", "gt3", 2)
        cell("WAG", "lt3", 1)
        cell("WAG", "reply", 5)
        pg.wait_for_timeout(250)
        pg.click("#next")
        pg.wait_for_timeout(300)
        check("yang gantung memunculkan langkah keduanya",
              pg.inner_text("body"), lambda s: "gantung" in s.lower())

        pg.fill("[name=detail]", "WAG Klien A belum dibalas")
        pg.fill("[name=plan]", "Telepon pagi")
        due = pg.evaluate(
            "() => { const t = new Date(Date.now() + 2*864e5); return t.toISOString().slice(0,10); }")
        pg.fill("[name=due]", due)
        pg.click("#next")
        pg.wait_for_timeout(300)
        pg.check("[name=declared]")
        pg.click("#next")
        pg.wait_for_timeout(900)
        check("laporan terkirim", pg.inner_text("h1"), "Sudah terkirim")

        print("\n=== benar-benar tersimpan ===")
        pg.reload()
        pg.wait_for_load_state("networkidle")
        pg.wait_for_timeout(800)
        check("setelah muat ulang tetap terkirim", pg.inner_text("h1"), "Sudah terkirim")

        fresh = browser.new_context(viewport={"width": 400, "height": 900})
        pg2 = sign_in(fresh, "nicho@example.test")
        check("dari sesi baru pun tetap ada (jadi ini dari MySQL, bukan localStorage)",
              pg2.inner_text("h1"), "Sudah terkirim")
        check("janji yang tadi dibuat ikut tersimpan", pg2.inner_text("body"),
              lambda s: "Telepon pagi" in s)
        check("hari yang terlewat dikenali dari tanggal masuk di database",
              pg2.inner_text("body"), lambda s: "tidak lapor" in s)
        pg2.click("text=Lihat")
        pg2.wait_for_timeout(300)
        report_text = pg2.inner_text("#reportText")
        check("laporan yang dibangun server dan halaman berisi yang diketik",
              report_text, lambda s: "WAG Klien A belum dibalas" in s)
        check("dan formatnya yang sudah dikenal squad",
              report_text, lambda s: s.startswith("MESSI Report") and "Deklarasi:" in s)
        fresh.close()

        print("\n=== yang dilihat leader ===")
        lead_ctx = browser.new_context(viewport={"width": 420, "height": 900})
        lead = sign_in(lead_ctx, "chief@example.test")
        check("leader punya tab squad", lead.eval_on_selector("#tabs", "e=>!e.hidden"))
        lead.click('[data-tab="chief"]')
        lead.wait_for_timeout(500)
        body = lead.inner_text("body")
        check("leader melihat laporan orang lain", body, lambda s: "Nicho" in s)
        check("hitungannya 1 dari 3", lead.inner_text(".stats"), lambda s: "1/3" in s)
        check("nama, bukan id database", "u_1" not in body and "u_2" not in body)
        check("leader tidak melihat isi laporan penuh orang lain",
              "Deklarasi:" not in body)

        print("\n=== yang tidak boleh terjadi ===")
        forged = lead.evaluate(
            """async (id) => {
                 const r = await fetch("api/save.php", {
                   method: "POST",
                   headers: { "Content-Type": "application/json", "X-MESSI": "1" },
                   body: JSON.stringify({ collection: "cycles", id,
                     doc: { grid: { WAG: { open: 1 } }, declared: true } }) });
                 return r.status;
               }""",
            "u_1__" + due)
        check("tidak bisa mengirim laporan atas nama orang lain / tanggal lain", forged, 422)

        nocsrf = lead.evaluate(
            """async () => {
                 const r = await fetch("api/save.php", { method: "POST",
                   headers: { "Content-Type": "application/json" },
                   body: JSON.stringify({ collection: "roster", id: "u_3", doc: { name: "X" } }) });
                 return r.status;
               }""")
        check("permintaan tanpa header X-MESSI ditolak", nocsrf, 403)

        cron = lead.evaluate(
            """async () => (await fetch("cron/tick.php?key=salah")).status""")
        check("cron tanpa kunci ditolak", cron, 403)

        admin = lead.evaluate("""async () => (await fetch("admin.php")).status""")
        check("halaman admin tertutup untuk leader biasa", admin, 403)

        libfile = lead.evaluate("""async () => (await fetch("lib/engine.php")).text()""")
        check("kode di lib/ tidak pernah dikirim sebagai teks", "<?php" not in libfile)

        lead_ctx.close()
        ctx.close()
        browser.close()
finally:
    server.terminate()
    server.wait(timeout=10)

sys.exit(report("PHP + MySQL, hidup"))
