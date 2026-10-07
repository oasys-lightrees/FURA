"""Getting a squad onto the system, and off it again.

The parts nobody thinks to test until the day they are needed: the first account on a
fresh install, adding somebody, changing what they are allowed to see, resetting a
password, and handing somebody a one-time link when they are locked out.

    MESSI_TEST_SOCKET=/var/run/mysqld/mysqld.sock python3 php/tests/test_admin.py
"""

from __future__ import annotations

import json
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
sys.path.insert(0, str(HERE.parent.parent / "web" / "tests"))

try:
    from harness import check, launch, report, results, sync_playwright
except ImportError as exc:                                  # pragma: no cover
    print(f"Playwright tidak tersedia ({exc}) — dilewati.")
    sys.exit(0)

from livehost import PASSWORD, Host, accept_invite, sign_in

NEW_PASSWORD = "password-baru-yang-panjang"
PASSWORD2 = "password-ketiga-yang-panjang"

# install.sql dijalankan ulang persis seperti phpMyAdmin menjalankannya: baris komentar
# dibuang dulu, baru dipecah per titik koma.
REIMPORT = """require 'lib/bootstrap.php';
$sql = preg_replace('/^--.*$/m', '', file_get_contents('install.sql'));
foreach (explode(';', $sql) as $one) {
    if (trim($one) !== '') { db()->exec($one); }
}"""


def status(pg, path, base):
    return pg.evaluate("async p => (await fetch(p)).status", base + path)


