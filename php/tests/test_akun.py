"""Akun sendiri, izin, dan jalan pulang kalau lupa password — lewat halamannya.

Tiga hal yang sampai kemarin tidak bisa dikerjakan siapa pun di dalam aplikasi ini:
mengganti passwordnya sendiri, menghapus tanda "tidak lapor" yang tidak adil, dan
mendapat link masuk tanpa menunggu admin membuka laptop. Yang diuji di sini bukan
fungsinya — itu ada di test_repo.php — tapi halamannya: tombol yang ada, kiriman yang
ditolak, dan apa yang berubah di layar orang lain sesudahnya.

    MESSI_TEST_SOCKET=/var/run/mysqld/mysqld.sock python3 php/tests/test_akun.py
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

from livehost import PASSWORD, Host, sign_in

BARU = "rahasia-baru-sekali"

# Email dinyalakan di pemasangan tes ini. Tidak ada MTA di sini, jadi pengirimannya pasti
# gagal — dan itu justru jalur yang paling penting diperiksa: yang gagal tidak boleh
# meninggalkan siapa pun tanpa jalan pulang, dan tidak boleh membocorkan siapa yang
# terdaftar lewat jawaban yang berbeda.
with Host("messi_akun", extra={"mail_from": "fura@example.test"}) as host, sync_playwright() as p:
    browser = launch(p)
    ctx = browser.new_context(viewport={"width": 1100, "height": 900})

    def db(php: str) -> str:
        r = host.php("-r", "require 'lib/bootstrap.php';" + php)
        return (r.stdout or r.stderr).strip()

    print("\n=== halaman akun ada pintunya ===")
    pg = sign_in(ctx, host.base, "nicho@example.test", PASSWORD, results=results, enter=None)
    pg.click("#userMenu summary") if pg.query_selector("#userMenu summary") else None
    check("menu pemain punya pintu ke Akun",
          pg.eval_on_selector_all("a[href='akun.php']", "e=>e.length"), lambda n: n >= 1)
    # Izin bukan urusan pemain: tombolnya tidak ada, dan halamannya pun tidak terbuka.
    check("tapi tidak punya pintu ke Izin",
          pg.eval_on_selector_all("a[href='izin.php']", "e=>e.length"), 0)
    check("dan halaman izin tertutup untuknya",
          pg.evaluate("""async () => (await fetch("izin.php")).status"""), 403)

    print("\n=== ganti nama ===")
    pg.goto(host.base + "/akun.php")
    pg.wait_for_load_state("networkidle")
    pg.fill("#nm", "Nicho Pratama")
    pg.click("button:has-text('Simpan nama')")
    pg.wait_for_load_state("networkidle")
    check("namanya tersimpan", pg.inner_text(".note.ok"), lambda s: "Nama diubah" in s)
    check("dan langsung terbaca di menunya, bukan nanti setelah masuk lagi",
          pg.get_attribute("#userMenu summary", "title"), "Nicho Pratama")
    check("nama kosong ditolak, bukan diterima jadi nama kosong",
          pg.evaluate("""async ([csrf]) => {
                const body = new URLSearchParams({ do: "nama", csrf, name: "   " });
                const r = await fetch("akun.php", { method: "POST", body });
                return (await r.text()).includes("Namanya belum diisi");
              }""", [pg.get_attribute("input[name=csrf]", "value")]), True)

    print("\n=== ganti password ===")
    pg.goto(host.base + "/akun.php")
    pg.wait_for_load_state("networkidle")
    pg.fill("#pc", "bukan-passwordnya")
    pg.fill("#p1", BARU)
    pg.fill("#p2", BARU)
    pg.click("button:has-text('Ganti password')")
    pg.wait_for_load_state("networkidle")
    check("password sekarang yang salah ditolak",
          pg.inner_text(".note.bad"), lambda s: "Password sekarang salah" in s)

    pg.fill("#pc", PASSWORD)
    pg.fill("#p1", BARU)
    pg.fill("#p2", "beda-sendiri-nih")
    pg.click("button:has-text('Ganti password')")
    pg.wait_for_load_state("networkidle")
    check("dua kotak yang berbeda ditolak",
          pg.inner_text(".note.bad"), lambda s: "belum sama" in s)

    # Sesi di perangkat lain, dibuat sebelum passwordnya berganti — HP yang ketinggalan
    # di meja, laptop kantor yang masih terbuka.
    hp = browser.new_context(viewport={"width": 420, "height": 900})
    hpPg = sign_in(hp, host.base, "nicho@example.test", PASSWORD, results=results, enter=None)
    check("perangkat kedua ikut masuk", hpPg.url, lambda u: "login.php" not in u)

    pg.goto(host.base + "/akun.php")
    pg.wait_for_load_state("networkidle")
    check("halamannya menghitung perangkat lain itu",
          pg.inner_text("body"), lambda s: "1 perangkat lain" in s)
    pg.fill("#pc", PASSWORD)
    pg.fill("#p1", BARU)
    pg.fill("#p2", BARU)
    pg.click("button:has-text('Ganti password')")
    pg.wait_for_load_state("networkidle")
    check("yang benar diterima", pg.inner_text(".note.ok"), lambda s: "Password diganti" in s)
    check("dan yang menggantinya tetap masuk di sini",
          pg.inner_text("body"), lambda s: "Akun" in s and "Masuk" not in s[:200])

    # Password baru yang tidak menutup pintu lama bukan password baru.
    hpPg.goto(host.base + "/akun.php")
    hpPg.wait_for_load_state("networkidle")
    check("perangkat lain dikeluarkan, bukan dibiarkan 30 hari lagi",
          hpPg.url, lambda u: "login.php" in u)
    hp.close()

    lagi = browser.new_context()
    masuk = sign_in(lagi, host.base, "nicho@example.test", PASSWORD, results=results, enter=None)
    check("password lamanya mati", masuk.url, lambda u: "login.php" in u)
    masuk.close()
    lagi.close()

    print("\n=== izin: admin menandai, dan rekapnya berubah ===")
    bos = browser.new_context(viewport={"width": 1100, "height": 900})
    lia = sign_in(bos, host.base, "lead@example.test", PASSWORD, results=results, enter=None)
    # Untuk sekarang leader belum boleh menandai izin: izin yang bisa diberikan atasan
    # langsung paling cepat berubah jadi "tolong hapus merah saya", dan satu pintu yang
    # jelas lebih mudah dipercaya daripada dua yang batasnya kabur.
    check("leader belum boleh membukanya",
          lia.evaluate("""async () => (await fetch("izin.php")).status"""), 403)

    db("q(\"UPDATE users SET role='owner' WHERE email='lead@example.test'\");")
    lia.goto(host.base + "/izin.php")
    lia.wait_for_load_state("networkidle")
    check("admin boleh", lia.inner_text("h1"), "Izin")

    # Hari kerja terakhir yang sudah lewat — bukan "kemarin", yang di hari Senin adalah
    # hari Minggu dan tidak pernah ditandai apa pun. Tes yang pemeriksaannya berbeda
    # tergantung hari apa ia dijalankan adalah tes yang tidak memeriksa apa-apa di hari Senin.
    lalu = db("echo messi_past_workdays(messi_add_days(Clock::today(), -1), 1, '2000-01-01')[0];")
    rioId = db("echo q1(\"SELECT id FROM users WHERE email='rio@example.test'\")['id'];")
    lia.select_option("#id", rioId)
    lia.fill("#d1", lalu)
    lia.fill("#d2", lalu)
    lia.fill("#nt", "sakit")
    lia.click("button:has-text('Tandai izin')")
    lia.wait_for_load_state("networkidle")
    check("harinya ditandai", lia.inner_text(".note.ok"), lambda s: "1 hari ditandai izin" in s)
    check("dan tercatat dengan keterangannya",
          lia.inner_text("table.izin"), lambda s: "Rio" in s and "sakit" in s)
    check("beserta siapa yang menandainya",
          lia.inner_text("table.izin"), lambda s: "ditandai Lia" in s)
    check("dan halamannya mengatakan leader harus menitipkannya ke admin",
          lia.inner_text("body"), lambda s: "Leader belum bisa menandai" in s)

    # Akhir pekan memang tidak pernah dihitung tidak lapor, jadi menandainya cuma menambah
    # baris yang tidak berarti apa-apa — dan itu dikatakan, bukan dianggap berhasil.
    sabtu = db("""for ($i = 1; $i <= 7; $i++) { $d = messi_add_days(Clock::today(), $i);
                    if (!messi_is_workday($d)) { echo $d; break; } }""")
    lia.select_option("#id", rioId)
    lia.fill("#d1", sabtu)
    lia.fill("#d2", sabtu)
    lia.click("button:has-text('Tandai izin')")
    lia.wait_for_load_state("networkidle")
    check("akhir pekan dikatakan apa adanya, bukan diam-diam dianggap berhasil",
          lia.inner_text(".note.ok"), lambda s: "Tidak ada hari yang berubah" in s)

    # Inilah seluruh gunanya: tanda merah yang tidak adil hilang dari rekap.
    lia.goto(host.base + "/index.php")
    lia.wait_for_load_state("networkidle")
    lia.wait_for_timeout(400)
    lia.click("[data-rekap]") if lia.query_selector("[data-rekap]") else None
    lia.wait_for_timeout(300)
    # Harinya dipindah lewat tombol "‹ hari sebelumnya", yang memang satu-satunya cara
    # orangnya melakukannya — bukan lewat kotak tanggal yang tidak ada di layar ini.
    for _ in range(5):
        if lia.query_selector(f"[data-day='{lalu}']") is None:
            break
        lia.click(f"[data-day='{lalu}']")
        lia.wait_for_timeout(300)
        break
    barisRio = lia.evaluate("""() => {
          const cards = [...document.querySelectorAll(".card")];
          const rio = cards.find(c => c.textContent.includes("Rio"));
          return rio ? rio.textContent : "";
        }""")
    check("di rekap hari itu Rio tertulis izin", barisRio, lambda s: "izin" in s)
    check("dan tidak lagi tertulis tidak lapor untuk dia",
          barisRio, lambda s: "tidak lapor" not in s)

    # Dibatalkan, dan tanda merahnya kembali — pembatalan yang tidak mengembalikan apa pun
    # adalah tombol yang tidak jujur.
    lia.goto(host.base + "/izin.php")
    lia.wait_for_load_state("networkidle")
    lia.on("dialog", lambda d: d.accept())
    lia.click("table.izin button:has-text('Batalkan')")
    lia.wait_for_load_state("networkidle")
    check("dibatalkan", lia.inner_text(".note.ok"), lambda s: "dibatalkan izinnya" in s)
    check("dan harinya kembali tercatat tidak lapor",
          db(f"echo q1(\"SELECT status FROM cycles WHERE user_id={rioId} AND day=\'{lalu}\'\")['status'];"),
          "missed")

    bos.close()

    print("\n=== lupa password lewat email ===")
    # Jawabannya harus sama persis untuk alamat yang terdaftar dan yang tidak. Halaman
    # yang menjawab berbeda adalah daftar nama siapa saja yang bekerja di sini.
    def lupa(email: str) -> str:
        pgx = ctx.new_page()
        pgx.goto(host.base + "/lupa.php")
        pgx.fill("#email", email)
        pgx.click("button[type=submit]")
        pgx.wait_for_load_state("networkidle")
        teks = pgx.inner_text(".card")
        pgx.close()
        return teks

    sebelum = int(db("echo (int) q1('SELECT COUNT(*) n FROM login_tokens')['n'];"))
    ada = lupa("lead@example.test")
    tidak = lupa("hantu@example.test")
    check("jawaban untuk yang terdaftar dan yang tidak sama persis", ada, tidak)
    check("dan bunyinya tentang email yang dikirim", ada, lambda s: "dikirim ke sana" in s)
    sesudah = int(db("echo (int) q1('SELECT COUNT(*) n FROM login_tokens')['n'];"))
    check("yang terdaftar dibuatkan satu link masuk, yang tidak terdaftar nol",
          sesudah - sebelum, 1)
    # Tidak ada MTA di sini, jadi pengirimannya gagal — dan kegagalan yang tidak
    # meninggalkan jejak adalah kegagalan yang tidak bisa ditelusuri.
    check("pengiriman yang gagal tercatat, bukan hilang diam-diam",
          db("echo (int) q1(\"SELECT COUNT(*) n FROM job_log WHERE kind='mail_fail'\")['n'];"),
          lambda s: int(s) >= 1)
    check("dan permintaannya tetap dititipkan ke admin sebagai cadangan",
          db("echo (int) q1('SELECT COUNT(*) n FROM users WHERE reset_asked_at IS NOT NULL')['n'];"),
          lambda s: int(s) >= 1)
    check("halaman cek menyebut emailnya menyala",
          db("echo cfg('mail_from');"), "fura@example.test")

    print("\n=== kepala halaman: beda untuk tiap peran ===")

    def kepala(pgx):
        """Baris menu di kepala, dan TINGGINYA — bukan cuma atributnya.

        Pernah terjadi: atribut `hidden` kalah dari `display:flex` di host PHP, dan yang
        terlihat orangnya adalah baris yang menurut tesnya tersembunyi. Jadi yang diukur
        tinggi sungguhan, bukan niat.
        """
        return pgx.evaluate("""() => {
              const n = document.querySelector(".hnav");
              if (!n) return { tinggi: 0, isi: [], kini: [] };
              return { tinggi: n.getBoundingClientRect().height,
                       isi: [...n.querySelectorAll("a")].map(a => a.textContent.trim()),
                       kini: [...n.querySelectorAll("a.on")].map(a => a.textContent.trim()) };
            }""")

    def lembar(pgx):
        """Isi lembar di bawah avatar — dibaca dari DOM, bukan dari layar, karena
        <details> yang tertutup tidak punya teks yang terlihat."""
        return pgx.evaluate("""() => {
              const s = document.querySelector("#usheet, .menu .sheet");
              return s ? s.textContent.replace(/\s+/g, " ").trim() : "";
            }""")

    # Pemain: satu pintu saja, jadi barisnya tidak digambar sama sekali.
    pg.goto(host.base + "/index.php")
    pg.wait_for_load_state("networkidle")
    pg.wait_for_timeout(500)
    pemain = kepala(pg)
    check("pemain tidak dapat baris menu di kepala", pemain["tinggi"], 0)
    check("tapi tetap punya Akun dan Keluar di bawah avatarnya",
          lembar(pg), lambda s: "Akun" in s and "Keluar" in s)
    check("dan tidak punya Kelola maupun Izin di situ",
          lembar(pg), lambda s: "Kelola" not in s and "Izin" not in s)
    check("peranmu disebutkan, karena \"kenapa saya tidak punya tombol itu\" adalah "
          "pertanyaan tentang peran", lembar(pg), lambda s: "Pemain" in s)

    # Leader: peranya terbaca, tapi pintunya memang belum ada di luar aplikasi — izin
    # sekarang milik admin. Jadi kepalanya sama bersihnya dengan kepala pemain, dan yang
    # membedakannya ada di dalam aplikasinya: tab Tim.
    db("q(\"UPDATE users SET role='leader' WHERE email='rio@example.test'\");")
    ctxRio = browser.new_context(viewport={"width": 1100, "height": 900})
    pgRio = sign_in(ctxRio, host.base, "rio@example.test", PASSWORD, results=results, enter=None)
    pgRio.wait_for_timeout(500)
    check("leader belum punya pintu di luar aplikasinya", kepala(pgRio)["tinggi"], 0)
    check("tapi peranmu tetap terbaca di bawah avatar",
          lembar(pgRio), lambda s: "Leader" in s)
    check("dan Izin memang tidak ditawarkan kepadanya",
          lembar(pgRio), lambda s: "Izin" not in s)
    ctxRio.close()

    # Owner: tiga pintu, dan di halaman PHP pun kepalanya sama bentuknya.
    ctxLead = browser.new_context(viewport={"width": 1100, "height": 900})
    pgLead = sign_in(ctxLead, host.base, "lead@example.test", PASSWORD, results=results,
                     enter=None)
    pgLead.wait_for_timeout(500)
    ownerHead = kepala(pgLead)
    check("owner dapat barisnya, dan barisnya benar-benar terlihat",
          ownerHead["tinggi"], lambda t: t > 10)
    check("isinya Laporan, Izin dan Kelola", ownerHead["isi"], ["Laporan", "Izin", "Kelola"])
    check("halaman yang sedang dibuka ditandai", ownerHead["kini"], ["Laporan"])
    check("dan peranmu terbaca Owner", lembar(pgLead), lambda s: "Owner" in s)

    # Di telepon barisnya tidak muat. Disembunyikan — dan isinya yang sama tetap ada di
    # bawah avatar, kalau tidak, admin yang memakai telepon kehilangan halaman Izin.
    hpLead = browser.new_context(viewport={"width": 420, "height": 900})
    pgHp = sign_in(hpLead, host.base, "lead@example.test", PASSWORD, results=results, enter=None)
    pgHp.wait_for_timeout(500)
    check("di telepon barisnya disembunyikan", kepala(pgHp)["tinggi"], 0)
    check("tapi Izin tetap bisa dicapai dari bawah avatarnya",
          lembar(pgHp), lambda s: "Izin" in s)
    hpLead.close()

    pgLead.goto(host.base + "/kelola.php")
    pgLead.wait_for_load_state("networkidle")
    phpHead = kepala(pgLead)
    check("halaman PHP memakai kepala yang sama, dan barisnya terlihat",
          phpHead["tinggi"], lambda t: t > 10)
    check("isinya sama persis dengan yang di aplikasinya", phpHead["isi"], ownerHead["isi"])
    check("dan yang ditandai halaman yang sedang dibuka", phpHead["kini"], ["Kelola"])

    pgLead.goto(host.base + "/izin.php")
    pgLead.wait_for_load_state("networkidle")
    check("pindah halaman memindahkan tandanya", kepala(pgLead)["kini"], ["Izin"])
    ctxLead.close()

    # Halaman PHP milik pemain pun tidak boleh menumbuhkan baris yang tidak dia punya.
    pg.goto(host.base + "/akun.php")
    pg.wait_for_load_state("networkidle")
    check("di halaman PHP pemain tetap tanpa baris menu", kepala(pg)["tinggi"], 0)

    ctx.close()

sys.exit(report("akun, izin, dan jalan pulang"))
