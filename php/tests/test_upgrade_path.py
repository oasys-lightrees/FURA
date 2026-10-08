"""Berkas baru di atas database lama — jendela yang dilewati tiap pemasangan.

Dulu jendela itu dijaga sebuah tombol: siapa pun yang membuka aplikasinya mendapat
halaman "belum siap" sampai ada admin yang sempat menekan "Jalankan". Tombol itu dipasang
sebagai penjagaan, tapi tidak pernah sekali pun jawabannya "jangan" — jadi yang benar-benar
dihasilkannya cuma seluruh tim menatap tembok sampai adminnya membuka laptop.

Sekarang halaman pertama yang membutuhkan bentuk baru menyusulkan databasenya sendiri.
Suite ini memeriksa jendela itu dari ujung ke ujung: masih bisa masuk dengan password
lama, halaman pertama yang dibuka langsung hidup tanpa satu tombol pun ditekan, yang
dikerjakannya tercatat, dan data lamanya utuh.

    MESSI_TEST_SOCKET=/var/run/mysqld/mysqld.sock python3 php/tests/test_upgrade_path.py
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

from livehost import PASSWORD, Host

with Host("messi_upgrade_test", seed="lama") as host, sync_playwright() as p:
    browser = launch(p)
    ctx = browser.new_context(viewport={"width": 1000, "height": 900})
    pg = ctx.new_page()
    pg.on("pageerror", lambda e: results.append((False, "JS error: " + str(e), "")))

    def status(path, method="GET", data=None):
        """Status dan isi sebuah halaman, tanpa meninggalkan halaman yang sedang dibuka.

        Dikembalikan sebagai teks walau 500: halaman 500 yang membawa kalimat justru
        yang sedang diuji di sini.
        """
        return pg.evaluate(
            """async ([p, m, d]) => {
                 const o = { method: m };
                 if (d) { o.body = new URLSearchParams(d); }
                 const r = await fetch(p, o);
                 return [r.status, (await r.text()).slice(0, 4000)];
               }""", [host.base + path, method, data])

    def tertunda():
        return int(host.php("-r", """require 'lib/schema.php'; echo count(schema_pending());""")
                   .stdout.strip() or "-1")

    print("\n=== sebelum dimutakhirkan ===")
    check("databasenya memang masih bentuk lama", tertunda(), lambda n: n > 0)
    pg.goto(host.base + "/login.php")
    check("halaman masuk tetap terbuka", pg.inner_text("h1"), "FURA")

    # Inti suite ini. Pernah 500 kosong di sini, karena pembatasan percobaan masuk
    # menanyakan tabel yang baru dibuat oleh pemutakhiran — yang cuma bisa dijalankan
    # setelah masuk. Pintu yang kuncinya ada di dalam.
    pg.fill("#email", "lead@example.test")
    pg.fill("#password", PASSWORD)
    pg.click("button[type=submit]")
    pg.wait_for_load_state("networkidle")
    check("dan masuk dengan password lama tetap berhasil",
          pg.url, lambda u: not u.endswith("/login.php"))

    # Masuk mendaratkan orangnya di aplikasinya — dan itu sendiri sudah cukup: halaman
    # itulah yang pertama membutuhkan bentuk baru, jadi dialah yang menyusulkannya.
    check("mendarat di aplikasinya, bukan di tembok",
          pg.inner_text("body"), lambda s: "Database perlu dimutakhirkan" not in s)
    check("dan sesudah itu databasenya tidak punya sisa", tertunda(), 0)

    # Halaman berikutnya diperiksa lewat fetch, dan fetch butuh berdiri di halaman yang
    # memang punya asal-usul.
    pg.goto(host.base + "/cek.php")
    pg.wait_for_load_state("networkidle")
    check("halaman cek terbuka", pg.inner_text("h1"), lambda s: "Cek sistem" in s)
    check("dan menyebut bentuk databasenya sudah sesuai",
          pg.inner_text("body"), lambda s: "sesuai dengan versi yang terpasang" in s)

    def job_log(kind):
        return host.php("-r", f"""require 'lib/bootstrap.php';
            foreach (q("SELECT detail FROM job_log WHERE kind = '{kind}'") as $r) {{
                echo $r['detail'] . "\n"; }}""").stdout.strip()

    print("\n=== yang dikerjakannya tercatat ===")
    code, body = status("/index.php")
    check("index.php hidup", code, 200)
    check("dan yang tergambar memang aplikasinya",
          body, lambda s: "Database perlu dimutakhirkan" not in s and "FURA" in s)

    naik = job_log("schema_auto")
    check("yang dikerjakannya tercatat", naik, lambda s: s != "")
    check("beserta nama langkah-langkahnya", naik, lambda s: "teams" in s and "modules" in s)

    # Permintaan kedua tidak mengerjakan apa pun lagi: yang tertunda sudah habis, dan
    # kuncinya memastikan dua permintaan yang datang bersamaan tidak saling menimpa.
    status("/index.php")
    check("dibuka lagi tidak menaikkan apa pun untuk kedua kalinya",
          job_log("schema_auto").count("\n"), 0)

    for path in ["/admin.php", "/soal.php", "/modul.php"]:
        code, body = status(path)
        check(f"{path} ikut hidup", body, lambda s: "Database perlu dimutakhirkan" not in s)

    code, body = status("/api/data.php")
    check("API pun menjawab dengan data, bukan keluhan",
          body, lambda s: "dimutakhirkan" not in s.lower())

    print("\n=== tombolnya tetap ada, dan mengatakan tidak ada sisa ===")
    pg.goto(host.base + "/upgrade.php")
    pg.wait_for_load_state("networkidle")
    check("halaman pemutakhiran terbuka", pg.inner_text("h1"), "Pemutakhiran database")
    check("dan mengatakan tidak ada lagi yang tertunda",
          pg.inner_text("body"), lambda s: "sudah sesuai dengan versi" in s)

    print("\n=== sesudahnya ===")
    pg.goto(host.base + "/admin.php")
    pg.wait_for_load_state("networkidle")
    isi = pg.inner_text("body")
    check("halaman orang & tim hidup", pg.inner_text("h1"), "Orang & tim")
    check("orang lama tidak hilang", isi, lambda s: "nicho@example.test" in s)
    check("semuanya dimasukkan ke satu tim", isi, lambda s: "2 orang" in s)
    # Yang paling mudah terlewat: akun lama ikut dianggap belum menerima undangan, lalu
    # terkunci di luar oleh fitur yang baru dipasang.
    check("dan tidak ada yang mendadak dianggap belum terima undangan",
          isi, lambda s: "belum terima undangan" not in s)
    check("admin pertama naik jadi owner, supaya ada yang bisa mengangkat admin",
          isi, lambda s: "Owner (kamu)" in s)

    pg.goto(host.base + "/index.php")
    pg.wait_for_load_state("networkidle")
    pg.wait_for_timeout(900)
    check("aplikasinya hidup", pg.inner_text("body"), lambda s: "Modul" in s)

    pg.goto(host.base + "/cek.php")
    check("cek hosting menyatakan bentuknya sudah sesuai",
          pg.inner_text("body"), lambda s: "sesuai dengan versi yang terpasang" in s)

    # Dan orang yang bukan admin tetap bisa masuk dengan password lamanya.
    other = browser.new_context(viewport={"width": 400, "height": 900})
    op = other.new_page()
    op.goto(host.base + "/login.php")
    op.fill("#email", "nicho@example.test")
    op.fill("#password", PASSWORD)
    op.click("button[type=submit]")
    op.wait_for_load_state("networkidle")
    op.wait_for_timeout(700)
    check("pemain lama masuk dengan password lamanya, tanpa undangan apa pun",
          op.inner_text("body"), lambda s: "Modul" in s or "Berapa banyak" in s)
    other.close()

    ctx.close()
    browser.close()

sys.exit(report("berkas baru di atas database lama"))
