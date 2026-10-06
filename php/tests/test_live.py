"""End to end against the real PHP app: Apache's job done by php -S, MySQL underneath.

This answers the only question that matters about the rewrite — does the page the squad
already tested still work when its data comes from MySQL instead of the artifact
platform? It signs in as a real person, files a real report, and reads it back from the
database as somebody else.

    MESSI_TEST_SOCKET=/var/run/mysqld/mysqld.sock python3 php/tests/test_live.py

Skips cleanly (exit 0) when there is no database or no browser to use.
"""

from __future__ import annotations

import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
sys.path.insert(0, str(HERE.parent.parent / "web" / "tests"))

try:
    from harness import check, launch, report, results, sync_playwright
except ImportError as exc:                                  # pragma: no cover
    print(f"Playwright tidak tersedia ({exc}) — dilewati.")
    sys.exit(0)

from livehost import Host, sign_in

with Host("messi_live_test") as host, sync_playwright() as p:
    browser = launch(p)
    base = host.base

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
    check("pesannya tidak membocorkan siapa yang punya akun di sini",
          guest.inner_text(".err"), lambda s: "tidak terdaftar" not in s and "tidak ada" not in s)

    # The classic. If quoting were wrong anywhere in the login path, this is where it shows.
    guest.fill("#email", "nicho@example.test' OR '1'='1")
    guest.fill("#password", "apa saja")
    guest.click("button[type=submit]")
    guest.wait_for_load_state("networkidle")
    check("email yang isinya SQL tidak membuka apa-apa",
          guest.url, lambda u: u.endswith("/login.php"))
    anon.close()

    print("\n=== pemain mengisi laporan ===")
    ctx = browser.new_context(viewport={"width": 400, "height": 900})
    pg = sign_in(ctx, base, "nicho@example.test", results=results)
    check("masuk ke aplikasi", pg.inner_text("h1"), "Berapa banyak hari ini?")
    check("namanya terbaca dari database", pg.inner_text("body"), lambda s: "Nicho" in s)
    check("cookie sesinya tidak bisa dibaca JavaScript",
          pg.evaluate("document.cookie"), lambda c: "messi_session" not in c)

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

    pg.fill("[name=detail]", "WAG Klien A belum dibalas, TGG Vendor B belum diputus")
    pg.fill("[data-pa='0']", "Telepon pagi")
    due = pg.evaluate(
        "() => { const t = new Date(Date.now() + 2*864e5); return t.toISOString().slice(0,10); }")
    due2 = pg.evaluate(
        "() => { const t = new Date(Date.now() + 4*864e5); return t.toISOString().slice(0,10); }")
    pg.fill("[data-pd='0']", due)
    # Dua rencana dengan tanggal berbeda, lewat jalur sungguhan: halaman -> save.php ->
    # MySQL. Bentuk daftarnya hanya benar kalau sampai ke tabel commitments utuh.
    pg.click("#addPlan")
    pg.wait_for_timeout(200)
    pg.fill("[data-pa='1']", "Putuskan vendor B")
    pg.fill("[data-pd='1']", due2)
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
    pg2 = sign_in(fresh, base, "nicho@example.test", results=results)
    check("dari sesi baru pun tetap ada (jadi ini dari MySQL, bukan localStorage)",
          pg2.inner_text("h1"), "Sudah terkirim")
    check("janji yang tadi dibuat ikut tersimpan", pg2.inner_text("body"),
          lambda s: "Telepon pagi" in s)
    rows = host.php("-r", """
        require "lib/bootstrap.php";
        foreach (q("SELECT action_text, due_date, status FROM commitments ORDER BY due_date")
                 as $r) { echo $r["action_text"], "|", $r["due_date"], "|", $r["status"], "\n"; }
    """).stdout.strip().splitlines()
    check("dua rencana jadi dua janji di database", rows,
          [f"Telepon pagi|{due}|open", f"Putuskan vendor B|{due2}|open"])
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

    print("\n=== memperbaiki laporan yang sudah dikirim ===")
    pg.reload()
    pg.wait_for_load_state("networkidle")
    pg.wait_for_timeout(700)
    pg.click("#edit")
    pg.wait_for_timeout(400)
    check("angka yang dulu diisi masih ada, tidak mulai dari kosong",
          pg.input_value("[data-row=WAG][data-col=open]"), "13")
    cell("GCG", "open", 9)
    pg.wait_for_timeout(200)
    pg.click("#next")
    pg.wait_for_timeout(300)
    check("rencana yang dulu diisi juga kembali, bukan kosong",
          pg.eval_on_selector_all("[data-pa]", "e=>e.map(x=>x.value)"),
          ["Telepon pagi", "Putuskan vendor B"])
    pg.click("[data-drop='1']")       # vendor B ternyata tidak perlu diputus
    pg.wait_for_timeout(250)
    pg.click("#next")
    pg.wait_for_timeout(300)
    if pg.query_selector("[name=declared]"):
        pg.check("[name=declared]")
        pg.click("#next")
    pg.wait_for_timeout(900)
    check("perbaikan ikut terkirim", pg.inner_text("h1"), "Sudah terkirim")
    promises = host.php("-r", """
        require "lib/bootstrap.php";
        foreach (q("SELECT action_text, status FROM commitments ORDER BY due_date") as $r) {
            echo $r["action_text"], "|", $r["status"], "\n"; }
    """).stdout.strip().splitlines()
    check("memperbaiki laporan tidak meninggalkan janji kedua",
          promises, [f"Telepon pagi|open", "Putuskan vendor B|cancelled"])
    days = host.php("-r", """
        require "lib/bootstrap.php";
        echo (string) q1("SELECT COUNT(*) n FROM cycles WHERE submitted_at IS NOT NULL")["n"];
    """).stdout.strip()
    check("dan tetap satu laporan untuk hari itu", days, "1")

    print("\n=== yang dilihat leader ===")
    lead_ctx = browser.new_context(viewport={"width": 420, "height": 900})
    lead = sign_in(lead_ctx, base, "lead@example.test", results=results)
    check("leader punya tab squad", lead.eval_on_selector("#tabs", "e=>!e.hidden"))
    lead.click('[data-tab="tim"]')
    lead.wait_for_timeout(500)
    body = lead.inner_text("body")
    check("judulnya rekap, bukan daftar masalah", lead.inner_text("h1"), "Rekap tim")
    check("hitungannya 1 dari 3", lead.inner_text(".stats"), lambda s: "1/3" in s)
    check("nama, bukan id database", "u_1" not in body and "u_2" not in body)
    check("semua orang terdaftar, bukan cuma yang bermasalah",
          body, lambda s: "SEMUA (3)" in s and "Nicho" in s and "Rio" in s)
    check("yang sudah lapor tampil dengan angkanya",
          body, lambda s: "26 aktif" in s)   # 13 WAG + 4 TGG + 9 GCG, setelah dikoreksi
    check("yang belum lapor ditandai begitu", body, lambda s: "belum lapor" in s)
    check("laporan penuh tidak terhampar sekaligus", "Deklarasi:" not in body)

    print("\n--- leader mengisi laporannya sendiri ---")
    lead.click('[data-tab="hari-ini"]')
    lead.wait_for_timeout(400)
    for row, open_n in [("WAG", 5), ("TGG", 2), ("GCG", 1)]:
        lead.fill(f"[data-row={row}][data-col={open_n and 'open'}]", str(open_n))
    lead.fill("[data-row=WAG][data-col=reply]", "5")
    lead.wait_for_timeout(250)
    lead.click("#next")
    lead.wait_for_timeout(300)
    lead.check("[name=declared]")
    lead.click("#next")
    lead.wait_for_timeout(900)
    check("leader juga mengisi laporannya", lead.inner_text("h1"), "Sudah terkirim")
    lead.click('[data-tab="tim"]')
    lead.wait_for_timeout(500)
    check("hitungannya naik jadi 2 dari 3", lead.inner_text(".stats"), lambda s: "2/3" in s)

    print("\n--- atasan membaca laporan utuh dari website, tanpa Google Chat ---")
    me = lead.evaluate("""() => document.querySelector("[data-open]").dataset.open""")
    lead.click(f'[data-open="{me}"]')
    lead.wait_for_timeout(350)
    opened = lead.inner_text(".report")
    check("laporan utuh bisa dibuka dari rekap",
          opened, lambda s: s.startswith("MESSI Report") and "Deklarasi:" in s)
    check("dan isinya dari database, bukan diketik ulang",
          opened, lambda s: "Channel aktif/open" in s)
    lead.click(f'[data-open="{me}"]')
    lead.wait_for_timeout(300)
    check("bisa ditutup lagi", lead.query_selector(".report") is None)

    print("\n--- dan bisa membaca hari lain ---")
    prev = lead.query_selector("[data-day]")
    check("ada jalan ke hari kerja sebelumnya", prev is not None)
    prev.click()
    lead.wait_for_timeout(400)
    check("pindah ke hari sebelumnya", lead.inner_text(".stats"), lambda s: "0/" in s)
    check("hari itu memang belum ada yang lapor",
          lead.inner_text("body"), lambda s: "belum lapor" in s or "tidak lapor" in s)
    lead.click('[data-tab="hari-ini"]')
    lead.click('[data-tab="tim"]')
    lead.wait_for_timeout(400)
    check("ganti tab mengembalikan ke hari ini",
          lead.inner_text(".stats"), lambda s: "2/3" in s)

    print("\n=== data yang diberikan ke tiap orang ===")
    def fetched(page):
        return page.evaluate(
            """async () => (await fetch("api/data.php", { headers: { "X-MESSI": "1" } })).json()""")

    # Two reports exist now, which is what makes this worth asking.
    leader_data = fetched(lead)
    check("leader menerima dua-duanya, karena punya layarnya", len(leader_data["cycles"]), 2)
    check("dan tahu dirinya leader", leader_data["me"]["isLeader"], True)

    player_data = fetched(pg)
    check("pemain hanya menerima laporannya sendiri", len(player_data["cycles"]), 1)
    check("laporan orang lain tidak ikut terkirim ke browsernya",
          all(c["owner"] == player_data["me"]["id"] for c in player_data["cycles"].values()))
    check("begitu juga janji orang lain",
          all(c["owner"] == player_data["me"]["id"] for c in player_data["commitments"].values()))
    check("pemain tahu dirinya bukan leader", player_data["me"]["isLeader"], False)
    check("tapi tetap dapat daftar nama squad, karena halamannya butuh",
          len(player_data["roster"]), 3)

    print("\n=== nama yang aneh tidak merusak halaman ===")
    host.php("-r", """
        require "lib/bootstrap.php";
        q("UPDATE users SET name = ? WHERE email = ?",
          ["<script>window.__PWNED__=1</script>Rio", "rio@example.test"]);
    """)
    lead.reload()
    lead.wait_for_load_state("networkidle")
    lead.wait_for_timeout(700)
    lead.click('[data-tab="tim"]')
    lead.wait_for_timeout(400)
    check("nama berisi HTML tidak dijalankan sebagai kode",
          lead.evaluate("window.__PWNED__ === undefined"))
    check("dan tetap terbaca sebagai teks biasa",
          lead.inner_text("body"), lambda s: "<script>" in s or "Rio" in s)

    print("\n=== keluar ===")
    cookie = [c for c in lead_ctx.cookies() if c["name"] == "messi_session"][0]["value"]
    lead.goto(base + "/api/logout.php")
    lead.wait_for_load_state("networkidle")
    check("keluar mengantar kembali ke login", lead.url, lambda u: u.endswith("/login.php"))
    replay = browser.new_context(viewport={"width": 400, "height": 900})
    replay.add_cookies([{"name": "messi_session", "value": cookie,
                         "url": base}])
    back = replay.new_page()
    back.goto(base + "/index.php")
    check("cookie lama yang dipakai lagi tidak menghidupkan sesinya",
          back.url, lambda u: u.endswith("/login.php"))
    replay.close()

    print("\n=== yang tidak boleh terjadi ===")
    forged = pg.evaluate(
        """async (id) => {
             const r = await fetch("api/save.php", {
               method: "POST",
               headers: { "Content-Type": "application/json", "X-MESSI": "1" },
               body: JSON.stringify({ collection: "cycles", id,
                 doc: { grid: { WAG: { open: 1 } }, declared: true } }) });
             return r.status;
           }""",
        "u_3__" + due)
    check("tidak bisa mengirim laporan atas nama orang lain / tanggal lain", forged, 422)

    promoted = pg.evaluate(
        """async () => {
             const r = await fetch("api/save.php", {
               method: "POST",
               headers: { "Content-Type": "application/json", "X-MESSI": "1" },
               body: JSON.stringify({ collection: "roster", id: "u_1",
                 doc: { name: "Nicho", role: "admin", leader: true, joined: "2020-01-01" } }) });
             return r.status;
           }""")
    still = host.php("-r", """
        require "lib/bootstrap.php";
        $u = q1("SELECT role, joined_on FROM users WHERE email = ?", ["nicho@example.test"]);
        echo $u["role"] . " " . $u["joined_on"];
    """).stdout.strip()
    check("menulis barisnya sendiri tidak bisa menaikkan peran atau memundurkan tanggal masuk",
          still, lambda s: s.startswith("player") and "2020" not in s)

    nocsrf = pg.evaluate(
        """async () => {
             const r = await fetch("api/save.php", { method: "POST",
               headers: { "Content-Type": "application/json" },
               body: JSON.stringify({ collection: "roster", id: "u_1", doc: { name: "X" } }) });
             return r.status;
           }""")
    check("permintaan tanpa header X-MESSI ditolak", nocsrf, 403)

    check("cron tanpa kunci ditolak",
          pg.evaluate("""async () => (await fetch("cron/tick.php?key=salah")).status"""), 403)
    check("halaman admin tertutup untuk pemain",
          pg.evaluate("""async () => (await fetch("admin.php")).status"""), 403)
    check("setup tertutup begitu sudah ada akun",
          pg.evaluate("""async () => (await fetch("setup.php")).status"""), 403)

    # php -S does not read .htaccess, so this proves only that PHP is executed rather
    # than served as text — the .htaccess rules that also block these paths outright are
    # a second layer, and only Apache can be asked whether they hold.
    libfile = pg.evaluate("""async () => (await fetch("lib/engine.php")).text()""")
    check("kode di lib/ tidak pernah dikirim sebagai teks", "<?php" not in libfile)
    conf = pg.evaluate("""async () => (await fetch("config.example.php")).text()""")
    check("berkas konfigurasi pun tidak", "<?php" not in conf)

    lead_ctx.close()
    ctx.close()
    browser.close()

sys.exit(report("PHP + MySQL, hidup"))
