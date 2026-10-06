import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from harness import (SHOTS, check, context, launch, new_page, report, results,
                     store_of, txt, sync_playwright)

with sync_playwright() as p:
    b = launch(p)
    ctx = context(b)
    shared = context(b)

    print("\n=== KASUS PINGGIRAN ===")
    pg = new_page(ctx, "u_nicho", at="2026-10-03T05:00:00Z")           # Sabtu 12:00 WIB
    check("akhir pekan: tidak diminta mengisi", "Hari ini libur" in pg.inner_text("body"))
    check("akhir pekan: tidak ada tombol kirim", pg.eval_on_selector("#bar","e=>e.hidden"))

    pg = new_page(ctx, "u_nicho", at="2026-09-30T00:30:00Z")           # Rabu 07:30 WIB
    check("sebelum jam 9: belum dibuka", "Belum dibuka" in pg.inner_text("body"))
    check("sebelum jam 9: tombol disembunyikan", pg.eval_on_selector("#bar","e=>e.hidden"))

    pg = new_page(ctx, "u_nicho", at="2026-09-30T12:30:00Z")           # Rabu 19:30 WIB
    check("setelah jam 18: masih boleh mengisi", pg.query_selector(".stepno") is not None)
    def g(pg,r,c,v): pg.fill(f'[data-row={r}][data-col={c}]', str(v))
    for r,o in [("WAG",5),("TGG",1),("GCG",1)]: g(pg,r,"open",o)
    pg.wait_for_timeout(150); pg.click("#next"); pg.wait_for_timeout(250)
    pg.check("[name=declared]"); pg.click("#next"); pg.wait_for_timeout(400)
    check("kiriman telat ditandai telat", "setelah jam 18:00" in pg.inner_text("body"))
    st = store_of(pg)
    check("status telat tersimpan", list(st["cycles"].values())[0]["late"] is True)

    print("\n--- rencana lebih dari satu ---")
    many = context(b)
    pgM = new_page(many, "u_nicho", at="2026-09-30T04:00:00Z")            # Rabu 11:00 WIB
    for r, o in [("WAG", 9), ("TGG", 3), ("GCG", 2)]: g(pgM, r, "open", o)
    g(pgM, "WAG", "gt3", 2); g(pgM, "TGG", "lt3", 1)
    pgM.wait_for_timeout(200); pgM.click("#next"); pgM.wait_for_timeout(250)
    nrows = lambda: pgM.eval_on_selector_all("[data-plan]", "e=>e.length")
    check("mulai dari satu baris", nrows(), 1)
    check("satu baris: tidak ada tombol hapus", pgM.query_selector("[data-drop]") is None)
    pgM.fill("[name=detail]", "WAG Klien C, TGG Klien D, GCG Klien E")

    # Satu baris terisi dulu, baru baris berikutnya — baris kosong memang tidak disimpan.
    plans = [("Telepon Klien C", "2026-10-01"), ("Kirim quotation Klien D", "2026-10-02"),
             ("Tagih invoice Klien E", "2026-10-05"), ("Follow up Klien F", "2026-10-06"),
             ("Rapat Klien G", "2026-10-07")]
    for i, (act, due) in enumerate(plans):
        if i: pgM.click("#addPlan"); pgM.wait_for_timeout(150)
        pgM.fill(f"[data-pa='{i}']", act); pgM.fill(f"[data-pd='{i}']", due)
    check("bisa sampai lima baris", nrows(), 5)
    check("lima baris: tombol tambah hilang", pgM.query_selector("#addPlan") is None)
    check("banyak baris: ada tombol hapus", pgM.eval_on_selector_all("[data-drop]", "e=>e.length"), 5)

    pgM.click("[data-drop='2']"); pgM.wait_for_timeout(200)
    check("hapus baris tengah: sisa empat", nrows(), 4)
    check("yang dihapus memang baris itu",
          pgM.eval_on_selector_all("[data-pa]", "e=>e.map(x=>x.value)"),
          ["Telepon Klien C", "Kirim quotation Klien D", "Follow up Klien F", "Rapat Klien G"])
    check("tanggalnya tidak tergeser",
          pgM.eval_on_selector_all("[data-pd]", "e=>e.map(x=>x.value)"),
          ["2026-10-01", "2026-10-02", "2026-10-06", "2026-10-07"])
    check("tombol tambah kembali setelah dihapus", pgM.query_selector("#addPlan") is not None)

    # Tanggal tanpa rencana adalah baris setengah terisi, bukan baris kosong.
    pgM.click("#addPlan"); pgM.wait_for_timeout(150)
    pgM.fill("[data-pd='4']", "2026-10-08")
    pgM.click("#next"); pgM.wait_for_timeout(250)
    check("tanggal tanpa rencana ditolak", pgM.inner_text("body"), lambda s: "Ada tanggal tanpa rencana" in s)
    pgM.fill("[data-pa='4']", "Kunjungi Klien H"); pgM.fill("[data-pd='4']", "")
    pgM.click("#next"); pgM.wait_for_timeout(250)
    check("rencana tanpa tanggal ditolak", pgM.inner_text("body"), lambda s: "Pilih tanggalnya" in s)
    pgM.click("[data-drop='4']"); pgM.wait_for_timeout(200)
    pgM.click("#next"); pgM.wait_for_timeout(300)
    check("empat rencana lolos ke langkah berikutnya", pgM.query_selector("[name=escalation]") is not None)

    pgM.check("[name=declared]"); pgM.click("#next"); pgM.wait_for_timeout(500)
    check("terkirim dengan empat rencana", txt(pgM, "h1"), "Sudah terkirim")
    st = store_of(pgM)
    cs = sorted([c for c in st["commitments"].values() if c["from"] == "2026-09-30"],
                key=lambda c: c["due"])
    check("satu janji per baris rencana", len(cs), 4)
    check("isi janjinya sesuai barisnya",
          [(c["action"], c["due"], c["status"]) for c in cs],
          [("Telepon Klien C", "2026-10-01", "open"),
           ("Kirim quotation Klien D", "2026-10-02", "open"),
           ("Follow up Klien F", "2026-10-06", "open"),
           ("Rapat Klien G", "2026-10-07", "open")])

    pgM.click("#toggle"); pgM.wait_for_timeout(250)
    rep = pgM.inner_text("#reportText")
    check("laporan memakai daftar berbutir", rep, lambda s: "g. Rencana:\n- Telepon Klien C" in s)
    check("semua rencana masuk laporan", rep, lambda s: s.count("\n- ") == 4)
    check("target tiap rencana ikut tertulis", rep, lambda s: "Rapat Klien G (target Rab 7 Okt)" in s)
    pgM.screenshot(path=str(SHOTS) + "/t-multi-plan.png", full_page=True)

    # Besoknya janji-janji itu ditagih satu-satu, dan hanya yang sudah jatuh tempo —
    # inti dari memisah rencana: tiap baris berbunyi di tanggalnya sendiri.
    pgM2 = new_page(many, "u_nicho", at="2026-10-01T04:00:00Z", reset=False)
    for r, o in [("WAG", 8), ("TGG", 3), ("GCG", 2)]: g(pgM2, r, "open", o)
    pgM2.wait_for_timeout(200); pgM2.click("#next"); pgM2.wait_for_timeout(300)
    check("hanya janji hari itu yang ditagih",
          pgM2.eval_on_selector_all("[data-resolve]", "e=>e.length"), 1)
    body2 = pgM2.inner_text("body")
    check("janji yang jatuh tempo hari ini ditanyakan", body2, lambda s: "Telepon Klien C" in s)
    check("janji untuk minggu depan belum diganggu", body2, lambda s: "Rapat Klien G" not in s)
    pgM2.check("[data-resolve]")
    pgM2.check("[name=declared]"); pgM2.click("#next"); pgM2.wait_for_timeout(500)
    st2 = store_of(pgM2)
    done = [c for c in st2["commitments"].values() if c["action"] == "Telepon Klien C"]
    check("yang ditutup hanya janji itu", [c["status"] for c in done], ["kept"])
    check("janji lain tetap terbuka",
          sorted(c["action"] for c in st2["commitments"].values() if c["status"] == "open"),
          ["Follow up Klien F", "Kirim quotation Klien D", "Rapat Klien G"])

    # Seminggu kemudian: yang kelewat dan yang jatuh tempo hari itu ditagih bersama,
    # yang masih di depan tetap diam.
    pgM3 = new_page(many, "u_nicho", at="2026-10-06T04:00:00Z", reset=False)   # Selasa 11:00
    for r, o in [("WAG", 7), ("TGG", 3), ("GCG", 2)]: g(pgM3, r, "open", o)
    pgM3.wait_for_timeout(200); pgM3.click("#next"); pgM3.wait_for_timeout(300)
    body3 = pgM3.inner_text("body")
    check("dua janji ditagih di hari itu",
          pgM3.eval_on_selector_all("[data-resolve]", "e=>e.length"), 2)
    check("janji yang kelewat ditandai lewat", body3,
          lambda s: "Kirim quotation Klien D" in s and "sudah lewat" in s)
    check("janji hari itu ikut ditagih", body3, lambda s: "Follow up Klien F" in s)
    check("janji besok tetap diam", body3, lambda s: "Rapat Klien G" not in s)

    # Koreksi laporan. Di sinilah pencocokan lewat teks menentukan: kalau janji dicocokkan
    # per urutan baris, menghapus baris pertama akan menukar tanggal janji orang.
    print("\n--- koreksi laporan dengan beberapa rencana ---")
    fix = context(b)
    pgF = new_page(fix, "u_nicho", at="2026-09-30T04:00:00Z")
    for r, o in [("WAG", 9), ("TGG", 3), ("GCG", 2)]: g(pgF, r, "open", o)
    g(pgF, "WAG", "gt3", 1); pgF.wait_for_timeout(200)
    pgF.click("#next"); pgF.wait_for_timeout(250)
    pgF.fill("[name=detail]", "WAG Klien C, TGG Klien D")
    pgF.fill("[data-pa='0']", "Telepon Klien C"); pgF.fill("[data-pd='0']", "2026-10-01")
    pgF.click("#addPlan"); pgF.wait_for_timeout(150)
    pgF.fill("[data-pa='1']", "Kirim quotation Klien D"); pgF.fill("[data-pd='1']", "2026-10-02")
    pgF.click("#next"); pgF.wait_for_timeout(250)
    pgF.check("[name=declared]"); pgF.click("#next"); pgF.wait_for_timeout(500)
    promises = lambda pg: sorted(
        [(c["action"], c["due"], c["status"]) for c in store_of(pg)["commitments"].values()])
    check("dua janji sebelum dikoreksi", promises(pgF),
          [("Kirim quotation Klien D", "2026-10-02", "open"),
           ("Telepon Klien C", "2026-10-01", "open")])

    pgF.click("#edit"); pgF.wait_for_timeout(300)
    pgF.click("#next"); pgF.wait_for_timeout(300)
    check("perbaiki laporan: rencananya kembali utuh",
          pgF.eval_on_selector_all("[data-pa]", "e=>e.map(x=>x.value)"),
          ["Telepon Klien C", "Kirim quotation Klien D"])
    check("tanggalnya ikut kembali",
          pgF.eval_on_selector_all("[data-pd]", "e=>e.map(x=>x.value)"),
          ["2026-10-01", "2026-10-02"])

    pgF.fill("[data-pd='1']", "2026-10-05")           # Klien D digeser, Klien C tidak
    pgF.click("#next"); pgF.wait_for_timeout(250)
    pgF.check("[name=declared]"); pgF.click("#next"); pgF.wait_for_timeout(500)
    check("hanya tanggal yang diubah yang bergeser", promises(pgF),
          [("Kirim quotation Klien D", "2026-10-05", "open"),
           ("Telepon Klien C", "2026-10-01", "open")])

    pgF.click("#edit"); pgF.wait_for_timeout(300)
    pgF.click("#next"); pgF.wait_for_timeout(300)
    pgF.click("[data-drop='0']"); pgF.wait_for_timeout(250)      # Klien C dibatalkan
    pgF.click("#next"); pgF.wait_for_timeout(250)
    pgF.check("[name=declared]"); pgF.click("#next"); pgF.wait_for_timeout(500)
    check("baris yang dihapus jadi dibatalkan, bukan hilang", promises(pgF),
          [("Kirim quotation Klien D", "2026-10-05", "open"),
           ("Telepon Klien C", "2026-10-01", "cancelled")])
    check("koreksi tidak menambah janji baru", len(store_of(pgF)["commitments"]), 2)
    check("tetap satu laporan untuk hari itu", len(store_of(pgF)["cycles"]), 1)

    # Satu baris saja tetap satu baris, dan laporannya tidak berubah bentuk.
    one = context(b)
    pg1 = new_page(one, "u_rio", at="2026-09-30T04:00:00Z")
    for r, o in [("WAG", 4), ("TGG", 1), ("GCG", 1)]: g(pg1, r, "open", o)
    g(pg1, "WAG", "lt3", 1); pg1.wait_for_timeout(200)
    pg1.click("#next"); pg1.wait_for_timeout(250)
    pg1.fill("[name=detail]", "WAG Klien Z"); pg1.fill("[data-pa='0']", "Telepon Klien Z")
    pg1.fill("[data-pd='0']", "2026-10-01"); pg1.click("#next"); pg1.wait_for_timeout(250)
    pg1.check("[name=declared]"); pg1.click("#next"); pg1.wait_for_timeout(500)
    pg1.click("#toggle"); pg1.wait_for_timeout(250)
    rep1 = pg1.inner_text("#reportText")
    check("satu rencana tetap satu baris", rep1,
          lambda s: "g. Rencana: Telepon Klien Z (target Kam 1 Okt)" in s and "\n- " not in s)

    print("\n--- data benar-benar dibagi antar orang ---")
    pgN = new_page(shared, "u_nicho", at="2026-09-30T04:00:00Z")          # Rabu 11:00 WIB, store baru
    for r,o in [("WAG",9),("TGG",3),("GCG",2)]: g(pgN,r,"open",o)
    g(pgN,"WAG","gt3",1); pgN.wait_for_timeout(200)
    pgN.click("#next"); pgN.wait_for_timeout(250)
    pgN.fill("[name=detail]","WAG Klien C"); pgN.fill("[data-pa='0']","Telepon besok")
    pgN.fill("[data-pd='0']","2026-10-01"); pgN.click("#next"); pgN.wait_for_timeout(250)
    pgN.fill("[name=escalation]","Minta bantuan Chief soal Klien C")
    pgN.check("[name=declared]"); pgN.click("#next"); pgN.wait_for_timeout(400)
    check("Nicho berhasil kirim", txt(pgN,"h1"), "Sudah terkirim")

    pgC = new_page(shared, "u_chief", at="2026-09-30T04:05:00Z", reset=False)   # store yang sama
    pgC.click('[data-tab="chief"]'); pgC.wait_for_timeout(400)
    body = pgC.inner_text("body")
    check("Chief melihat kiriman Nicho yang baru", "Minta bantuan Chief soal Klien C" in body)
    check("Chief melihat namanya, bukan id", "Nicho" in body and "u_nicho" not in body)
    check("Chief belum lapor -> terhitung", txt(pgC,".stats"), lambda s: "1/2" in s)
    pgC.screenshot(path=str(SHOTS)+"/t-cross.png", full_page=True)
    b.close()

bad = [r for r in results if not r[0]]
print(f"\n{len(results)-len(bad)}/{len(results)} lolos")
# Keluar dengan kode gagal, supaya run_all.py bisa melihatnya. Tanpa baris ini suite
# yang gagal tetap terbaca sukses oleh pemanggilnya.
sys.exit(1 if bad else 0)
