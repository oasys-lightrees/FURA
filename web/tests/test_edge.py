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

    print("\n--- data benar-benar dibagi antar orang ---")
    pgN = new_page(shared, "u_nicho", at="2026-09-30T04:00:00Z")          # Rabu 11:00 WIB, store baru
    for r,o in [("WAG",9),("TGG",3),("GCG",2)]: g(pgN,r,"open",o)
    g(pgN,"WAG","gt3",1); pgN.wait_for_timeout(200)
    pgN.click("#next"); pgN.wait_for_timeout(250)
    pgN.fill("[name=detail]","WAG Klien C"); pgN.fill("[name=plan]","Telepon besok")
    pgN.fill("[name=due]","2026-10-01"); pgN.click("#next"); pgN.wait_for_timeout(250)
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
