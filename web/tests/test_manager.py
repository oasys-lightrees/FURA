import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from harness import (SHOTS, check, context, launch, new_page, report, results,
                     store_of, txt, sync_playwright)

TODAY, YDAY, D2 = "2026-09-30", "2026-09-29", "2026-09-28"   # Rab, Sel, Sen
def cyc(owner, day, gt3=0, lt3=0, esc="", late=False):
    return { "owner":owner, "day":day, "grid":{"WAG":{"open":13,"gt3":gt3,"lt3":lt3,"reply":5},
             "TGG":{"open":4,"reply":2}, "GCG":{"open":4,"reply":1}},
             "detail":"WAG Klien A" if gt3+lt3 else "", "plan":"Balas besok" if gt3+lt3 else "",
             "due":"2026-10-02" if gt3+lt3 else "", "escalation":esc, "prista":"",
             "declared":True, "submittedAt":day+"T09:05:00Z", "late":late }

SEED = {
  "roster": { "u_nicho":{"name":"Nicho","joined":D2}, "u_chief":{"name":"Chief","joined":D2},
              "u_rio":{"name":"Rio","joined":D2} },
  "cycles": {
    "u_nicho__"+TODAY: cyc("u_nicho", TODAY, gt3=2, lt3=1, esc="Butuh Chief approve harga Klien A"),
    "u_nicho__"+YDAY:  cyc("u_nicho", YDAY),
    "u_chief__"+YDAY:  cyc("u_chief", YDAY),
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

    print("\n=== MANAGER (Chief, owner) ===")
    pg = new_page(ctx, "u_chief", SEED)
    check("manager melihat dua tab", pg.eval_on_selector("#tabs","e=>!e.hidden"))
    check("tab bernama 'Punya saya' & 'Squad'", txt(pg,"#tabs"), lambda s: "Punya saya" in s and "Squad" in s)
    check("badge janji lewat di tab", txt(pg,"#tabs .n"), "1")
    check("default ke laporan sendiri", txt(pg,"h1"), "Berapa banyak hari ini?")

    pg.click('[data-tab="chief"]'); pg.wait_for_timeout(400)
    check("judul halaman manager", txt(pg,"h1"), "Yang perlu kamu lihat")
    body = pg.inner_text("body")
    stats = txt(pg,".stats")
    check("hitung sudah lapor (1 dari 3)", stats, lambda s: "1/3" in s)
    check("hitung janji lewat", stats, lambda s: "1" in s)
    check("hitung belum lapor (Chief + Rio)", stats, lambda s: "2" in s)

    check("melihat eskalasi Nicho", "Butuh Chief approve harga Klien A" in body)
    check("eskalasi diberi nama orangnya", body, lambda s: "Nicho" in s)
    check("melihat janji yang lewat", "Kirim revisi ke Vendor B" in body)
    check("menandai Rio jarang lapor", body, lambda s: "Rio" in s and "Belum lapor beberapa hari" in s)
    check("daftar belum lapor hari ini", body, lambda s: "Belum lapor" in s)
    check("nama asli terbaca, bukan id", "u_nicho" not in body and "u_rio" not in body)

    check("TIDAK menampilkan isi laporan penuh tiap orang",
          "MESSI Report" not in body and "Deklarasi:" not in body)
    check("tidak ada tombol kirim di halaman manager", pg.eval_on_selector("#bar","e=>e.hidden"))
    pg.screenshot(path=str(SHOTS)+"/t-manager.png", full_page=True)

    print("\n--- manager mengisi laporannya sendiri ---")
    pg.click('[data-tab="hari-ini"]'); pg.wait_for_timeout(300)
    check("kembali ke form sendiri", txt(pg,".stepno"), "LANGKAH 1 DARI 2")
    def g(r,c,v): pg.fill(f'[data-row={r}][data-col={c}]', str(v))
    for r,o in [("WAG",6),("TGG",2),("GCG",1)]: g(r,"open",o)
    g("WAG","reply",4); pg.wait_for_timeout(200)
    pg.click("#next"); pg.wait_for_timeout(250)
    pg.check("[name=declared]"); pg.click("#next"); pg.wait_for_timeout(400)
    check("manager bisa mengirim laporannya", txt(pg,"h1"), "Sudah terkirim")
    pg.click('[data-tab="chief"]'); pg.wait_for_timeout(350)
    check("angka manager ikut terhitung (2/3)", txt(pg,".stats"), lambda s: "2/3" in s)

    print("\n--- semua beres: halaman manager harus kosong ---")
    # Realistic: on the roster since day one, and reported every workday since.
    pg2 = new_page(ctx, "u_chief", {
      "roster": {"u_chief":{"name":"Chief","joined":D2}},
      "cycles": {"u_chief__"+TODAY: cyc("u_chief", TODAY),
                 "u_chief__"+YDAY:  cyc("u_chief", YDAY),
                 "u_chief__"+D2:    cyc("u_chief", D2)}, "commitments": {}})
    pg2.click('[data-tab="chief"]'); pg2.wait_for_timeout(400)
    check("pesan 'tidak ada yang perlu dibaca'",
          "Tidak ada yang perlu dibaca" in pg2.inner_text("body"))
    check("tidak ada kartu peringatan", pg2.query_selector(".card.bad") is None
          and pg2.query_selector(".card.due") is None)
    pg2.screenshot(path=str(SHOTS)+"/t-manager-clear.png", full_page=True)

    print("\n--- user biasa tidak boleh melihat halaman manager ---")
    pg3 = new_page(ctx, "u_rio", SEED)
    check("Rio tidak punya tab manager", pg3.eval_on_selector("#tabs","e=>e.hidden"))
    check("Rio tidak melihat data orang lain",
          "Butuh Chief approve" not in pg3.inner_text("body"))
    check("Rio diberi tahu hari yang dia lewatkan",
          "Kamu belum lapor" in pg3.inner_text("body"))
    pg3.screenshot(path=str(SHOTS)+"/t-rio-miss.png", full_page=True)
    b.close()

bad = [r for r in results if not r[0]]
print(f"\n{len(results)-len(bad)}/{len(results)} lolos")
