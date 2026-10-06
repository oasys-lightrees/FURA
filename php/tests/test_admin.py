"""Getting a squad onto the system, and off it again.

The parts nobody thinks to test until the day they are needed: the first account on a
fresh install, adding somebody, changing what they are allowed to see, resetting a
password, and handing somebody a one-time link when they are locked out.

    MESSI_TEST_SOCKET=/var/run/mysqld/mysqld.sock python3 php/tests/test_admin.py
"""

from __future__ import annotations

import json
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

from livehost import PASSWORD, Host, sign_in

NEW_PASSWORD = "password-baru-yang-panjang"

# install.sql dijalankan ulang persis seperti phpMyAdmin menjalankannya: baris komentar
# dibuang dulu, baru dipecah per titik koma.
REIMPORT = """require 'lib/bootstrap.php';
$sql = preg_replace('/^--.*$/m', '', file_get_contents('install.sql'));
foreach (explode(';', $sql) as $one) {
    if (trim($one) !== '') { db()->exec($one); }
}"""


def status(pg, path, base):
    return pg.evaluate("async p => (await fetch(p)).status", base + path)


with Host("messi_admin_test", seed="empty") as host, sync_playwright() as p:
    browser = launch(p)
    ctx = browser.new_context(viewport={"width": 1000, "height": 900})

    print("\n=== pemasangan pertama ===")
    pg = ctx.new_page()
    pg.on("pageerror", lambda e: results.append((False, "JS error: " + str(e), "")))
    pg.goto(host.base + "/index.php")
    check("tanpa akun pun tidak bocor, hanya dialihkan ke login",
          pg.url, lambda u: u.endswith("/login.php"))

    pg.goto(host.base + "/setup.php")
    pg.fill("#name", "Lia")
    pg.fill("#email", "lead@example.test")
    pg.fill("#password", PASSWORD)
    pg.click("button[type=submit]")
    pg.wait_for_load_state("networkidle")
    check("akun pertama dibuat lalu diantar ke login", pg.url, lambda u: u.endswith("/login.php"))

    pg.goto(host.base + "/setup.php")
    check("setup menolak jalan untuk kedua kalinya",
          pg.inner_text("body"), lambda s: "Sudah ada akun" in s)

    print("\n=== masuk sebagai admin ===")
    admin = sign_in(ctx, host.base, "lead@example.test", results=results)
    check("admin masuk ke aplikasinya", admin.inner_text("h1"),
          lambda s: "Berapa banyak" in s or "Sudah terkirim" in s)
    check("admin melihat pintu ke halaman tim dan ke pertanyaannya",
          admin.inner_text("footer"), lambda s: "Tim" in s and "Pertanyaan" in s)

    admin.goto(host.base + "/admin.php")
    check("halaman tim terbuka", admin.inner_text("h1"), "Tim")
    check("dirinya sendiri tercatat", admin.inner_text("body"), lambda s: "lead@example.test" in s)
    check("perannya sendiri tidak bisa diturunkan sendiri",
          admin.inner_text("body"), lambda s: "admin (kamu)" in s)

    print("\n=== menambah orang ===")
    admin.fill("#n", "Nicho")
    admin.fill("#e", "nicho@example.test")
    admin.fill("#p", PASSWORD)
    admin.select_option("#r", "player")
    admin.click("button:has-text('Tambah')")
    admin.wait_for_load_state("networkidle")
    check("orangnya masuk daftar", admin.inner_text("body"), lambda s: "Nicho ditambahkan" in s)

    admin.fill("#n", "Nicho Lagi")
    admin.fill("#e", "nicho@example.test")
    admin.fill("#p", PASSWORD)
    admin.click("button:has-text('Tambah')")
    admin.wait_for_load_state("networkidle")
    check("email yang sama ditolak", admin.inner_text(".bad"), lambda s: "sudah terdaftar" in s)

    # The browser refuses to submit a short password before the server is troubled with
    # it, which is the right order — so the server's own guard has to be poked directly,
    # because that is the one that holds when the browser is not a browser.
    csrf = admin.get_attribute("input[name=csrf]", "value")
    short = admin.evaluate(
        """async ([url, csrf]) => {
             const body = new URLSearchParams({ do: "add", name: "Pendek",
               email: "pendek@example.test", password: "123", role: "player",
               joined: "2026-09-28", csrf });
             const r = await fetch(url, { method: "POST", body });
             return (await r.text()).includes("minimal 10");
           }""", [host.base + "/admin.php", csrf])
    check("password pendek ditolak juga di server", short)
    admin.reload()
    admin.wait_for_load_state("networkidle")
    check("dan orangnya tidak jadi dibuat",
          admin.inner_text("body"), lambda s: "pendek@example.test" not in s)

    print("\n=== orang baru itu betul-betul bisa masuk ===")
    other = browser.new_context(viewport={"width": 400, "height": 900})
    nicho = sign_in(other, host.base, "nicho@example.test", results=results)
    check("bisa masuk dengan password yang diberikan",
          nicho.inner_text("h1"), "Berapa banyak hari ini?")
    check("player tidak punya tab squad", nicho.eval_on_selector("#tabs", "e=>e.hidden"))
    check("dan tidak bisa membuka halaman squad", status(nicho, "/admin.php", host.base), 403)

    print("\n=== menaikkan jadi leader ===")
    admin.reload()
    admin.wait_for_load_state("networkidle")
    row = admin.locator("tr", has_text="nicho@example.test")
    row.locator("select[name=role]").select_option("leader")
    admin.wait_for_load_state("networkidle")
    check("perannya berubah", admin.inner_text(".ok"), lambda s: "Peran diubah" in s)

    nicho.reload()
    nicho.wait_for_load_state("networkidle")
    nicho.wait_for_timeout(600)
    check("leader baru langsung melihat tab squad", nicho.eval_on_selector("#tabs", "e=>!e.hidden"))
    check("tapi halaman admin tetap tertutup untuk leader",
          status(nicho, "/admin.php", host.base), 403)

    print("\n=== mengganti password ===")
    admin.reload()
    admin.wait_for_load_state("networkidle")
    row = admin.locator("tr", has_text="nicho@example.test")
    row.locator("input[name=password]").fill(NEW_PASSWORD)
    row.locator("button:has-text('Ganti')").click()
    admin.wait_for_load_state("networkidle")
    check("passwordnya diganti", admin.inner_text(".ok"), lambda s: "Password diganti" in s)

    nicho.goto(host.base + "/index.php")
    check("sesi lamanya ikut mati — ganti password berarti keluar di mana-mana",
          nicho.url, lambda u: u.endswith("/login.php"))

    stale = browser.new_context(viewport={"width": 400, "height": 900})
    old = sign_in(stale, host.base, "nicho@example.test", PASSWORD, results=results)
    check("password lama tidak berlaku lagi", old.inner_text(".err"), lambda s: "salah" in s)
    fresh = sign_in(stale, host.base, "nicho@example.test", NEW_PASSWORD, results=results)
    check("password baru berlaku", fresh.inner_text("h1"), "Berapa banyak hari ini?")
    stale.close()

    print("\n=== link masuk sekali pakai ===")
    admin.reload()
    admin.wait_for_load_state("networkidle")
    row = admin.locator("tr", has_text="nicho@example.test")
    row.locator("button:has-text('Link masuk')").click()
    admin.wait_for_load_state("networkidle")
    link = admin.inner_text(".ok code").strip()
    check("linknya lengkap dan sekali pakai", link,
          lambda s: s.startswith(host.base) and "login.php?t=" in s)
    # The warning belongs next to the link itself, not in the notice above it.
    check("admin diingatkan mengirimnya japri, bukan ke space",
          admin.locator(".note.ok", has=admin.locator("code")).inner_text(),
          lambda s: "japri" in s)

    spent = browser.new_context(viewport={"width": 400, "height": 900})
    sp = spent.new_page()
    sp.goto(link)
    sp.wait_for_load_state("networkidle")
    sp.wait_for_timeout(600)
    check("linknya memang memasukkan orangnya", sp.inner_text("h1"),
          lambda s: "Modul" in s or "Berapa banyak" in s or "Sudah terkirim" in s)
    spent.close()

    again = browser.new_context(viewport={"width": 400, "height": 900})
    ag = again.new_page()
    ag.goto(link)
    ag.wait_for_load_state("networkidle")
    check("dan tidak bisa dipakai kedua kalinya",
          ag.inner_text(".err"), lambda s: "sudah dipakai" in s or "kedaluwarsa" in s)
    again.close()

    print("\n=== menonaktifkan ===")
    admin.reload()
    admin.wait_for_load_state("networkidle")
    row = admin.locator("tr", has_text="nicho@example.test")
    row.locator("button:has-text('Nonaktifkan')").click()
    admin.wait_for_load_state("networkidle")
    gone = browser.new_context(viewport={"width": 400, "height": 900})
    blocked = sign_in(gone, host.base, "nicho@example.test", NEW_PASSWORD, results=results)
    check("yang dinonaktifkan tidak bisa masuk lagi",
          blocked.inner_text(".err"), lambda s: "salah" in s)
    gone.close()

    print("\n=== halaman cek hosting ===")
    check("cek.php tertutup untuk yang belum masuk",
          nicho.evaluate("""async () => { const r = await fetch("cek.php", {credentials:"omit"});
                                          return r.status; }"""), 403)
    admin.goto(host.base + "/cek.php")
    admin.wait_for_load_state("networkidle")
    body = admin.inner_text("body")
    check("admin melihat hasilnya", body, lambda s: "Cek hosting" in s)
    check("versi PHP diperiksa", body, lambda s: "Versi PHP" in s)
    check("tabelnya diperiksa", body, lambda s: "lengkap, 7 tabel" in s)
    check("base_url dicocokkan dengan alamat yang sedang dibuka",
          body, lambda s: "base_url" in s)
    check("perintah cron dibuatkan lengkap dengan path-nya",
          body, lambda s: "cron/tick.php" in s)
    check("tidak pernah menampilkan password database",
          "ganti-ini" not in body and "kata-sandi" not in body)

    print("\n=== mengubah pertanyaan MESSI ===")
    admin.goto(host.base + "/soal.php")
    admin.wait_for_load_state("networkidle")
    check("halaman pertanyaan terbuka", admin.inner_text("h1"), "Pertanyaan MESSI")
    check("pertanyaan yang berlaku sekarang yang ditampilkan",
          admin.input_value("input[name='q[detail][label]']"), "Yang mana saja?")
    check("menjelaskan akibat ambangnya, bukan cuma menyediakan kotaknya",
          admin.inner_text(".prev"), lambda s: "Gantung >3 hari" in s)

    admin.fill("input[name=team_name]", "Tim Dukungan")
    admin.select_option("select[name=threshold_days]", "1")
    admin.fill("input[name='ch[0][key]']", "IG")
    admin.fill("input[name='ch[0][label]']", "IG")
    admin.fill("input[name='ch[0][full]']", "Instagram DM")
    admin.fill("input[name='ch[1][key]']", "")          # TGG dibuang
    admin.fill("input[name='ch[2][key]']", "")          # GCG dibuang
    admin.fill("input[name='q[detail][label]']", "Tiket yang mana?")
    admin.fill("input[name='q[detail][error]']", "Tulis nomor tiketnya dulu.")
    admin.uncheck("input[name='q[prista][show]']")
    admin.click("button:has-text('Simpan')")
    admin.wait_for_load_state("networkidle")
    check("tersimpan", admin.inner_text(".ok"), lambda s: "Tersimpan" in s)
    check("dan yang ditampilkan ulang adalah yang berlaku",
          admin.input_value("input[name='q[detail][label]']"), "Tiket yang mana?")
    check("channel yang dikosongkan memang hilang",
          admin.input_value("input[name='ch[1][key]']"), "")
    check("akibatnya ikut berubah di penjelasannya",
          admin.inner_text(".prev"), lambda s: "Gantung >1 hari" in s)
    check("pertanyaan yang dimatikan tetap mati setelah disimpan",
          admin.is_checked("input[name='q[prista][show]']"), False)

    # Nilai yang tidak masuk akal tidak boleh membuat halamannya mati — dirapikan diam-diam.
    forced = admin.evaluate(
        """async ([url, csrf]) => {
             const body = new URLSearchParams({ csrf, threshold_days: "0",
               open_hour: "20", due_hour: "3", max_plans: "99", team_name: "Tim Dukungan" });
             const r = await fetch(url, { method: "POST", body });
             return r.status;
           }""", [host.base + "/soal.php",
                  admin.get_attribute("input[name=csrf]", "value")])
    check("setelan yang mustahil tidak menjatuhkan halamannya", forced, 200)
    # Dan token yang salah ditolak walau yang mengirim memang admin — kalau tidak, satu
    # tautan di tab lain bisa mengubah pertanyaan seluruh tim.
    forgedAdmin = admin.evaluate(
        """async url => {
             const body = new URLSearchParams({ csrf: "salah", threshold_days: "30" });
             const r = await fetch(url, { method: "POST", body });
             return r.status;
           }""", host.base + "/soal.php")
    check("kiriman admin tanpa token yang benar pun ditolak", forgedAdmin, 403)
    admin.goto(host.base + "/soal.php")
    admin.wait_for_load_state("networkidle")
    check("ambang nol dikembalikan ke yang masuk akal",
          admin.input_value("select[name=threshold_days]"), "3")
    check("jam tutup tidak dibiarkan mendahului jam buka",
          admin.evaluate("""() => {
            const o = +document.querySelector("[name=open_hour]").value;
            const d = +document.querySelector("[name=due_hour]").value;
            return d > o; }"""), True)
    check("dan channel tidak pernah jadi kosong sama sekali",
          admin.input_value("input[name='ch[0][key]']"), lambda s: s != "")

    print("\n--- yang dilihat pemain ikut berubah ---")
    # Dikembalikan dulu ke keadaan yang mau diuji, lalu dibaca dari sisi pemain.
    admin.fill("input[name=team_name]", "Tim Dukungan")
    admin.select_option("select[name=threshold_days]", "1")
    admin.fill("input[name='ch[0][key]']", "IG")
    admin.fill("input[name='ch[0][label]']", "IG")
    admin.fill("input[name='ch[0][full]']", "Instagram DM")
    for slot in range(1, 4):
        if admin.query_selector(f"input[name='ch[{slot}][key]']"):
            admin.fill(f"input[name='ch[{slot}][key]']", "")
    admin.select_option("select[name=open_hour]", "0")      # supaya jam berapa pun terbuka
    admin.select_option("select[name=due_hour]", "24")
    admin.fill("input[name='q[grid][label]']", "Berapa tiket hari ini?")
    admin.fill("input[name='q[detail][error]']", "Tulis nomor tiketnya dulu.")
    # Kiriman sampah di atas mematikan semua checkbox-nya, jadi yang satu ini dinyalakan
    # lagi — supaya di bawah terlihat bedanya antara yang hidup dan yang dimatikan.
    admin.check("input[name='q[escalation][show]']")
    admin.uncheck("input[name='q[prista][show]']")
    admin.click("button:has-text('Simpan')")
    admin.wait_for_load_state("networkidle")

    # Pemain baru: yang sebelumnya sudah dinonaktifkan beberapa bagian di atas. Dan
    # konteks sendiri, karena yang di atas memegang sesi admin.
    admin.goto(host.base + "/admin.php")
    admin.wait_for_load_state("networkidle")
    admin.fill("#n", "Oki")
    admin.fill("#e", "oki@example.test")
    admin.fill("#p", PASSWORD)
    admin.select_option("#r", "player")
    admin.click("button:has-text('Tambah')")
    admin.wait_for_load_state("networkidle")
    pctx = browser.new_context(viewport={"width": 420, "height": 900})
    player = sign_in(pctx, host.base, "oki@example.test", results=results)
    check("pemain melihat pertanyaan yang disetel admin",
          player.inner_text("h1"), "Berapa tiket hari ini?")
    check("dan channel yang disetel admin",
          player.eval_on_selector_all("[data-row]",
              "e=>[...new Set(e.map(x=>x.dataset.row))]"), ["IG"])
    check("ambangnya ikut di kolomnya", player.inner_text(".mx"),
          lambda s: ">1 hari" in s)

    player.fill("[data-row=IG][data-col=open]", "5")
    player.fill("[data-row=IG][data-col=gt3]", "1")
    player.wait_for_timeout(250)
    player.click("#next")
    player.wait_for_timeout(300)
    player.click("#next")
    player.wait_for_timeout(300)
    check("server pun memakai kalimat kesalahan yang ditulis admin",
          player.inner_text(".bar .err"), "Tulis nomor tiketnya dulu.")

    player.fill("[name=detail]", "#1041")
    player.fill("[data-pa='0']", "Eskalasi ke tim produk")
    player.fill("[data-pd='0']", "2099-01-01")
    player.click("#next")
    player.wait_for_timeout(300)
    check("pertanyaan yang dimatikan admin tidak ditanyakan ke pemain",
          player.query_selector("[name=prista]") is None)
    check("yang dibiarkan hidup tetap ditanyakan",
          player.query_selector("[name=escalation]") is not None)

    print("\n--- halaman pertanyaan tertutup untuk yang bukan admin ---")
    check("pemain tidak bisa membukanya", status(player, "/soal.php", host.base), 403)
    forged2 = player.evaluate(
        """async url => {
             const body = new URLSearchParams({ csrf: "salah", threshold_days: "30" });
             const r = await fetch(url, { method: "POST", body });
             return r.status;
           }""", host.base + "/soal.php")
    check("dan kiriman tanpa token yang benar ditolak", forged2, 403)

    print("\n=== yang tidak boleh terjadi ===")
    forged = admin.evaluate(
        """async url => {
             const body = new URLSearchParams({ do: "add", name: "Penyusup",
               email: "susup@example.test", password: "password-panjang-sekali",
               role: "admin", joined: "2026-09-28", csrf: "salah" });
             const r = await fetch(url, { method: "POST", body });
             return r.status;
           }""", host.base + "/admin.php")
    check("form tanpa token yang benar ditolak", forged, 403)
    admin.reload()
    admin.wait_for_load_state("networkidle")
    check("dan penyusupnya tidak masuk daftar",
          admin.inner_text("body"), lambda s: "Penyusup" not in s)

    print("\n=== pemasangan lama yang kurang satu tabel ===")
    # Gejala yang benar-benar dilihat orangnya: halaman 500 kosong. Diuji dari sisi
    # browser, bukan cuma dari fungsinya, karena yang kosong itulah yang tidak bisa
    # ditindaklanjuti siapa pun.
    host.php("-r", 'require "lib/bootstrap.php"; db()->exec("DROP TABLE settings");')
    admin.goto(host.base + "/index.php")
    admin.wait_for_load_state("networkidle")
    admin.wait_for_timeout(700)
    # Pelaporan harian tidak boleh ikut mati karena satu tabel setelan: setelan punya
    # bawaan, dan delapan orang tidak boleh berhenti bekerja karena satu langkah
    # pemasangan yang terlewat.
    check("aplikasinya tetap jalan dengan pertanyaan bawaan",
          admin.inner_text("body"), lambda s: "Modul" in s or "Berapa banyak" in s)

    admin.goto(host.base + "/soal.php")
    admin.wait_for_load_state("networkidle")
    soal = admin.inner_text("body")
    check("halaman pertanyaan mengatakan kenapa belum bisa menyimpan",
          soal, lambda s: "Belum bisa disimpan" in s and "settings" in s)
    check("beserta jalan keluarnya", soal,
          lambda s: "install.sql" in s and "Aman diulang" in s)
    check("dan tombol simpannya dimatikan, bukan dibiarkan menipu",
          admin.is_disabled("button:has-text('Simpan')"))
    # Tombol yang dimatikan cuma menghalangi klik. Yang mengirim lewat jalan lain harus
    # tetap mendapat halaman, bukan 500 — itu justru yang sedang dibetulkan di sini.
    paksa = admin.evaluate(
        """async ([url, csrf]) => {
             const body = new URLSearchParams({ csrf, threshold_days: "7" });
             const r = await fetch(url, { method: "POST", body });
             return r.status;
           }""", [host.base + "/soal.php",
                  admin.get_attribute("input[name=csrf]", "value")])
    check("mengirim paksa pun tidak meledakkan halamannya", paksa, 200)

    admin.goto(host.base + "/cek.php")
    admin.wait_for_load_state("networkidle")
    check("halaman cek hosting ikut menyebutkannya",
          admin.inner_text("body"), lambda s: "kurang: settings" in s)

    # Dan import ulang memang memulihkannya, tanpa menyentuh data yang sudah ada.
    host.php("-r", REIMPORT)
    admin.goto(host.base + "/admin.php")
    admin.wait_for_load_state("networkidle")
    check("import ulang memulihkannya", admin.inner_text("h1"), "Tim")
    check("dan orang-orangnya masih ada", admin.inner_text("body"),
          lambda s: "oki@example.test" in s)

    other.close()
    pctx.close()
    ctx.close()
    browser.close()

sys.exit(report("admin & pemasangan"))
