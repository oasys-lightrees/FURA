"""Katalog modul, penjelasan MESSI, dan pertanyaan yang bisa diubah admin.

Tiga hal yang semuanya tentang satu pertanyaan: apa yang dilihat orang sebelum dia
mulai mengisi, dan apakah yang dilihatnya memang yang disetel timnya.
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from harness import (SHOTS, check, context, launch, new_page, results, store_of,
                     stored, sync_playwright, txt)

AT = "2026-09-30T04:00:00Z"          # Rabu 11:00 WIB
SABTU = "2026-10-03T04:00:00Z"       # Sabtu
PAGI = "2026-09-30T00:30:00Z"        # Rabu 07:30 WIB, belum dibuka

with sync_playwright() as p:
    b = launch(p)

    print("\n=== KATALOG MODUL ===")
    pg = new_page(context(b), "u_nicho", at=AT, enter=None)
    check("yang pertama terlihat adalah daftar modul, bukan laporannya",
          txt(pg, "h1"), "Modul")
    check("kepalanya FURA, bukan nama modulnya", txt(pg, ".logo .brand"), "FURA")
    check("dengan kepanjangannya", txt(pg, ".logo .tagline"),
          lambda s: s.lower() == "follow up report automation")
    check("tidak ada tombol kirim di katalog", pg.eval_on_selector("#bar", "e=>e.hidden"))
    check("baris modul belum muncul", pg.eval_on_selector("#modbar", "e=>e.hidden"))

    body = pg.inner_text("body")
    check("MESSI ada di daftar", body, lambda s: "MESSI" in s and "Messenger Screening" in s)
    check("dan ditandai aktif", txt(pg, "[data-mod=messi] .pill"), "aktif")
    check("kartunya menyebut keadaan hari ini",
          txt(pg, "[data-mod=messi] .state"), "Belum lapor hari ini")
    check("modul yang belum jadi tetap terlihat", body, lambda s: "PRISTA" in s)
    check("tapi ditandai segera", txt(pg, ".mod.soon .pill"), "segera")
    # Kartu yang bisa diklik lalu tidak melakukan apa-apa lebih buruk daripada kartu jujur.
    check("dan tidak bisa dibuka", pg.query_selector("[data-mod=prista]") is None)
    pg.screenshot(path=str(SHOTS) + "/t-catalog.png", full_page=True)

    pg.click("[data-mod=messi]")
    pg.wait_for_timeout(300)
    check("masuk modul membuka laporannya", txt(pg, "h1"), "Berapa banyak hari ini?")
    check("baris modul menunjukkan di mana orangnya", txt(pg, "#modbar"),
          lambda s: "MESSI" in s and "Modul" in s)

    # Dua jalan pulang, karena orang mencari keduanya.
    pg.click("[data-home]")
    pg.wait_for_timeout(250)
    check("'‹ Modul' kembali ke katalog", txt(pg, "h1"), "Modul")
    pg.click("[data-mod=messi]")
    pg.wait_for_timeout(250)
    pg.click("#home")
    pg.wait_for_timeout(250)
    check("logo FURA juga kembali ke katalog", txt(pg, "h1"), "Modul")

    print("\n--- katalog tahu keadaan hari ini ---")
    pgS = new_page(context(b), "u_nicho", at=SABTU, enter=None)
    check("akhir pekan terbaca dari kartunya",
          txt(pgS, "[data-mod=messi] .state"), "Hari ini libur")
    pgP = new_page(context(b), "u_nicho", at=PAGI, enter=None)
    check("sebelum jam buka pun", txt(pgP, "[data-mod=messi] .state"), "Dibuka jam 09:00")

    ctxN = context(b)
    pgN = new_page(ctxN, "u_nicho", at=AT)
    def cell(pg, r, c, v): pg.fill(f"[data-row={r}][data-col={c}]", str(v))
    for r, o in [("WAG", 5), ("TGG", 2), ("GCG", 1)]: cell(pgN, r, "open", o)
    pgN.wait_for_timeout(200); pgN.click("#next"); pgN.wait_for_timeout(250)
    pgN.check("[name=declared]"); pgN.click("#next"); pgN.wait_for_timeout(500)
    pgN.click("#home"); pgN.wait_for_timeout(300)
    check("setelah lapor, kartunya ikut berubah",
          txt(pgN, "[data-mod=messi] .state"), "Sudah lapor hari ini")

    stored(pgN, "cycles", 1)
    pgL = new_page(ctxN, "u_lead", at=AT, reset=False, enter=None)
    check("leader melihat keadaan timnya dari katalog",
          pgL.inner_text("[data-mod=messi]"), lambda s: "Tim:" in s and "sudah lapor" in s)

    print("\n--- memuat ulang tidak melempar orang keluar ---")
    pgR = new_page(context(b), "u_nicho", at=AT)
    cell(pgR, "WAG", "open", 7)
    pgR.wait_for_timeout(300)
    # Dicek dulu bahwa draftnya memang sudah tertulis. Kalau tidak, kegagalan di bawah
    # bisa berarti dua hal yang berbeda — gagal menyimpan, atau gagal membaca kembali.
    check("angkanya tersimpan sebagai draft sebelum dimuat ulang", pgR.evaluate(
        """() => { try { return (JSON.parse(
             localStorage.getItem("messi.draft.2026-09-30") || "{}").grid || {}).WAG.open; }
           catch (e) { return "tidak tersimpan"; } }"""), 7)
    pgR.reload()
    pgR.wait_for_selector("[data-row=WAG][data-col=open]", timeout=10_000)
    pgR.wait_for_timeout(300)
    check("muat ulang di tengah pengisian tetap di dalam modul",
          txt(pgR, "h1"), "Berapa banyak hari ini?")
    check("dan angkanya masih ada",
          pgR.input_value("[data-row=WAG][data-col=open]"), "7")
    pgR.click("#home"); pgR.wait_for_timeout(250)
    pgR.reload(); pgR.wait_for_timeout(900)
    check("keluar dari modul lalu muat ulang tetap di katalog", txt(pgR, "h1"), "Modul")

    print("\n--- kepala dan baris modul sebagai satu blok ---")
    # Diukur, bukan dilihat: dulu baris modul menempel dengan jarak tetap dari atas, dan
    # di layar sempit kepalanya jadi dua baris — sehingga baris modulnya tertimbun.
    for lebar in (360, 400, 860):
        cw = b.new_context(viewport={"width": lebar, "height": 700})
        pw = new_page(cw, "u_nicho", at=AT)
        pw.evaluate("window.scrollTo(0, 400)")
        pw.wait_for_timeout(250)
        atas = pw.eval_on_selector(".top", "e=>e.getBoundingClientRect().bottom")
        baris = pw.eval_on_selector("#modbar", "e=>e.getBoundingClientRect().top")
        check(f"di {lebar}px baris modul tidak tertimbun kepalanya",
              round(baris - atas, 1), lambda d: -0.5 <= d <= 0.5)
        check(f"dan di {lebar}px halamannya tidak bisa digeser ke samping",
              pw.evaluate("document.documentElement.scrollWidth > window.innerWidth"), False)
        cw.close()

    print("\n=== PENJELASAN MODUL ===")
    ctxI = context(b)
    pgI = new_page(ctxI, "u_nicho", at=AT, enter=None, seen=None)
    pgI.click("[data-mod=messi]")
    pgI.wait_for_timeout(300)
    check("pertama kali masuk, yang muncul penjelasannya", txt(pgI, "h1"), "MESSI")
    intro = pgI.inner_text("body")
    check("menjelaskan untuk apa modulnya", intro, lambda s: "menggantung" in s)
    check("menyebut langkah-langkahnya", intro.lower(),
          lambda s: "yang kamu isi" in s and "setelah dikirim" in s)
    check("menyebut jam buka dan jam tutupnya", intro,
          lambda s: "jam 09:00" in s and "jam 18:00" in s)
    check("menyebut ambang merahnya", intro, lambda s: "lebih dari 3 hari" in s)
    check("menyebut channel yang dihitung", intro, lambda s: "WhatsApp Group" in s)
    check("menyebut bahwa tanggalnya jadi janji", intro, lambda s: "janji" in s)
    check("belum ada tombol kirim di penjelasan", pgI.eval_on_selector("#bar", "e=>e.hidden"))
    pgI.screenshot(path=str(SHOTS) + "/t-intro.png", full_page=True)

    pgI.click("#introGo")
    pgI.wait_for_timeout(300)
    check("'Mulai isi laporan' membuka laporannya", txt(pgI, "h1"), "Berapa banyak hari ini?")

    pgI.click("[data-home]"); pgI.wait_for_timeout(250)
    pgI.click("[data-mod=messi]"); pgI.wait_for_timeout(300)
    check("masuk kedua kalinya tidak membaca penjelasan lagi",
          txt(pgI, "h1"), "Berapa banyak hari ini?")
    pgI.click("#what"); pgI.wait_for_timeout(250)
    check("tapi masih bisa dibuka lewat 'Apa ini?'", txt(pgI, "h1"), "MESSI")

    print("\n=== PERTANYAAN YANG DISETEL ADMIN ===")
    CFG = {
        "team_name": "Tim Dukungan",
        "threshold_days": 1,
        "open_hour": 8,
        "due_hour": 16,
        "max_plans": 2,
        "declaration": "Saya menyatakan laporan ini benar.",
        "channels": [{"key": "IG", "label": "IG", "full": "Instagram DM"},
                     {"key": "EMAIL", "label": "Email", "full": "Kotak masuk email"}],
        "questions": {
            "grid": {"label": "Berapa tiket hari ini?", "hint": "Hitung kotak masuk kamu."},
            "detail": {"label": "Tiket yang mana?", "placeholder": "#1041 — belum dibalas",
                       "error": "Tulis nomor tiketnya dulu."},
            "plan": {"label": "Tindakannya apa?", "placeholder": "Eskalasi ke tim produk",
                     "error": "Tulis tindakannya."},
            "due": {"label": "Target selesai", "error": "Pilih targetnya."},
            "escalation": {"show": False},
            "prista": {"label": "Perlu jadi project?", "show": True},
        },
    }
    pgC = new_page(context(b), "u_nicho", at=AT, config=CFG)
    check("judul langkahnya ikut yang disetel", txt(pgC, "h1"), "Berapa tiket hari ini?")
    check("keterangannya juga", txt(pgC, ".sub"), "Hitung kotak masuk kamu.")
    check("channel yang dihitung ikut berganti",
          pgC.eval_on_selector_all("[data-row]", "e=>[...new Set(e.map(x=>x.dataset.row))]"),
          ["IG", "EMAIL"])
    check("kolomnya menyebut ambang yang disetel", pgC.inner_text(".mx"),
          lambda s: ">1 hari" in s and "<1 hari" in s)

    cell(pgC, "IG", "open", 6); cell(pgC, "EMAIL", "open", 2)
    cell(pgC, "IG", "gt3", 1)
    pgC.wait_for_timeout(250)
    check("lampunya menyebut ambang yang sama", txt(pgC, ".lamp"),
          lambda s: "lebih dari 1 hari" in s)

    pgC.click("#next"); pgC.wait_for_timeout(250)
    check("pertanyaan gantungnya ikut diganti", pgC.inner_text("body"),
          lambda s: "Tiket yang mana?" in s and "Tindakannya apa?" in s)
    pgC.click("#next"); pgC.wait_for_timeout(250)
    check("pesan kesalahan memakai kalimat admin", txt(pgC, ".bar .err"),
          "Tulis nomor tiketnya dulu.")
    pgC.fill("[name=detail]", "#1041")
    pgC.click("#next"); pgC.wait_for_timeout(250)
    check("dan pesan kesalahan rencananya juga", txt(pgC, ".bar .err"), "Tulis tindakannya.")
    pgC.fill("[data-pa='0']", "Eskalasi ke tim produk")
    pgC.click("#next"); pgC.wait_for_timeout(250)
    check("begitu juga pesan tanggalnya", txt(pgC, ".bar .err"), "Pilih targetnya.")
    pgC.fill("[data-pd='0']", "2026-10-01")

    pgC.click("#addPlan"); pgC.wait_for_timeout(200)
    pgC.fill("[data-pa='1']", "Telepon pelanggan"); pgC.fill("[data-pd='1']", "2026-10-02")
    check("batas jumlah rencana ikut yang disetel",
          pgC.query_selector("#addPlan") is None)

    pgC.click("#next"); pgC.wait_for_timeout(300)
    kirim = pgC.inner_text("body")
    check("pertanyaan yang dimatikan tidak ditanyakan",
          pgC.query_selector("[name=escalation]") is None)
    check("yang dibiarkan hidup tetap ditanyakan",
          pgC.query_selector("[name=prista]") is not None)
    check("pernyataannya ikut yang ditulis admin", kirim,
          lambda s: "Saya menyatakan laporan ini benar." in s)

    pgC.check("[name=declared]"); pgC.click("#next"); pgC.wait_for_timeout(500)
    check("terkirim", txt(pgC, "h1"), "Sudah terkirim")
    pgC.click("#toggle"); pgC.wait_for_timeout(250)
    rep = pgC.inner_text("#reportText")
    check("laporannya menyebut nama tim", rep, lambda s: "Tim: Tim Dukungan" in s)
    check("memakai ambang yang disetel", rep, lambda s: "b. Gantung >1 hari: 1" in s)
    check("memakai channel yang disetel", rep,
          lambda s: "a. Channel aktif/open: 6 IG, 2 Email" in s)
    check("tidak mencetak pertanyaan yang dimatikan", rep, lambda s: "h. Eskalasi" not in s)
    check("tapi tetap mencetak yang hidup", rep, lambda s: "i. Calon project baru" in s)
    check("dan pernyataan yang ditulis admin", rep,
          lambda s: s.rstrip().endswith("Saya menyatakan laporan ini benar."))

    # Kebalikannya juga: yang dimatikan hilang, yang dihidupkan muncul — dua-duanya diuji,
    # karena satu cabang yang selalu benar tidak menguji apa pun.
    CFG2 = dict(CFG, questions=dict(CFG["questions"],
        escalation={"label": "Perlu bantuan?", "show": True},
        prista={"label": "Perlu jadi project?", "show": False}))
    pgD = new_page(context(b), "u_rio", at=AT, config=CFG2)
    cell(pgD, "IG", "open", 3)
    pgD.wait_for_timeout(200); pgD.click("#next"); pgD.wait_for_timeout(250)
    check("pertanyaan yang dihidupkan ditanyakan",
          pgD.query_selector("[name=escalation]") is not None)
    check("dan yang dimatikan tidak", pgD.query_selector("[name=prista]") is None)
    pgD.fill("[name=escalation]", "Minta bantuan tim produk")
    pgD.check("[name=declared]"); pgD.click("#next"); pgD.wait_for_timeout(500)
    pgD.click("#toggle"); pgD.wait_for_timeout(250)
    rep2 = pgD.inner_text("#reportText")
    check("laporannya mencetak yang dihidupkan", rep2,
          lambda s: "h. Eskalasi: Minta bantuan tim produk" in s)
    check("dan tidak mencetak yang dimatikan", rep2,
          lambda s: "Calon project" not in s)

    # Laporan membawa setelan yang berlaku saat dikirim, supaya nanti tetap terbaca sama.
    doc = list(store_of(pgC)["cycles"].values())[0]
    snap = doc.get("cfg") or {}
    check("laporan menyimpan cuplikan setelannya",
          [c["key"] for c in snap.get("channels") or []], ["IG", "EMAIL"])
    check("termasuk ambangnya", snap.get("threshold_days"), 1)
    pgC.screenshot(path=str(SHOTS) + "/t-config.png", full_page=True)

    b.close()

bad = [r for r in results if not r[0]]
print(f"\n{len(results) - len(bad)}/{len(results)} lolos")
sys.exit(1 if bad else 0)
