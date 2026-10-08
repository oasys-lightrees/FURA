"""Membuat modul sendiri, lewat halamannya — bukan lewat fungsinya.

Yang diuji di sini satu janji: perusahaan bisa menyusun modulnya sendiri tanpa menyentuh
kode. Jadi semuanya dikerjakan seperti admin mengerjakannya — mengetik, menekan tombol,
memuat ulang — karena fungsinya bisa benar sementara halamannya membuang yang baru
diketik, dan yang kedua itulah yang membuat orang berhenti memakainya.

    MESSI_TEST_SOCKET=/var/run/mysqld/mysqld.sock python3 php/tests/test_modul_page.py
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


def labels(pg):
    """Judul tiap pertanyaan — bukan judul baris dan kolom di dalamnya.

    f[2][rows][0][label] juga berakhiran "[label]", jadi memilih lewat akhiran saja
    diam-diam ikut menyeret isi tabel angkanya.
    """
    return pg.eval_on_selector_all(
        "input[name^='f[']",
        "e=>e.filter(x=>/^f\\[\\d+\\]\\[label\\]$/.test(x.name)).map(x=>x.value)")


def nfields(pg):
    return pg.eval_on_selector_all(".fld", "e=>e.length")


with Host("messi_modulpage") as host, sync_playwright() as p:
    browser = launch(p)
    ctx = browser.new_context(viewport={"width": 1100, "height": 900})
    host.php("-r", """require 'lib/bootstrap.php';
        q("UPDATE users SET role='owner' WHERE email='lead@example.test'");""")

    pg = sign_in(ctx, host.base, "lead@example.test", PASSWORD, results=results, enter=None)

    print("\n=== MESSI sudah ada di katalog, bukan modul contoh kosong ===")
    pg.goto(host.base + "/modul.php")
    pg.wait_for_load_state("networkidle")
    tabel = pg.inner_text("table.mods")
    check("MESSI terdaftar", tabel, lambda s: "MESSI" in s)
    check("dengan kepanjangannya", tabel, lambda s: "Messenger Screening" in s)
    # Lima pertanyaan MESSI: angka, yang mana, rencana, minta bantuan, calon project.
    check("dan lima pertanyaannya ikut terbaca", tabel, lambda s: "5 pertanyaan" in s)
    check("beserta jamnya", tabel, lambda s: "09:00–18:00" in s)

    print("\n=== MESSI diubah di Pertanyaan, dan hanya di sana ===")
    # Dua tempat yang menyimpan satu bentuk akan berbeda, dan yang berbeda tidak kelihatan
    # berbeda. Jadi barisnya mengantar ke halaman Pertanyaan, bukan ke formulir yang
    # tulisannya akan ditimpa begitu setelan timnya disimpan.
    baris = pg.locator("table.mods tr", has_text="MESSI")
    check("barisnya menunjuk ke Pertanyaan, bukan ke penyusun",
          baris.inner_text(), lambda s: "Ubah di Pertanyaan" in s and "Susun" not in s)
    check("dan tidak menawarkan mematikan atau menghapusnya",
          baris.inner_text(), lambda s: "Matikan" not in s and "Hapus" not in s)

    # Yang tetap mengetikkan ?id= miliknya diantar, bukan dibiarkan mengetik sia-sia.
    mid = pg.get_attribute("table.mods tr:has-text('MESSI') input[name=id]", "value")
    pg.goto(host.base + "/modul.php?id=" + str(mid))
    pg.wait_for_load_state("networkidle")
    check("membuka penyusunnya langsung pun diantar ke Pertanyaan",
          pg.inner_text(".note.warn"), lambda s: "halaman Pertanyaan" in s)
    check("dan formulir penyusunnya tidak digambar",
          pg.locator(".fld").count(), 0)

    # Tanpa MESSI, repo_module_id() tidak menemukan apa pun, laporan tersimpan tanpa modul,
    # dan kunci unik (user_id, day, module_id) berhenti menahan apa pun — MySQL menganggap
    # dua NULL sebagai dua nilai berbeda.
    dipaksa = pg.evaluate(
        """async ([url, csrf, id, team]) => {
             const out = [];
             for (const act of ["hapus", "nonaktif"]) {
               const body = new URLSearchParams({ do: act, csrf, id, team });
               const r = await fetch(url, { method: "POST", body });
               out.push((await r.text()).includes("tidak bisa dimatikan atau dihapus"));
             }
             return out;
           }""",
        [host.base + "/modul.php", pg.get_attribute("input[name=csrf]", "value"), mid,
         pg.get_attribute("table.mods tr:has-text('MESSI') input[name=team]", "value")])
    check("dipaksa lewat kiriman mentah pun MESSI tidak bisa dihapus", dipaksa[0], True)
    check("maupun dimatikan", dipaksa[1], True)

    print("\n=== membuat modul sendiri ===")
    pg.fill("#nk", "pr-ista 2!")              # dikotori sengaja
    pg.fill("#nn", "Prista")
    pg.click("button:has-text('Buat modul')")
    pg.wait_for_load_state("networkidle")
    check("kodenya dibersihkan jadi huruf dan angka",
          pg.inner_text(".note.ok"), lambda s: "PRISTA2" in s)
    check("dan halamannya langsung membuka penyusunnya",
          pg.inner_text("body"), lambda s: "Susun: Prista" in s)
    check("modul baru lahir dengan satu pertanyaan, bukan layar kosong", nfields(pg), 1)

    pg.goto(host.base + "/modul.php")
    pg.wait_for_load_state("networkidle")
    pg.fill("#nk", "PRISTA2")
    pg.fill("#nn", "Lagi")
    pg.click("button:has-text('Buat modul')")
    pg.wait_for_load_state("networkidle")
    check("kode yang sudah dipakai ditolak",
          pg.inner_text(".note.bad"), lambda s: "sudah ada modul" in s.lower())

    print("\n=== menambah tiap jenis pertanyaan ===")
    baris = pg.locator("table.mods tr", has_text="PRISTA2")
    baris.locator("a:has-text('Susun')").click()
    pg.wait_for_load_state("networkidle")

    for jenis, judul in [("grid", "Berapa banyak hari ini?"),
                         ("plans", "Mau diapakan?"),
                         ("choice", "Pilih salah satu"),
                         ("number", "Pertanyaan baru"),
                         ("text", "Pertanyaan baru")]:
        pg.select_option("#nt", jenis)
        pg.click("button:has-text('Tambah')")
        pg.wait_for_load_state("networkidle")
        # Pertanyaan yang lahir tanpa judul pernah terjadi: "+" pada array PHP membuat
        # label kosong mengalahkan label contohnya.
        check(f"{jenis} lahir dengan judul contohnya", labels(pg)[-1], judul)

    check("semuanya tersusun", nfields(pg), 6)
    check("pertanyaan angka membawa baris dan kolom contoh",
          pg.eval_on_selector_all("input[name='f[1][rows][0][key]']", "e=>e.map(x=>x.value)"),
          ["WAG"])
    check("dengan satu kolom yang menyalakan lampu merahnya",
          pg.eval_on_selector_all("select[name='f[1][cols][1][flag]']", "e=>e.map(x=>x.value)"),
          ["merah"])

    print("\n=== menekan tombol tidak membuang yang sudah diketik ===")
    # Janji utama halaman ini. Tanpa ini, menambah pertanyaan setelah mengetik lima menit
    # membuang lima menit itu, dan orang berhenti mempercayai tombol-tombolnya.
    pg.fill("input[name='f[0][label]']", "Apa yang terjadi hari ini?")
    pg.fill("input[name='f[2][due_label]']", "Kelar kapan?")
    pg.select_option("#nt", "textarea")
    pg.click("button:has-text('Tambah')")
    pg.wait_for_load_state("networkidle")
    check("yang diketik sebelum menambah tetap ada", labels(pg)[0], "Apa yang terjadi hari ini?")
    check("termasuk isian di dalam pertanyaan rencana",
          pg.input_value("input[name='f[2][due_label]']"), "Kelar kapan?")
    check("dan pertanyaannya bertambah satu", nfields(pg), 7)

    print("\n=== menggeser dan menghapus ===")
    urutan = labels(pg)
    pg.locator(".fld").nth(1).locator("button[value=fup]").click()
    pg.wait_for_load_state("networkidle")
    check("digeser ke atas memang tukar tempat dengan yang di atasnya",
          labels(pg)[:2], [urutan[1], urutan[0]])

    pg.on("dialog", lambda d: d.accept())
    sebelum = nfields(pg)
    pg.locator(".fld").nth(0).locator("button[value=rmfield]").click()
    pg.wait_for_load_state("networkidle")
    check("dihapus memang berkurang satu", nfields(pg), sebelum - 1)
    check("dan yang terhapus memang yang itu", labels(pg), lambda L: urutan[1] not in L)

    print("\n=== tersimpan betulan ===")
    pg.fill("input[name='f[0][label]']", "Judul yang disimpan")
    pg.select_option("select[name='f[0][when]']", "merah")
    pg.fill("input[name=name]", "PRISTA Dua")
    pg.fill("input[name=what]", "Status tiap project yang berjalan.")
    pg.uncheck("input[name='workdays[]'][value='5']")
    pg.select_option("select[name=due_hour]", "17")
    pg.click("button:has-text('Simpan')")
    pg.wait_for_load_state("networkidle")
    check("halamannya mengatakan tersimpan",
          pg.inner_text(".note.ok"), lambda s: "Tersimpan" in s)

    pg.reload()
    pg.wait_for_load_state("networkidle")
    check("judul pertanyaannya bertahan", labels(pg)[0], "Judul yang disimpan")
    check("syarat 'hanya kalau merah' bertahan",
          pg.input_value("select[name='f[0][when]']"), "merah")
    check("nama modulnya bertahan", pg.input_value("input[name=name]"), "PRISTA Dua")
    check("jam tutupnya bertahan", pg.input_value("select[name=due_hour]"), "17")
    check("hari yang dimatikan bertahan mati",
          pg.is_checked("input[name='workdays[]'][value='5']"), False)

    print("\n=== kodenya tidak bisa diganti, walau dipaksa ===")
    # Jawaban yang sudah tersimpan menunjuk ke kode itu. Menggantinya membuat laporan lama
    # kehilangan modulnya — dan yang terlihat bukan pesan kesalahan, cuma rekap yang kosong.
    dipaksa = pg.evaluate(
        """async ([url, csrf, id, team]) => {
             const body = new URLSearchParams({ do: "simpan", csrf, id, team,
               key: "LAIN", name: "Coba Ganti" });
             const r = await fetch(url, { method: "POST", body });
             return (await r.text()).includes("PRISTA2");
           }""",
        [host.base + "/modul.php", pg.get_attribute("input[name=csrf]", "value"),
         pg.input_value("input[name=id]"), pg.input_value("input[name=team]")])
    check("kode modulnya tetap yang lama", dipaksa, True)

    print("\n=== menyalakan, mematikan, menghapus ===")
    pg.goto(host.base + "/modul.php")
    pg.wait_for_load_state("networkidle")
    baris = pg.locator("table.mods tr", has_text="PRISTA2")
    baris.locator("button[value=nonaktif]").click()
    pg.wait_for_load_state("networkidle")
    check("yang dimatikan dikatakan dimatikan",
          pg.inner_text(".note.ok"), lambda s: "dimatikan" in s.lower())
    check("tapi tetap terdaftar untuk admin",
          pg.inner_text("table.mods"), lambda s: "PRISTA2" in s)

    baris = pg.locator("table.mods tr", has_text="PRISTA2")
    baris.locator("button[value=hapus]").click()
    pg.wait_for_load_state("networkidle")
    check("yang belum pernah dilaporkan boleh dihapus",
          pg.inner_text("table.mods"), lambda s: "PRISTA2" not in s)

    # Begitu ada satu laporan saja, modulnya jadi bagian dari riwayat: menghapusnya
    # membuat laporan lama tidak bisa dibaca lagi.
    pg.fill("#nk", "LAPOR1")
    pg.fill("#nn", "Sudah dilapor")
    pg.click("button:has-text('Buat modul')")
    pg.wait_for_load_state("networkidle")
    host.php("-r", """require 'lib/bootstrap.php';
        $m = q1("SELECT id, team_id FROM modules WHERE code = 'LAPOR1' LIMIT 1");
        $u = q1("SELECT id FROM users WHERE email = 'nicho\\@example.test'");
        q('INSERT INTO cycles (user_id, day, team_id, module_id, status, answers,
                               submitted_at, created_at) VALUES (?,?,?,?,?,?,?,?)',
          [$u['id'], Clock::today(), $m['team_id'], $m['id'], 'submitted', '{}',
           Clock::nowUtcSql(), Clock::nowUtcSql()]);""")
    pg.goto(host.base + "/modul.php")
    pg.wait_for_load_state("networkidle")
    baris = pg.locator("table.mods tr", has_text="LAPOR1")
    baris.locator("button[value=hapus]").click()
    pg.wait_for_load_state("networkidle")
    check("yang sudah punya laporan tidak bisa dihapus",
          pg.inner_text(".note.bad"), lambda s: "sudah punya laporan" in s)
    check("dan ditawari jalan lain", pg.inner_text(".note.bad"),
          lambda s: "nonaktifkan" in s.lower())

    print("\n=== yang tidak boleh terjadi ===")
    pctx = browser.new_context(viewport={"width": 420, "height": 900})
    pemain = sign_in(pctx, host.base, "nicho@example.test", PASSWORD, results=results, enter=None)
    check("pemain tidak bisa membuka halaman modul",
          pemain.evaluate("""async () => (await fetch("modul.php")).status"""), 403)
    pctx.close()
    ctx.close()

sys.exit(report("modul yang disusun sendiri"))