class Space(BaseHTTPRequestHandler):
    """Sebuah space Google Chat, sejauh yang dilihat kode ini: satu alamat yang menerima
    POST dan menjawab 200.

    Dijalankan di proses tes, bukan di host PHP-nya: host tes itu `php -S`, yang melayani
    satu permintaan pada satu waktu — mengarahkan webhook ke dirinya sendiri membuatnya
    menunggu dirinya sendiri sampai waktunya habis.
    """

    diterima: list[str] = []

    def do_POST(self):                                      # noqa: N802  (nama dari pustakanya)
        n = int(self.headers.get("Content-Length") or 0)
        Space.diterima.append(self.rfile.read(n).decode("utf-8", "replace"))
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(b"{}")

    def log_message(self, *a):                              # tanpa bising di keluaran tes
        pass


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
    # Langsung masuk, bukan dilempar ke halaman masuk: orangnya baru saja membuktikan dia
    # memegang pemasangan yang masih kosong ini, dan mengetik ulang password yang dibuatnya
    # tiga detik lalu cuma menambah satu pintu tanpa menambah satu pun penjagaan.
    check("akun pertama dibuat lalu langsung masuk ke penyiapan",
          pg.url, lambda u: u.endswith("/kelola.php"))
    check("dan dituntun tiga langkah penyiapan", pg.inner_text("body"),
          lambda s: "Penyiapan" in s and "Undang orangnya" in s)
    check("sambil disebutkan mana yang sudah", pg.inner_text("body"),
          lambda s: "Hubungkan Google Chat" in s)

    pg.goto(host.base + "/setup.php")
    check("setup menolak jalan untuk kedua kalinya",
          pg.inner_text("body"), lambda s: "Sudah ada akun" in s)

    # Setup sudah memasukkannya, jadi pintu masuknya diuji dari keadaan keluar.
    pg.goto(host.base + "/api/logout.php")
    pg.wait_for_load_state("networkidle")

    print("\n=== masuk sebagai admin ===")
    admin = sign_in(ctx, host.base, "lead@example.test", results=results)
    check("admin masuk ke aplikasinya", admin.inner_text("h1"),
          lambda s: "Berapa banyak" in s or "Sudah terkirim" in s)
    # Pintunya pindah dari kaki halaman ke menu di bawah avatar — kaki halaman itu tempat
    # orang mencari hal yang paling tidak penting.
    admin.click("#avatar")
    check("admin melihat pintu ke halaman kelola di menunya",
          admin.inner_text("#usheet"), lambda s: "Kelola" in s)
    admin.keyboard.press("Escape")
    admin.goto(host.base + "/kelola.php")
    check("dan halaman kelola menampung keempatnya", admin.inner_text("body"),
          lambda s: "Orang & tim" in s and "Pertanyaan" in s and "Cek sistem" in s)

    admin.goto(host.base + "/admin.php")
    check("halaman orang & tim terbuka", admin.inner_text("h1"), "Orang & tim")
    check("dirinya sendiri tercatat", admin.inner_text("body"), lambda s: "lead@example.test" in s)
    # Akun pertama adalah owner: harus ada satu yang bisa mengangkat admin.
    check("akun pertama jadi owner, dan perannya sendiri tidak bisa diturunkan sendiri",
          admin.inner_text("body"), lambda s: "Owner (kamu)" in s)
    check("dan sudah punya satu tim", admin.inner_text("body"), lambda s: "1 orang" in s)

    print("\n=== menambah orang lewat undangan ===")
    admin.fill("#n", "Nicho")
    admin.fill("#e", "nicho@example.test")
    admin.select_option("#r", "player")
    check("tidak ada kotak password untuk diketik admin",
          admin.query_selector("#p") is None)
    admin.click("button:text-is('Undang')")
    admin.wait_for_load_state("networkidle")
    check("orangnya masuk daftar", admin.inner_text("body"), lambda s: "Nicho ditambahkan" in s)
    undangan = admin.inner_text(".links code").strip()
    check("dan yang keluar adalah link undangan", undangan,
          lambda s: s.startswith(host.base) and "undang.php?t=" in s)
    check("admin diingatkan mengirimnya japri",
          admin.locator(".note.ok", has_text="japri").inner_text(),
          lambda s: "japri" in s)
    check("selama belum diterima, ditandai di daftarnya",
          admin.inner_text("body"), lambda s: "belum terima undangan" in s)

    admin.fill("#n", "Nicho Lagi")
    admin.fill("#e", "nicho@example.test")
    admin.click("button:text-is('Undang')")
    admin.wait_for_load_state("networkidle")
    check("email yang sama ditolak", admin.inner_text(".bad"), lambda s: "sudah terdaftar" in s)

    print("\n=== yang diundang membuat passwordnya sendiri ===")
    other = browser.new_context(viewport={"width": 400, "height": 900})
    inv = other.new_page()
    inv.goto(undangan)
    inv.wait_for_load_state("networkidle")
    check("halamannya menyapa orangnya dengan namanya",
          inv.inner_text("h1"), lambda s: "Nicho" in s)
    check("dan menyebutkan bahwa yang mengundang tidak akan tahu passwordnya",
          inv.inner_text("body"), lambda s: "tidak akan tahu" in s)

    # Password pendek dan dua kotak yang berbeda ditolak di server, bukan cuma di browser.
    tolak = inv.evaluate(
        """async ([url, t]) => {
             const out = [];
             for (const [a, b] of [["123", "123"], ["password-panjang", "beda-sekali"]]) {
               const body = new URLSearchParams({ t, password: a, password2: b });
               const r = await fetch(url, { method: "POST", body });
               out.push(await r.text());
             }
             return out.map(x => x.includes("minimal 10") || x.includes("belum sama"));
           }""", [undangan.split("?")[0], undangan.split("t=")[1]])
    check("password pendek dan ketikan yang tidak sama ditolak di server", tolak, [True, True])

    inv.fill("#password", NEW_PASSWORD)
    inv.fill("#password2", NEW_PASSWORD)
    inv.click("button[type=submit]")
    inv.wait_for_load_state("networkidle")
    inv.wait_for_timeout(600)
    check("menerima undangan langsung memasukkannya ke aplikasi",
          inv.inner_text("body"), lambda s: "Modul" in s or "Berapa banyak" in s)

    gone = other.new_page()
    gone.goto(undangan)
    gone.wait_for_load_state("networkidle")
    check("dan undangannya tidak bisa dipakai kedua kalinya",
          gone.inner_text("h1"), lambda s: "tidak berlaku" in s)
    gone.close()

    admin.reload()
    admin.wait_for_load_state("networkidle")
    check("di daftar admin tandanya hilang",
          admin.inner_text("body"), lambda s: "belum terima undangan" not in s)

    print("\n=== orang baru itu betul-betul bisa masuk ===")
    other.close()
    other = browser.new_context(viewport={"width": 400, "height": 900})
    nicho = sign_in(other, host.base, "nicho@example.test", NEW_PASSWORD, results=results)
    check("bisa masuk dengan password yang dia buat sendiri",
          nicho.inner_text("h1"), "Berapa banyak hari ini?")
    check("player tidak punya tab squad", nicho.eval_on_selector("#tabs", "e=>e.hidden"))
    check("dan tidak bisa membuka halaman squad", status(nicho, "/admin.php", host.base), 403)

    print("\n=== menaikkan jadi leader ===")
    admin.reload()
    admin.wait_for_load_state("networkidle")
    row = admin.locator("table.orang tr", has_text="nicho@example.test")
    row.locator("select[name=role]").select_option("leader")
    admin.wait_for_load_state("networkidle")
    check("perannya berubah", admin.inner_text(".ok"), lambda s: "Peran diubah" in s)

    nicho.reload()
    nicho.wait_for_load_state("networkidle")
    nicho.wait_for_timeout(600)
    check("leader baru langsung melihat tab squad", nicho.eval_on_selector("#tabs", "e=>!e.hidden"))
    check("tapi halaman admin tetap tertutup untuk leader",
          status(nicho, "/admin.php", host.base), 403)

    print("\n=== lupa password: link, bukan password yang diketik admin ===")
    admin.reload()
    admin.wait_for_load_state("networkidle")
    row = admin.locator("table.orang tr", has_text="nicho@example.test")
    check("admin tidak punya kotak untuk mengetik password orang lain",
          row.locator("input[name=password]").count(), 0)
    row.locator("button:has-text('Link buat password baru')").click()
    admin.wait_for_load_state("networkidle")
    reset = admin.inner_text(".links code").strip()
    check("yang keluar adalah link, bukan password", reset,
          lambda s: s.startswith(host.base) and "undang.php?t=" in s)

    stale = browser.new_context(viewport={"width": 400, "height": 900})
    rp = stale.new_page()
    rp.goto(reset)
    rp.wait_for_load_state("networkidle")
    rp.fill("#password", PASSWORD2)
    rp.fill("#password2", PASSWORD2)
    rp.click("button[type=submit]")
    rp.wait_for_load_state("networkidle")
    rp.wait_for_timeout(600)
    check("orangnya membuat password barunya sendiri lalu langsung masuk",
          rp.inner_text("body"), lambda s: "Modul" in s or "Berapa banyak" in s)
    stale.close()

    stale = browser.new_context(viewport={"width": 400, "height": 900})
    old = sign_in(stale, host.base, "nicho@example.test", NEW_PASSWORD, results=results)
    check("password lama tidak berlaku lagi", old.inner_text(".note.bad"), lambda s: "salah" in s)
    fresh = sign_in(stale, host.base, "nicho@example.test", PASSWORD2, results=results)
    check("password baru berlaku", fresh.inner_text("h1"), "Berapa banyak hari ini?")
    stale.close()

    print("\n=== link masuk sekali pakai ===")
    admin.reload()
    admin.wait_for_load_state("networkidle")
    row = admin.locator("table.orang tr", has_text="nicho@example.test")
    row.locator("button:has-text('Link masuk')").click()
    admin.wait_for_load_state("networkidle")
    link = admin.inner_text(".links code").strip()
    check("linknya lengkap dan sekali pakai", link,
          lambda s: s.startswith(host.base) and "login.php?t=" in s)
    # The warning belongs next to the link itself, not in the notice above it.
    check("admin diingatkan mengirimnya japri, bukan ke space",
          admin.locator(".note.ok", has_text="japri").inner_text(),
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
          ag.inner_text(".note.bad"), lambda s: "sudah dipakai" in s or "kedaluwarsa" in s)
    again.close()

    print("\n=== menonaktifkan ===")
    admin.reload()
    admin.wait_for_load_state("networkidle")
    row = admin.locator("table.orang tr", has_text="nicho@example.test")
    row.locator("button:has-text('Nonaktifkan')").click()
    admin.wait_for_load_state("networkidle")
    gone = browser.new_context(viewport={"width": 400, "height": 900})
    blocked = sign_in(gone, host.base, "nicho@example.test", PASSWORD2, results=results)
    check("yang dinonaktifkan tidak bisa masuk lagi",
          blocked.inner_text(".note.bad"), lambda s: "salah" in s)
    gone.close()

    print("\n=== halaman cek hosting ===")
    check("cek.php tertutup untuk yang belum masuk",
          nicho.evaluate("""async () => { const r = await fetch("cek.php", {credentials:"omit"});
                                          return r.status; }"""), 403)
    admin.goto(host.base + "/cek.php")
    admin.wait_for_load_state("networkidle")
    body = admin.inner_text("body")
    check("admin melihat hasilnya", body, lambda s: "Cek sistem" in s)
    check("versi PHP diperiksa", body, lambda s: "Versi PHP" in s)
    check("tabelnya diperiksa", body, lambda s: "lengkap, 7 tabel" in s)
    check("base_url dicocokkan dengan alamat yang sedang dibuka",
          body, lambda s: "base_url" in s)
    check("perintah cron dibuatkan lengkap dengan path-nya",
          body, lambda s: "cron/tick.php" in s)
    # Yang paling sering ditanyakan saat pengingat tidak sampai: kapan terakhir benar-benar
    # terkirim, dan apa kesalahan terakhirnya. Dua-duanya harus ada di satu halaman.
    check("menyebut kapan pengingat terakhir benar-benar terkirim",
          body, lambda s: "Pengingat terakhir terkirim" in s)
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
    admin.select_option("#r", "player")
    admin.click("button:text-is('Undang')")
    admin.wait_for_load_state("networkidle")
    undanganOki = admin.inner_text(".links code").strip()
    pctx = browser.new_context(viewport={"width": 420, "height": 900})
    player = accept_invite(pctx, undanganOki, PASSWORD, results=results)
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

    print("\n=== owner, admin, dan batas di antaranya ===")
    # Owner mengangkat dua admin, lalu salah satunya mencoba mengubah yang lain. Kalau
    # bisa, "admin" berhenti berarti apa pun: satu admin tinggal menurunkan yang lain.
    admin.goto(host.base + "/admin.php")
    admin.wait_for_load_state("networkidle")
    for nama, surel in [("Adi", "adi@example.test"), ("Bela", "bela@example.test")]:
        admin.fill("#n", nama)
        admin.fill("#e", surel)
        admin.select_option("#r", "admin")
        admin.click("button:text-is('Undang')")
        admin.wait_for_load_state("networkidle")
        link = admin.inner_text(".links code").strip()
        kctx = browser.new_context(viewport={"width": 420, "height": 900})
        accept_invite(kctx, link, PASSWORD, results=results).close()
        kctx.close()
    check("owner bisa mengangkat admin", admin.inner_text("body"),
          lambda s: "adi@example.test" in s and "bela@example.test" in s)

    actx = browser.new_context(viewport={"width": 1000, "height": 900})
    adi = sign_in(actx, host.base, "adi@example.test", PASSWORD, results=results)
    adi.goto(host.base + "/admin.php")
    adi.wait_for_load_state("networkidle")
    isi = adi.inner_text("body")
    check("admin melihat owner dan sesama admin, tapi tanpa tombol", isi,
          lambda s: "Owner" in s and "Bela" in s)
    barisBela = adi.locator("table.orang tr", has_text="bela@example.test")
    check("baris sesama admin tidak punya pemilih peran",
          barisBela.locator("select[name=role]").count(), 0)
    check("dan tidak punya tombol undangan sama sekali",
          barisBela.locator("button").count(), 0)
    check("peran yang bisa diberikan admin tidak sampai ke admin",
          adi.inner_text("#r"), lambda s: "Admin" not in s and "Owner" not in s)
    # Dan bukan cuma hilang dari pilihannya: kiriman yang menyebut peran di luar
    # wewenangnya tidak boleh diterima diam-diam.
    adi.evaluate(
        """async ([url, csrf]) => {
             const body = new URLSearchParams({ do: "add", name: "Curi", csrf,
               email: "curi@example.test", role: "owner", team: "1", joined: "2026-09-28" });
             await fetch(url, { method: "POST", body });
           }""", [host.base + "/admin.php",
                  adi.get_attribute("input[name=csrf]", "value")])
    admin.reload()
    admin.wait_for_load_state("networkidle")
    check("admin yang mengirim peran di luar wewenangnya tidak jadi mengangkat siapa pun",
          admin.evaluate("""() => {
            const tr = [...document.querySelectorAll("tr")]
              .find(t => t.textContent.includes("curi@example.test"));
            return tr ? tr.querySelector("select[name=role]").value : "tidak ada"; }"""),
          "player")

    # Tombol yang hilang cuma menghalangi klik. Yang mengirim sendiri harus ikut ditolak,
    # jadi id-nya diambil dari halaman owner — di halaman admin memang tidak ada.
    idBela = admin.evaluate("""() => {
        const tr = [...document.querySelectorAll("tr")]
          .find(t => t.textContent.includes("bela@example.test"));
        return tr.querySelector("input[name=id]").value; }""")
    paksa = adi.evaluate(
        """async ([url, csrf, id]) => {
             const body = new URLSearchParams({ do: "role", id, role: "player", csrf });
             const r = await fetch(url, { method: "POST", body });
             return (await r.text()).includes("Tidak bisa mengubah baris itu");
           }""", [host.base + "/admin.php",
                  adi.get_attribute("input[name=csrf]", "value"), idBela])
    check("admin yang mengirim paksa ke baris sesama admin ditolak", paksa, True)

    # Dan bukan cuma kalimatnya: perannya memang tidak berubah.
    admin.reload()
    admin.wait_for_load_state("networkidle")
    check("perannya memang tidak berubah", admin.evaluate("""() => {
        const tr = [...document.querySelectorAll("tr")]
          .find(t => t.textContent.includes("bela@example.test"));
        return tr.querySelector("select[name=role]").value; }"""), "admin")

    # Begitu juga menonaktifkan: admin tidak boleh mematikan akun sesama admin.
    matikan = adi.evaluate(
        """async ([url, csrf, id]) => {
             const body = new URLSearchParams({ do: "active", id, active: "0", csrf });
             const r = await fetch(url, { method: "POST", body });
             return (await r.text()).includes("Tidak bisa mengubah baris itu");
           }""", [host.base + "/admin.php",
                  adi.get_attribute("input[name=csrf]", "value"), idBela])
    check("menonaktifkan sesama admin juga ditolak", matikan, True)

    # Owner boleh, karena memang wewenangnya.
    bolehOwner = admin.evaluate(
        """async ([url, csrf, id]) => {
             const body = new URLSearchParams({ do: "role", id, role: "leader", csrf });
             const r = await fetch(url, { method: "POST", body });
             return (await r.text()).includes("Peran diubah");
           }""", [host.base + "/admin.php",
                  admin.get_attribute("input[name=csrf]", "value"), idBela])
    check("owner boleh menurunkan admin", bolehOwner, True)
    actx.close()

    print("\n=== tim ===")
    admin.goto(host.base + "/admin.php")
    admin.wait_for_load_state("networkidle")
    admin.fill("input[placeholder='nama tim baru']", "HR")
    admin.click("button:has-text('Tambah tim')")
    admin.wait_for_load_state("networkidle")
    check("tim baru dibuat", admin.inner_text(".ok"), lambda s: "Tim ditambahkan" in s)
    admin.fill("input[placeholder='nama tim baru']", "HR")
    admin.click("button:has-text('Tambah tim')")
    admin.wait_for_load_state("networkidle")
    check("nama tim yang sama ditolak", admin.inner_text(".bad"), lambda s: "sudah ada" in s.lower())
    check("tiap tim punya tautan ke pertanyaannya sendiri",
          admin.eval_on_selector_all("a[href*='soal.php?team=']", "e=>e.length"), 2)

    admin.goto(host.base + "/soal.php")
    admin.wait_for_load_state("networkidle")
    check("halaman pertanyaan menyebut tim mana yang sedang diatur",
          admin.inner_text("body"), lambda s: "Pertanyaan milik tim" in s)
    check("dan webhook tim tidak pernah ditampilkan kembali",
          admin.input_value("input[name=chat_webhook]"), "")

    print("\n=== webhook diuji sekarang, bukan besok pagi ===")
    # Alamat yang salah tempel tidak memberi tanda apa pun sampai jam buka berikutnya, dan
    # yang terlihat besok cuma "botnya mati" — kabar buruk yang sampai ke orang yang sudah
    # lupa dari mana dia menyalin alamatnya.
    admin.fill("input[name=chat_webhook]", "http://127.0.0.1:1/tidak-ada")
    admin.click("button:has-text('Simpan lalu kirim pesan tes')")
    admin.wait_for_load_state("networkidle")
    check("alamat yang tidak bisa dihubungi dikatakan gagal seketika",
          admin.inner_text(".note.bad"), lambda s: "gagal terkirim" in s)
    check("beserta apa yang harus dikerjakan",
          admin.inner_text(".note.bad"), lambda s: "buat ulang webhook" in s)
    # Dan jalur berhasilnya, ke space tiruan yang betul-betul menjawab 200.
    space = ThreadingHTTPServer(("127.0.0.1", 0), Space)
    threading.Thread(target=space.serve_forever, daemon=True).start()
    admin.fill("input[name=chat_webhook]",
               f"http://127.0.0.1:{space.server_address[1]}/space")
    admin.click("button:has-text('Simpan lalu kirim pesan tes')")
    admin.wait_for_load_state("networkidle")
    check("alamat yang menjawab dikatakan terkirim",
          admin.locator(".note.ok", has_text="terkirim").inner_text(),
          lambda s: "terkirim" in s)
    # Bukan cuma kalimatnya: pesannya memang sampai ke alamat itu.
    check("dan pesannya memang sampai ke sana", Space.diterima,
          lambda d: len(d) == 1 and "Tes dari FURA" in d[0])
    space.shutdown()
    check("dan menyimpannya tidak membuat alamatnya ditampilkan kembali",
          admin.input_value("input[name=chat_webhook]"), "")

    print("\n=== undangan borongan ===")
    admin.goto(host.base + "/admin.php")
    admin.wait_for_load_state("networkidle")
    admin.fill("#daftar", "budi.santoso@example.test\n"
                          "Sari Wijaya <sari@example.test>\n"
                          "bukan-email\n")
    admin.click("button:has-text('Undang semuanya')")
    admin.wait_for_load_state("networkidle")
    check("dua undangan keluar sekaligus",
          admin.eval_on_selector_all("table.links code", "e=>e.length"), 2)
    tabel = admin.inner_text("table.links")
    check("nama ditebak dari depan tanda @ kalau tidak ditulis",
          tabel, lambda s: "Budi Santoso" in s)
    check("dan nama yang ditulis dipakai apa adanya", tabel, lambda s: "Sari Wijaya" in s)
    # Satu baris salah ketik tidak boleh membatalkan sembilan baris yang benar — kalau
    # begitu, seluruh tempelan harus diulang.
    check("baris yang salah dikutip sendiri, yang lain tetap jadi",
          admin.inner_text(".note.bad"), lambda s: "bukan-email" in s)
    check("tiap baris punya tombol salinnya, plus satu untuk semuanya",
          admin.eval_on_selector_all("[data-salin]", "e=>e.length"), 3)
    check("yang disalin sekaligus berisi kedua linknya",
          admin.get_attribute("#salinSemua", "data-salin"),
          lambda s: s.count("undang.php?t=") == 2 and "Budi Santoso" in s)

    # Bentuk tempelan yang paling mudah salah dibaca: satu baris penuh alamat dipisah
    # koma, seperti isi kolom "To". Dibaca sebagai satu orang, alamat kedua jadi *nama*
    # orang pertama dan sisanya hilang tanpa sepatah kata.
    admin.fill("#daftar", "tono@example.test, wati@example.test, joko@example.test")
    admin.click("button:has-text('Undang semuanya')")
    admin.wait_for_load_state("networkidle")
    check("satu baris berisi tiga alamat jadi tiga orang",
          admin.eval_on_selector_all("table.links code", "e=>e.length"), 3)
    check("dan tidak ada yang bernama alamat orang lain",
          admin.inner_text("table.links"),
          lambda s: "Tono" in s and "Wati" in s and "Joko" in s)

    print("\n=== lupa password punya jalan pulang ===")
    # Pelapor punya atasan di ruangan yang sama. Yang paling dirugikan oleh layar buntu
    # adalah orang yang tidak tahu harus ke siapa.
    lctx = browser.new_context(viewport={"width": 420, "height": 900})
    lp = lctx.new_page()
    lp.goto(host.base + "/login.php")
    check("halaman masuk menyebutkan jalan pulangnya",
          lp.inner_text("body"), lambda s: "Lupa password" in s)
    lp.click("a[href='lupa.php']")
    lp.wait_for_load_state("networkidle")
    lp.fill("#email", "oki@example.test")
    lp.click("button[type=submit]")
    lp.wait_for_load_state("networkidle")
    check("permintaannya dicatat", lp.inner_text(".note.ok"), lambda s: "dicatat" in s)
    check("tanpa menjanjikan email yang tidak akan datang",
          lp.inner_text("body"), lambda s: "inbox" not in s.lower())

    # Jawaban yang berbeda untuk email yang tidak terdaftar menjadikan halaman ini daftar
    # nama siapa saja yang bekerja di sini, dan siapa pun boleh membukanya.
    lp2 = lctx.new_page()
    lp2.goto(host.base + "/lupa.php")
    lp2.fill("#email", "tidak-pernah-ada@example.test")
    lp2.click("button[type=submit]")
    lp2.wait_for_load_state("networkidle")
    # Keberadaannya diperiksa dulu. Tanpa itu, jawaban yang berbeda bukan tertangkap
    # sebagai kalimat yang berbeda tapi sebagai penantian tiga puluh detik — dan yang
    # gagal dengan menggantung tidak memberi tahu apa yang berubah.
    check("jawabannya tetap berupa kabar baik", lp2.query_selector(".note.ok") is not None)
    check("dan jawabannya sama persis untuk email yang tidak terdaftar",
          lp2.inner_text(".note.ok") if lp2.query_selector(".note.ok") else "(tidak ada)",
          lp.inner_text(".note.ok"))
    lctx.close()

    admin.goto(host.base + "/admin.php")
    admin.wait_for_load_state("networkidle")
    check("admin melihat siapa yang menunggu, dengan namanya di atas",
          admin.inner_text(".note.warn"), lambda s: "Oki" in s and "lupa password" in s)
    check("dan barisnya ikut ditandai",
          admin.inner_text("table.orang"), lambda s: "minta link masuk" in s)
    baris = admin.locator("table.orang tr", has_text="oki@example.test")
    baris.locator("button:has-text('Link masuk')").click()
    admin.wait_for_load_state("networkidle")
    check("mengeluarkan linknya menjawab permintaannya",
          admin.inner_text("body"), lambda s: "minta link masuk" not in s)

    print("\n=== penyiapan yang selesai berhenti menyuruh ===")
    admin.goto(host.base + "/kelola.php")
    admin.wait_for_load_state("networkidle")
    kelola = admin.inner_text("body")
    check("tuntunan tiga langkahnya hilang sendiri", kelola,
          lambda s: "Penyiapan" not in s)
    check("yang tinggal adalah rangkuman keadaannya", kelola,
          lambda s: "orang aktif" in s and "Cron" in s and "Pengingat terakhir" in s)

    print("\n=== cron meninggalkan jejak, hidup maupun mati ===")
    # "Cron-nya mati lagi" adalah keluhan yang paling sering terdengar dan paling sulit
    # dibuktikan, karena dua keadaan yang sangat berbeda dulu terlihat sama: hari sepi
    # yang tidak mengerjakan apa-apa, dan proses yang mati sebelum sempat mencatat apa pun.
    jalan = host.php("cron/tick.php")
    check("dijalankan dari baris perintah berhasil", jalan.returncode, 0)
    denyut = host.php("-r", """require 'lib/bootstrap.php';
        $r = q1("SELECT COUNT(*) n FROM job_log WHERE kind = 'tick'");
        echo (string) $r['n'];""").stdout.strip()
    check("meninggalkan satu denyut walau tidak ada yang dikerjakan", denyut, "1")

    host.php("cron/tick.php")
    denyut2 = host.php("-r", """require 'lib/bootstrap.php';
        $r = q1("SELECT COUNT(*) n FROM job_log WHERE kind = 'tick'");
        echo (string) $r['n'];""").stdout.strip()
    check("tiap kali dijalankan menambah denyutnya", denyut2, "2")

    admin.goto(host.base + "/cek.php")
    admin.wait_for_load_state("networkidle")
    check("halaman cek membaca denyut itu, bukan baris terakhir apa pun",
          admin.inner_text("body"), lambda s: '"tick"' in s)

    # Dan kalau mati di tengah jalan, kematiannya ikut tercatat — justru itu yang selama
    # ini hilang, karena fatal error tidak sempat menulis apa pun tentang dirinya.
    rusak = host.php("-r", """require 'lib/bootstrap.php';
        q('INSERT INTO job_log (kind, detail, ran_at) VALUES (?,?,?)',
          ['tick_fatal', 'Uncaught Error: pura-pura meledak @ tick.php:1', '2026-10-07 02:00:00']);
        echo 'ok';""").stdout.strip()
    check("baris kematian bisa dicatat", rusak, "ok")
    admin.goto(host.base + "/cek.php")
    admin.wait_for_load_state("networkidle")
    check("dan halaman cek menampilkannya sebagai kesalahan terakhir",
          admin.inner_text("body"), lambda s: "pura-pura meledak" in s)
    # Kesalahan itu baris terbaru di tabelnya, tapi bukan tanda cron-nya jalan. Dua
    # pertanyaan yang berbeda — "kapan terakhir jalan" dan "apa yang terakhir salah" —
    # harus dijawab oleh dua baris yang berbeda.
    check("tapi 'cron terakhir jalan' tetap membaca denyutnya",
          admin.inner_text("body"),
          lambda s: s.split("Cron terakhir jalan")[1].split("Pengingat")[0].count('"tick"') == 1)

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
    check("import ulang memulihkannya", admin.inner_text("h1"), "Orang & tim")
    check("dan orang-orangnya masih ada", admin.inner_text("body"),
          lambda s: "oki@example.test" in s)

    other.close()
    pctx.close()
    ctx.close()
    browser.close()

sys.exit(report("admin & pemasangan"))
