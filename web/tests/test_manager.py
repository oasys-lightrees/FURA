import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from harness import (SHOTS, check, context, launch, new_page, report, results,
                     store_of, txt, sync_playwright)

TODAY, YDAY, D2 = "2026-09-30", "2026-09-29", "2026-09-28"   # Rab, Sel, Sen
# Tanpa ini suite-nya hanya benar pada 30 Sep 2026 — dan diam-diam pecah keesokan harinya.
AT = "2026-09-30T04:00:00Z"                                   # Rabu 11:00 WIB
def cyc(owner, day, gt3=0, lt3=0, esc="", late=False):
    return { "owner":owner, "day":day, "grid":{"WAG":{"open":13,"gt3":gt3,"lt3":lt3,"reply":5},
             "TGG":{"open":4,"reply":2}, "GCG":{"open":4,"reply":1}},
             "detail":"WAG Klien A" if gt3+lt3 else "", "plan":"Balas besok" if gt3+lt3 else "",
             "due":"2026-10-02" if gt3+lt3 else "", "escalation":esc, "prista":"",
             "declared":True, "submittedAt":day+"T09:05:00Z", "late":late }

SEED = {
  "roster": { "u_nicho":{"name":"Nicho","joined":D2}, "u_lead":{"name":"Lia","joined":D2},
              "u_rio":{"name":"Rio","joined":D2} },
  "cycles": {
    "u_nicho__"+TODAY: cyc("u_nicho", TODAY, gt3=2, lt3=1, esc="Butuh approve harga Klien A"),
    "u_nicho__"+YDAY:  cyc("u_nicho", YDAY),
    "u_lead__"+YDAY:  cyc("u_lead", YDAY),
    # Rio: tidak ada sama sekali -> 2 hari kerja terlewat + belum lapor hari ini
  },
  "commitments": {
    "c-nicho-1": { "id":"c-nicho-1", "owner":"u_nicho", "action":"Kirim revisi ke Vendor B",
                   "due":D2, "status":"open", "from":D2 },   # lewat tanggal
  },
}

