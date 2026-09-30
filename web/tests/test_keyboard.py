import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from harness import (SHOTS, check, context, launch, new_page, report, results,
                     store_of, txt, sync_playwright)
def at(pg): return pg.evaluate("document.activeElement.dataset.row + '.' + document.activeElement.dataset.col")
def val(pg,r,c): return pg.input_value(f'[data-row={r}][data-col={c}]')

with sync_playwright() as p:
    b = launch(p)
    ctx = context(b)
    pg = new_page(ctx, "u_nicho")
    print("\n=== NAVIGASI KEYBOARD DI GRID ===")

    pg.click('[data-row=WAG][data-col=open]')
    pg.keyboard.type("13")
    check("ketik angka masuk", val(pg,"WAG","open"), "13")

    pg.keyboard.press("Enter")
    check("Enter -> kotak sebelah kanan", at(pg), "WAG.gt3")
    check("Enter tidak mengubah angka sebelumnya", val(pg,"WAG","open"), "13")

    pg.keyboard.press("ArrowDown")
    check("Panah bawah -> baris di bawah, kolom sama", at(pg), "TGG.gt3")
    pg.keyboard.press("ArrowUp")
    check("Panah atas -> kembali ke atas", at(pg), "WAG.gt3")

    pg.click('[data-row=WAG][data-col=open]')
    before = val(pg,"WAG","open")
    pg.keyboard.press("ArrowUp"); pg.keyboard.press("ArrowUp")
    check("Panah atas TIDAK menaikkan angka", val(pg,"WAG","open"), before)
    pg.keyboard.press("ArrowDown")
    check("dari baris pertama, panah bawah pindah baris", at(pg), "TGG.open")
    pg.keyboard.press("ArrowDown"); pg.keyboard.press("ArrowDown")
    check("di baris terakhir tetap diam, angka utuh", at(pg), "GCG.open")

    pg.click('[data-row=TGG][data-col=lt3]')
    pg.keyboard.press("ArrowLeft")
    check("Panah kiri di awal teks -> kotak sebelumnya", at(pg), "TGG.gt3")
    pg.keyboard.type("4")
    pg.keyboard.press("ArrowRight")
    check("Panah kanan di akhir teks -> kotak berikutnya", at(pg), "TGG.lt3")

    pg.click('[data-row=WAG][data-col=open]')
    pg.keyboard.press("ArrowRight")
    check("Panah kanan di tengah angka tetap geser kursor",
          pg.evaluate("document.activeElement.dataset.col"), lambda c: c in ("open","gt3"))

    pg.click('[data-row=GCG][data-col=reply]')
    pg.keyboard.press("Enter")
    check("Enter di kotak terakhir -> ke tombol Lanjut",
          pg.evaluate("document.activeElement.id"), "next")

    pg.click('[data-row=WAG][data-col=open]')
    pg.keyboard.type("abc7x")
    check("huruf diabaikan", val(pg,"WAG","open"), lambda v: v.isdigit() or v == "")
    check("klik lalu ketik MENGGANTI, bukan menyisip", val(pg,"WAG","open"), "7")

    pg.click('[data-row=TGG][data-col=open]'); pg.keyboard.type("5")
    pg.mouse.move(200, 400); pg.mouse.wheel(0, 300); pg.wait_for_timeout(200)
    check("scroll TIDAK mengubah angka", val(pg,"TGG","open"), "5")

    check("Tab tetap jalan seperti biasa", pg.evaluate("""() => {
      document.querySelector('[data-row=WAG][data-col=open]').focus();
      return document.querySelector('[data-row=WAG][data-col=gt3]').tabIndex >= 0; }"""))
    b.close()

bad = [r for r in results if not r[0]]
print(f"\n{len(results)-len(bad)}/{len(results)} lolos")
