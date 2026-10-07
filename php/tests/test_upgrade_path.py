"""Berkas baru di atas database lama — jendela yang dilewati tiap pemasangan.

Antara meng-upload versi baru dan menjalankan pemutakhirannya selalu ada jarak: semenit,
sejam, atau sampai besok pagi kalau yang mengurus sedang tidak di tempat. Di jendela itu
aplikasinya harus tetap bisa dimasuki — kalau tidak, pintu satu-satunya menuju tombol
pemutakhiran ada di balik pintu yang terkunci.

Suite ini menjalankan aplikasi sungguhan di atas skema versi sebelumnya, lalu memeriksa
tiga hal berurutan: masih bisa masuk, setiap halaman lain mengatakan apa yang kurang dan
ke mana harus pergi, dan setelah satu tombol ditekan semuanya hidup dengan data lama utuh.

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

    print("\n=== sebelum dimutakhirkan ===")
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

    # Halaman berikutnya diperiksa lewat fetch, dan fetch butuh berdiri di halaman yang
    # memang punya asal-usul. Halaman cek dipilih karena dia satu-satunya yang dijamin
    # terbuka justru ketika yang lain sedang tidak — itu memang gunanya.
    pg.goto(host.base + "/cek.php")
    pg.wait_for_load_state("networkidle")
    check("halaman cek tetap terbuka, karena gunanya memang melaporkan keadaan",
          pg.inner_text("h1"), lambda s: "Cek sistem" in s)
    check("dan menyebut bahwa bentuk databasenya tertinggal",
          pg.inner_text("body"), lambda s: "belum dikerjakan" in s)

    # Halaman yang memang butuh bentuk baru tidak berpura-pura jalan — tapi juga tidak
    # kosong: yang tidak bisa ditindaklanjuti adalah halaman yang tidak mengatakan apa-apa.
    for path in ["/index.php", "/admin.php", "/soal.php"]:
        code, body = status(path)
        check(f"{path} mengatakan apa yang kurang, bukan halaman kosong",
              body, lambda s: "Database perlu dimutakhirkan" in s)
        check(f"{path} menunjukkan ke mana harus pergi",
              body, lambda s: "upgrade.php" in s)

    code, body = status("/api/data.php")
    check("API pun menjawab dengan kalimat, bukan badan kosong",
          body, lambda s: "dimutakhirkan" in s.lower())

    print("\n=== menjalankan pemutakhirannya ===")
    pg.goto(host.base + "/upgrade.php")
    pg.wait_for_load_state("networkidle")
    check("halaman pemutakhiran terbuka", pg.inner_text("h1"), "Pemutakhiran database")
    check("menyebut berapa hal yang akan dikerjakan",
          pg.inner_text("body"), lambda s: "belum dikerjakan" in s or "perlu dikerjakan" in s)
    check("tiap langkah dijelaskan akibatnya ke data, bukan sebagai SQL",
          pg.inner_text("body"), lambda s: "tidak dihapus" in s and "SELECT" not in s)

    pg.click("button:has-text('Jalankan sekarang')")
    pg.wait_for_load_state("networkidle")
    check("selesai", pg.inner_text(".ok"), lambda s: "Selesai" in s)
    check("dan sesudahnya tidak ada lagi yang tertunda",
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