with sync_playwright() as p:
    b = launch(p)
    ctx = context(b)

    print("\n=== MANAGER (leader) ===")
    pg = new_page(ctx, "u_lead", SEED, at=AT)
    check("manager melihat dua tab", pg.eval_on_selector("#tabs","e=>!e.hidden"))
    check("tab bernama 'Punya saya' & 'Tim'", txt(pg,"#tabs"), lambda s: "Punya saya" in s and "Tim" in s)
    check("badge janji lewat di tab", txt(pg,"#tabs .n"), "1")
    check("default ke laporan sendiri", txt(pg,"h1"), "Berapa banyak hari ini?")

    pg.click('[data-tab="tim"]'); pg.wait_for_timeout(400)
    check("judul halaman manager", txt(pg,"h1"), "Rekap tim")
    body = pg.inner_text("body")
    stats = txt(pg,".stats")
    check("hitung sudah lapor (1 dari 3)", stats, lambda s: "1/3" in s)
    check("hitung janji lewat", stats, lambda s: "1" in s)
    check("hitung belum lapor (Lia + Rio)", stats, lambda s: "2" in s)

    check("melihat eskalasi Nicho", "Butuh approve harga Klien A" in body)
    check("eskalasi diberi nama orangnya", body, lambda s: "Nicho" in s)
    check("melihat janji yang lewat", "Kirim revisi ke Vendor B" in body)
    check("menandai Rio jarang lapor", body, lambda s: "Rio" in s and "Belum lapor beberapa hari" in s)
    check("daftar belum lapor hari ini", body, lambda s: "Belum lapor" in s)
    check("nama asli terbaca, bukan id", "u_nicho" not in body and "u_rio" not in body)

    # Laporan lengkap tidak terhampar begitu saja, tapi bisa dibuka satu per satu —
    # dulu laporan itu memang tertempel utuh di grup, jadi ini lebih tertutup, bukan
    # lebih terbuka.
    check("laporan penuh tidak terhampar semua sekaligus",
          "MESSI Report" not in body and "Deklarasi:" not in body)
    pg.click('[data-open="u_nicho"]'); pg.wait_for_timeout(250)
    opened = txt(pg, ".rv")
    # Dibaca sebagai layar, bukan sebagai teks yang kebetulan ditampilkan: angkanya tabel,
    # dan yang tidak dijawab tidak dicetak sama sekali.
    check("tapi laporan satu orang bisa dibuka", opened, lambda s: "Semua" in s)
    check("angkanya terbaca sebagai angka", opened, lambda s: "21" in s)
    check("yang gantung disebutkan", opened, lambda s: "Klien A" in s)
    check("judul berbahasa Inggris tidak ikut tercetak",
          opened, lambda s: "MESSI Report" not in s and "Report by" not in s)
    check("dan paragraf deklarasinya tidak memakan separuh kartunya",
          opened, lambda s: "sudah menyatakan" in s and "Deklarasi:" not in s)
    check("teks lamanya tetap bisa disalin", opened, lambda s: "Salin teksnya" in s)
    pg.click('[data-open="u_nicho"]'); pg.wait_for_timeout(250)
    check("dan bisa ditutup lagi", pg.query_selector(".rv") is None)
    check("tidak ada tombol kirim di halaman manager", pg.eval_on_selector("#bar","e=>e.hidden"))
    pg.screenshot(path=str(SHOTS)+"/t-manager.png", full_page=True)

    print("\n--- rekap semua orang, bukan cuma yang bermasalah ---")
    check("ada daftar semua orang", body, lambda s: "SEMUA (3)" in s)
    check("yang sudah lapor tampil dengan angkanya", body, lambda s: "21 aktif" in s)  # 13 WAG + 4 TGG + 4 GCG
    check("dan dengan berapa yang gantung", body, lambda s: "3 gantung" in s)
    check("yang belum lapor juga tetap tercantum", body, lambda s: "Rio" in s)
    check("leader sendiri ikut terdaftar", body, lambda s: "Lia" in s)

    print("\n--- membaca hari kemarin ---")
    check("ada tombol ke hari sebelumnya", pg.query_selector('[data-day="2026-09-29"]') is not None)
    pg.click('[data-day="2026-09-29"]'); pg.wait_for_timeout(350)
    check("judul harinya ikut pindah", pg.inner_text("body"), lambda s: "Selasa, 29 Sep" in s)
    check("angkanya hari itu, bukan hari ini", txt(pg,".stats"), lambda s: "2/3" in s)
    pg.click('[data-day="2026-09-30"]'); pg.wait_for_timeout(350)
    check("bisa kembali ke hari ini", pg.inner_text("body"), lambda s: "Rabu, 30 Sep" in s)
    check("tidak bisa melihat besok", pg.query_selector('[data-day="2026-10-01"]') is None)

    print("\n--- manager mengisi laporannya sendiri ---")
    pg.click('[data-tab="hari-ini"]'); pg.wait_for_timeout(300)
    check("kembali ke form sendiri", txt(pg,".stepno"), "LANGKAH 1")
    def g(r,c,v): pg.fill(f'[data-row={r}][data-col={c}]', str(v))
    for r,o in [("WAG",6),("TGG",2),("GCG",1)]: g(r,"open",o)
    g("WAG","reply",4); pg.wait_for_timeout(200)
    pg.click("#next"); pg.wait_for_timeout(250)
    pg.check("[name=declared]"); pg.click("#next"); pg.wait_for_timeout(400)
    check("manager bisa mengirim laporannya", txt(pg,"h1"), "Sudah terkirim")
    pg.click('[data-tab="tim"]'); pg.wait_for_timeout(350)
    check("angka manager ikut terhitung (2/3)", txt(pg,".stats"), lambda s: "2/3" in s)

    print("\n--- yang sudah lapor hari ini bukan 'pembolos' hari ini ---")
    # Seed sendiri: Dita lapor hari ini tapi dua hari kerja sebelumnya kosong. Dulu dia
    # muncul sebagai kartu merah "belum lapor beberapa hari" — berbarengan dengan
    # barisnya sendiri yang hijau, dan menenggelamkan eskalasi yang sungguhan.
    pgD = new_page(ctx, "u_lead", at=AT, store={
      "roster": {"u_lead":{"name":"Lia","joined":D2}, "u_dita":{"name":"Dita","joined":D2}},
      "cycles": {"u_dita__"+TODAY: cyc("u_dita", TODAY),
                 "u_lead__"+TODAY: cyc("u_lead", TODAY)},
      "commitments": {}})
    pgD.click('[data-tab="tim"]'); pgD.wait_for_timeout(400)
    bodyD = pgD.inner_text("body")
    check("Dita tidak dituduh membolos padahal dia lapor hari ini",
          "Belum lapor beberapa hari." not in bodyD)
    check("riwayatnya tetap kelihatan, sebagai catatan kecil di barisnya",
          bodyD, lambda s: "hari terlewat" in s)
    check("dan dia tetap terhitung sudah lapor", txt(pgD,".stats"), lambda s: "2/2" in s)

    print("\n--- yang benar-benar tidak lapor tetap diangkat ---")
    pgE = new_page(ctx, "u_lead", at=AT, store={
      "roster": {"u_lead":{"name":"Lia","joined":D2}, "u_bayu":{"name":"Bayu","joined":D2}},
      "cycles": {"u_lead__"+TODAY: cyc("u_lead", TODAY)},
      "commitments": {}})
    pgE.click('[data-tab="tim"]'); pgE.wait_for_timeout(400)
    check("Bayu yang memang tidak pernah lapor tetap diangkat",
          pgE.inner_text("body"), lambda s: "Belum lapor beberapa hari." in s)

    print("\n--- tiga orang yang sama-sama sepi jadi satu kartu, bukan tiga ---")
    # Tiga kartu yang berbunyi persis sama mendorong satu permintaan bantuan yang
    # sungguhan ke bawah layar, dan yang di bawah layar tidak dibaca.
    pgF = new_page(ctx, "u_lead", at=AT, store={
      "roster": {"u_lead":{"name":"Lia","joined":D2}, "u_bayu":{"name":"Bayu","joined":D2},
                 "u_cici":{"name":"Cici","joined":D2}, "u_deni":{"name":"Deni","joined":D2}},
      "cycles": {"u_lead__"+TODAY: cyc("u_lead", TODAY)},
      "commitments": {}})
    pgF.click('[data-tab="tim"]'); pgF.wait_for_timeout(400)
    kartu = pgF.eval_on_selector_all(
        ".card.bad", "e=>e.map(x=>x.textContent)")
    check("satu kartu untuk ketiganya", len(kartu), 1)
    check("dan ketiganya disebut namanya di dalamnya",
          kartu[0], lambda s: all(n in s for n in ["Bayu", "Cici", "Deni"]))
    check("beserta berapa hari masing-masing", kartu[0], lambda s: "hari" in s)

    print("\n--- semua beres: halaman manager harus kosong ---")
    # Realistic: on the roster since day one, and reported every workday since.
    pg2 = new_page(ctx, "u_lead", at=AT, store={
      "roster": {"u_lead":{"name":"Lia","joined":D2}},
      "cycles": {"u_lead__"+TODAY: cyc("u_lead", TODAY),
                 "u_lead__"+YDAY:  cyc("u_lead", YDAY),
                 "u_lead__"+D2:    cyc("u_lead", D2)}, "commitments": {}})
    pg2.click('[data-tab="tim"]'); pg2.wait_for_timeout(400)
    check("pesan 'tidak ada yang perlu dibaca'",
          "Tidak ada yang perlu dibaca" in pg2.inner_text("body"))
    check("tidak ada kartu peringatan", pg2.query_selector(".card.bad") is None
          and pg2.query_selector(".card.due") is None)
    pg2.screenshot(path=str(SHOTS)+"/t-manager-clear.png", full_page=True)

    print("\n--- di laptop, rekapnya dua kolom ---")
    wide = b.new_context(viewport={"width": 1280, "height": 900})
    pgW = new_page(wide, "u_lead", SEED, at=AT)
    pgW.click('[data-tab="tim"]'); pgW.wait_for_timeout(400)
    check("kolomnya jadi dua", pgW.evaluate(
        "getComputedStyle(document.querySelector('.cols')).gridTemplateColumns"),
        lambda v: len(v.split()) == 2)
    check("lebarnya melebar dari 500px", pgW.evaluate(
        "document.querySelector('.app').getBoundingClientRect().width"), 720)
    # Batang tombol yang menempel di dasar layar lebar terasa terlepas dari isinya.
    check("batang tombol ikut mengalir, tidak menempel di dasar layar", pgW.evaluate(
        "getComputedStyle(document.querySelector('#bar')).position"), "static")
    check("isinya sama saja, cuma tata letaknya yang beda",
          pgW.inner_text("body"), lambda s: "SEMUA (3)" in s and "Rekap tim" in s)
    wide.close()

    print("\n--- di telepon tetap satu kolom ---")
    check("tidak dua kolom di layar sempit", pg.evaluate(
        "getComputedStyle(document.querySelector('.cols')).gridTemplateColumns"),
        lambda v: len(v.split()) == 1)
    check("batang tombol tetap menempel di bawah jempol", pg.evaluate(
        "getComputedStyle(document.querySelector('#bar')).position"), "fixed")

    print("\n--- user biasa tidak boleh melihat halaman manager ---")
    pg3 = new_page(ctx, "u_rio", SEED, at=AT)
    check("Rio tidak punya tab manager", pg3.eval_on_selector("#tabs","e=>e.hidden"))
    check("Rio tidak melihat data orang lain",
          "Butuh approve" not in pg3.inner_text("body"))
    check("Rio diberi tahu hari yang dia lewatkan",
          "Kamu belum lapor" in pg3.inner_text("body"))
    pg3.screenshot(path=str(SHOTS)+"/t-rio-miss.png", full_page=True)
    print("\n=== dua tim ===")
    # Satu rekap selalu tentang satu tim. Owner dan admin menerima semuanya dan memilih
    # yang mana; leader cuma menerima timnya sendiri dari server, jadi tidak ada yang
    # bisa dipilih.
    TEAMS = [{"id": 1, "name": "Sales"}, {"id": 2, "name": "HR"}]
    SEED2 = {
      "roster": { "u_nicho": {"name":"Nicho","joined":D2,"team":1},
                  "u_lead":  {"name":"Lia","joined":D2,"team":1},
                  "u_ani":   {"name":"Ani","joined":D2,"team":2} },
      "cycles": { "u_nicho__"+TODAY: cyc("u_nicho", TODAY),
                  "u_ani__"+TODAY:   cyc("u_ani", TODAY, gt3=1) },
      "commitments": {
        "c-ani-1": { "id":"c-ani-1", "owner":"u_ani", "action":"Panggil kandidat",
                     "due":D2, "status":"open", "from":D2 },
      },
    }
    dua = context(b)
    pgT = new_page(dua, "u_lead", SEED2, at=AT, teams=TEAMS, team=1)
    pgT.click('[data-tab="tim"]'); pgT.wait_for_timeout(400)
    check("judulnya menyebut tim yang sedang dibaca", txt(pgT, "h1"), "Rekap Sales")
    check("ada pemilih timnya", pgT.eval_on_selector_all("[data-team]", "e=>e.length"), 2)
    body = pgT.inner_text("body")
    check("orang tim itu terdaftar", body, lambda s: "Nicho" in s)
    check("orang tim lain tidak ikut", body, lambda s: "Ani" not in s)
    check("hitungannya pun cuma tim itu", txt(pgT, ".stats"), lambda s: "1/2" in s)
    check("janji lewat milik tim lain tidak ikut dihitung",
          txt(pgT, ".stats"), lambda s: s.split("janji lewat")[0].strip().endswith("0"))

    pgT.click('[data-team="2"]'); pgT.wait_for_timeout(350)
    check("pindah tim mengganti rekapnya", txt(pgT, "h1"), "Rekap HR")
    body2 = pgT.inner_text("body")
    check("sekarang orang tim itu yang terlihat", body2, lambda s: "Ani" in s)
    check("dan yang tadi tidak lagi", body2, lambda s: "Nicho" not in s)
    check("janjinya ikut tim itu", txt(pgT, ".stats"), lambda s: "1" in s)
    pgT.screenshot(path=str(SHOTS) + "/t-dua-tim.png", full_page=True)

    # Leader hanya menerima satu tim dari server, jadi tidak ada yang bisa dipilih.
    pgL = new_page(dua, "u_lead", SEED2, at=AT, teams=[TEAMS[0]], team=1)
    pgL.click('[data-tab="tim"]'); pgL.wait_for_timeout(400)
    check("leader satu tim tidak diberi pemilih", pgL.query_selector("[data-team]") is None)
    check("dan judulnya tetap sederhana", txt(pgL, "h1"), "Rekap tim")

    b.close()

bad = [r for r in results if not r[0]]
print(f"\n{len(results)-len(bad)}/{len(results)} lolos")
# Keluar dengan kode gagal, supaya run_all.py bisa melihatnya. Tanpa baris ini suite
# yang gagal tetap terbaca sukses oleh pemanggilnya.
sys.exit(1 if bad else 0)
