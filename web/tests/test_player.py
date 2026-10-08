import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from harness import (SHOTS, check, context, launch, new_page, ready, report, results,
                     store_of, txt, sync_playwright)

# Rabu 30 Sep, 11:00 WIB — sebelum tenggat. Dibekukan supaya tanggal-tanggal di bawah
# tetap berarti hal yang sama berapa pun hari ini dijalankan.
AT = "2026-09-30T04:00:00Z"

with sync_playwright() as p:
    b = launch(p)
    ctx = context(b)

    print("\n=== USER (Nicho, bukan owner) ===")
    pg = new_page(ctx, "u_nicho", at=AT)
    # Totalnya belum pasti di langkah pertama — kalau ada yang gantung, satu langkah lagi
    # muncul. Jadi di sini angkanya saja; dulu tertulis "DARI 2" lalu berubah jadi "DARI 3".
    check("halaman termuat di langkah 1", txt(pg,".stepno"), "LANGKAH 1")
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
    pg.reload(); ready(pg)
    check("draft bertahan setelah halaman ditutup",
          pg.input_value('[data-row=WAG][data-col=open]'), "13")

    pg.click('[data-goto="1"]') if pg.query_selector('[data-goto="1"]') else None
    pg.wait_for_timeout(200)
    if txt(pg,".stepno") != "LANGKAH 1":
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
    pg.fill("[data-pa='0']","Balas setelah harga di-approve")
    pg.click("#next"); pg.wait_for_timeout(200)
    check("tanggal wajib", "Pilih tanggalnya" in txt(pg,".bar .err"))
    pg.fill("[data-pd='0']","2026-10-02")
    pg.wait_for_timeout(150)
    # Kotak tanggal bawaan browser menuliskan dirinya mm/dd/yyyy kalau bahasa browsernya
    # Inggris — berapa pun bahasa halamannya — jadi "10/02/2026" bisa berarti dua tanggal.
    check("tanggal yang dipilih ikut dibacakan", txt(pg,"[data-tgl='0']"),
          lambda s: "2 Okt" in s)
    pg.fill("[data-pd='0']","2026-10-03")        # Sabtu
    pg.wait_for_timeout(150)
    check("dan akhir pekan disebutkan sebelum sempat dijanjikan",
          txt(pg,"[data-tgl='0']"), lambda s: "bukan hari kerja" in s)
    pg.fill("[data-pd='0']","2026-10-02")
    pg.wait_for_timeout(150)
    check("hari kerja tidak diberi peringatan apa-apa", txt(pg,"[data-tgl='0']"),
          lambda s: "bukan hari kerja" not in s)
    pg.click("#next"); pg.wait_for_timeout(250)
    check("sampai layar cek", txt(pg,".stepno"), "LANGKAH 3 DARI 3")
    check("ringkasan menampilkan angka", txt(pg,".rev"), lambda s: "21" in s and "3" in s)

    pg.fill("[name=escalation]","Butuh approve harga Klien A")
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

    pg.reload(); ready(pg)
    check("setelah refresh tetap 'sudah terkirim'", txt(pg,"h1"), "Sudah terkirim")
    check("janji tampil di layar selesai", "Balas setelah harga" in pg.inner_text("body"))

    print("\n--- janji ditutup pada hari yang justru bersih ---")
    # Jalur yang paling mungkin terjadi: kamu janji mengejar Klien A, besoknya kamu
    # benar-benar mengejarnya, jadi tidak ada lagi yang gantung. Kalau kotak centang
    # janji hanya ada di langkah "gantung", langkah itu dilewati — dan orang yang
    # MENEPATI janjinya justru tidak bisa menutupnya. Janjinya lalu patah sendiri.
    from harness import store_of as _store
    DUE = "2026-09-30"
    pgC = new_page(ctx, "u_nicho", at=AT, store={
      "roster": {"u_nicho": {"name": "Nicho", "joined": "2026-09-28"}},
      "cycles": {},
      "commitments": {"c-1": {"id": "c-1", "owner": "u_nicho", "action": "Telepon Klien A",
                              "due": DUE, "status": "open", "from": "2026-09-29"}}})
    for row, v in [("WAG", 6), ("TGG", 2), ("GCG", 1)]:
        pgC.fill(f"[data-row={row}][data-col=open]", str(v))
    pgC.fill("[data-row=WAG][data-col=reply]", "6")
    pgC.wait_for_timeout(200)
    check("hari bersih memang melewati langkah gantung",
          txt(pgC, ".stepno"), "LANGKAH 1")
    pgC.click("#next"); pgC.wait_for_timeout(300)
    check("janji yang jatuh tempo tetap muncul di langkah kirim",
          pgC.query_selector('[data-resolve="c-1"]') is not None)
    check("lengkap dengan aksinya", txt(pgC, "body"), lambda s: "Telepon Klien A" in s)
    pgC.check('[data-resolve="c-1"]')
    pgC.check("[name=declared]")
    pgC.click("#next"); pgC.wait_for_timeout(500)
    check("laporannya terkirim", txt(pgC, "h1"), "Sudah terkirim")
    check("dan janjinya tercatat DITEPATI, bukan patah",
          _store(pgC)["commitments"]["c-1"]["status"], "kept")

    print("\n--- salah ketik, dibetulkan ---")
    pg.click("#edit"); pg.wait_for_timeout(350)
    check("laporan bisa dibuka lagi", txt(pg,"h1"), "Berapa banyak hari ini?")
    check("angkanya tidak hilang, tinggal dibetulkan",
          pg.input_value("[data-row=WAG][data-col=open]"), "13")
    check("yang ditulis sebelumnya juga masih ada", txt(pg,"body"), lambda s: "LANGKAH 1" in s)
    pg.fill("[data-row=GCG][data-col=open]", "9"); pg.wait_for_timeout(200)
    pg.click("#next"); pg.wait_for_timeout(250)
    check("yang gantung tetap terisi dari kiriman pertama",
          pg.input_value("[name=detail]"), lambda v: v.strip() != "")
    pg.click("#next"); pg.wait_for_timeout(250)
    check("deklarasinya diminta lagi — angkanya sudah berubah",
          pg.eval_on_selector("[name=declared]", "e=>e.checked"), False)
    pg.check("[name=declared]"); pg.click("#next"); pg.wait_for_timeout(400)
    check("perbaikan terkirim", txt(pg,"h1"), "Sudah terkirim")
    st2 = store_of(pg)
    check("tetap satu laporan untuk hari itu, bukan dua", len(st2["cycles"]), 1)
    check("angka barunya yang tersimpan",
          list(st2["cycles"].values())[0]["grid"]["GCG"]["open"], 9)

    print("\n--- terang / gelap ---")
    check("ada tombol temanya", pg.query_selector("#theme") is not None)
    get = lambda: pg.evaluate("document.documentElement.getAttribute('data-theme')")
    check("bawaannya mengikuti perangkat, belum dikunci", get() is None)
    pg.click("#theme"); pg.wait_for_timeout(250)
    first = get()
    check("sekali ditekan, temanya terkunci", first in ("light", "dark"))
    pg.reload(); ready(pg)
    check("pilihannya bertahan setelah halaman ditutup", get(), first)
    pg.click("#theme"); pg.wait_for_timeout(250)
    check("bisa dibalik lagi", get(), lambda t: t in ("light", "dark") and t != first)

    open(str(SHOTS)+"/store.json","w").write(json.dumps(store_of(pg)))
    pg.screenshot(path=str(SHOTS)+"/t-user-done.png", full_page=True)
    b.close()

bad = [r for r in results if not r[0]]
print(f"\n{len(results)-len(bad)}/{len(results)} lolos")
sys.exit(1 if bad else 0)
