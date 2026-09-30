import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from harness import (SHOTS, check, context, launch, new_page, report, results,
                     store_of, txt, sync_playwright)

with sync_playwright() as p:
    b = launch(p)
    ctx = context(b)

    print("\n=== USER (Nicho, bukan owner) ===")
    pg = new_page(ctx, "u_nicho")
    check("halaman termuat di langkah 1", txt(pg,".stepno"), "LANGKAH 1 DARI 2")
    check("nama dikenali dari akun", pg.get_attribute("#avatar","title"), "Nicho")
    check("player TIDAK melihat tab manager", pg.eval_on_selector("#tabs","e=>e.hidden"))
    check("otomatis masuk roster", "u_nicho" in store_of(pg)["roster"])

    pg.click("#next"); pg.wait_for_timeout(200)
    check("kosong ditolak", txt(pg,".bar .err"), "Isi dulu angkanya. Kalau kosong semua, tulis 0.")

    def g(r,c,v): pg.fill(f'[data-row={r}][data-col={c}]', str(v))
    g("WAG","open",3); g("WAG","gt3",9); pg.wait_for_timeout(150)
    pg.click("#next"); pg.wait_for_timeout(200)
    check("angka mustahil ditolak", "lebih banyak daripada" in txt(pg,".bar .err"))

    g("WAG","gt3",""); g("WAG","open",13); g("TGG","open",4); g("GCG","open",4)
    g("WAG","reply",5); g("TGG","reply",2); pg.wait_for_timeout(200)
    check("angka turunan hidup (21 aktif, 7 dibalas)", txt(pg,".sumline"), lambda s: "21" in s and "7" in s)
    check("lampu hijau saat bersih", pg.get_attribute(".lamp","class"), "lamp green")

    pg.click("#next"); pg.wait_for_timeout(250)
    check("hari bersih lewati langkah gantung", txt(pg,".stepno"), "LANGKAH 2 DARI 2")

    # draft survives a reload mid-fill
    pg.reload(); pg.wait_for_timeout(900)
    check("draft bertahan setelah halaman ditutup",
          pg.input_value('[data-row=WAG][data-col=open]'), "13")

    pg.click('[data-goto="1"]') if pg.query_selector('[data-goto="1"]') else None
    pg.wait_for_timeout(200)
    if txt(pg,".stepno") != "LANGKAH 1 DARI 2":
        pg.click("#back") if pg.query_selector("#back") else None
        pg.wait_for_timeout(200)
    g("WAG","gt3",2); g("WAG","lt3",1); pg.wait_for_timeout(200)
    check("lampu merah saat gantung >3 hari", pg.get_attribute(".lamp","class"), "lamp red")
    pg.click("#next"); pg.wait_for_timeout(250)
    check("muncul langkah gantung", txt(pg,".stepno"), "LANGKAH 2 DARI 3")
    check("judul menyebut jumlahnya", txt(pg,"h1"), "3 yang masih gantung")

    pg.click("#next"); pg.wait_for_timeout(200)
    check("detail gantung wajib", txt(pg,".bar .err"), "Tulis dulu yang mana saja yang gantung.")
    pg.fill("[name=detail]","WAG Klien A belum dibalas 4 hari")
    pg.click("#next"); pg.wait_for_timeout(200)
    check("rencana wajib", txt(pg,".bar .err"), "Tulis rencananya.")
    pg.fill("[name=plan]","Balas setelah harga di-approve")
    pg.click("#next"); pg.wait_for_timeout(200)
    check("tanggal wajib", "Pilih tanggalnya" in txt(pg,".bar .err"))
    pg.fill("[name=due]","2026-10-02")
    pg.click("#next"); pg.wait_for_timeout(250)
    check("sampai layar cek", txt(pg,".stepno"), "LANGKAH 3 DARI 3")
    check("ringkasan menampilkan angka", txt(pg,".rev"), lambda s: "21" in s and "3" in s)

    pg.fill("[name=escalation]","Butuh Chief approve harga Klien A")
    pg.click("#next"); pg.wait_for_timeout(200)
    check("deklarasi wajib", txt(pg,".bar .err"), "Centang pernyataannya dulu.")
    pg.check("[name=declared]"); pg.click("#next"); pg.wait_for_timeout(400)
    check("terkirim", txt(pg,"h1"), "Sudah terkirim")

    st = store_of(pg)
    check("tersimpan ke database bersama", len(st["cycles"]) == 1)
    check("janji tercatat", len(st["commitments"]) == 1)
    cm = list(st["commitments"].values())[0]
    check("janji simpan tanggal & pemilik", cm["due"] == "2026-10-02" and cm["owner"] == "u_nicho")

    check("laporan disembunyikan dulu", pg.query_selector("#reportText") is None)
    pg.click("#toggle"); pg.wait_for_timeout(200)
    rep = txt(pg,"#reportText")
    check("laporan format asli", rep, lambda s: "MESSI Report" in s and "[MERAH]" in s
          and "a. Channel aktif/open: 13 WAG, 4 TGG, 4 GCG" in s and "Deklarasi:" in s)
    check("laporan pakai nama asli", "Report by: Nicho" in rep)
    check("aritmatika di laporan benar", "d. Tidak gantung: 18" in rep and "e. Sudah dibalas hari ini: 7" in rep)
    pg.click("#toggle"); pg.wait_for_timeout(150)
    check("laporan bisa ditutup lagi", pg.query_selector("#reportText") is None)

    pg.reload(); pg.wait_for_timeout(900)
    check("setelah refresh tetap 'sudah terkirim'", txt(pg,"h1"), "Sudah terkirim")
    check("janji tampil di layar selesai", "Balas setelah harga" in pg.inner_text("body"))

    open(str(SHOTS)+"/store.json","w").write(json.dumps(store_of(pg)))
    pg.screenshot(path=str(SHOTS)+"/t-user-done.png", full_page=True)
    b.close()

bad = [r for r in results if not r[0]]
print(f"\n{len(results)-len(bad)}/{len(results)} lolos")
sys.exit(1 if bad else 0)
