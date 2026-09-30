"""Getting a squad onto the system, and off it again.

The parts nobody thinks to test until the day they are needed: the first account on a
fresh install, adding somebody, changing what they are allowed to see, resetting a
password, and handing somebody a one-time link when they are locked out.

    MESSI_TEST_SOCKET=/var/run/mysqld/mysqld.sock python3 php/tests/test_admin.py
"""

from __future__ import annotations

import json
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

NEW_PASSWORD = "password-baru-yang-panjang"


def status(pg, path, base):
    return pg.evaluate("async p => (await fetch(p)).status", base + path)


with Host("messi_admin_test", seed="empty") as host, sync_playwright() as p:
    browser = launch(p)
    ctx = browser.new_context(viewport={"width": 1000, "height": 900})

    print("\n=== pemasangan pertama ===")
    pg = ctx.new_page()
    pg.on("pageerror", lambda e: results.append((False, "JS error: " + str(e), "")))
    pg.goto(host.base + "/index.php")
    check("tanpa akun pun tidak bocor, hanya dialihkan ke login",
          pg.url, lambda u: u.endswith("/login.php"))

    pg.goto(host.base + "/setup.php")
    pg.fill("#name", "Chief")
    pg.fill("#email", "chief@example.test")
    pg.fill("#password", PASSWORD)
    pg.click("button[type=submit]")
    pg.wait_for_load_state("networkidle")
    check("akun pertama dibuat lalu diantar ke login", pg.url, lambda u: u.endswith("/login.php"))

    pg.goto(host.base + "/setup.php")
    check("setup menolak jalan untuk kedua kalinya",
          pg.inner_text("body"), lambda s: "Sudah ada akun" in s)

    print("\n=== masuk sebagai admin ===")
    chief = sign_in(ctx, host.base, "chief@example.test", results=results)
    check("admin masuk ke aplikasinya", chief.inner_text("h1"),
          lambda s: "Berapa banyak" in s or "Sudah terkirim" in s)
    check("admin melihat pintu ke halaman squad", chief.inner_text("footer"),
          lambda s: "Squad" in s)

    chief.goto(host.base + "/admin.php")
    check("halaman squad terbuka", chief.inner_text("h1"), "Squad OASYS")
    check("dirinya sendiri tercatat", chief.inner_text("body"), lambda s: "chief@example.test" in s)
    check("perannya sendiri tidak bisa diturunkan sendiri",
          chief.inner_text("body"), lambda s: "admin (kamu)" in s)

    print("\n=== menambah orang ===")
    chief.fill("#n", "Nicho")
    chief.fill("#e", "nicho@example.test")
    chief.fill("#p", PASSWORD)
    chief.select_option("#r", "player")
    chief.click("button:has-text('Tambah')")
    chief.wait_for_load_state("networkidle")
    check("orangnya masuk daftar", chief.inner_text("body"), lambda s: "Nicho ditambahkan" in s)

    chief.fill("#n", "Nicho Lagi")
    chief.fill("#e", "nicho@example.test")
    chief.fill("#p", PASSWORD)
    chief.click("button:has-text('Tambah')")
    chief.wait_for_load_state("networkidle")
    check("email yang sama ditolak", chief.inner_text(".bad"), lambda s: "sudah terdaftar" in s)

    # The browser refuses to submit a short password before the server is troubled with
    # it, which is the right order — so the server's own guard has to be poked directly,
    # because that is the one that holds when the browser is not a browser.
    csrf = chief.get_attribute("input[name=csrf]", "value")
    short = chief.evaluate(
        """async ([url, csrf]) => {
             const body = new URLSearchParams({ do: "add", name: "Pendek",
               email: "pendek@example.test", password: "123", role: "player",
               joined: "2026-09-28", csrf });
             const r = await fetch(url, { method: "POST", body });
             return (await r.text()).includes("minimal 10");
           }""", [host.base + "/admin.php", csrf])
    check("password pendek ditolak juga di server", short)
    chief.reload()
    chief.wait_for_load_state("networkidle")
    check("dan orangnya tidak jadi dibuat",
          chief.inner_text("body"), lambda s: "pendek@example.test" not in s)

    print("\n=== orang baru itu betul-betul bisa masuk ===")
    other = browser.new_context(viewport={"width": 400, "height": 900})
    nicho = sign_in(other, host.base, "nicho@example.test", results=results)
    check("bisa masuk dengan password yang diberikan",
          nicho.inner_text("h1"), "Berapa banyak hari ini?")
    check("player tidak punya tab squad", nicho.eval_on_selector("#tabs", "e=>e.hidden"))
    check("dan tidak bisa membuka halaman squad", status(nicho, "/admin.php", host.base), 403)

    print("\n=== menaikkan jadi leader ===")
    chief.reload()
    chief.wait_for_load_state("networkidle")
    row = chief.locator("tr", has_text="nicho@example.test")
    row.locator("select[name=role]").select_option("leader")
    chief.wait_for_load_state("networkidle")
    check("perannya berubah", chief.inner_text(".ok"), lambda s: "Peran diubah" in s)

    nicho.reload()
    nicho.wait_for_load_state("networkidle")
    nicho.wait_for_timeout(600)
    check("leader baru langsung melihat tab squad", nicho.eval_on_selector("#tabs", "e=>!e.hidden"))
    check("tapi halaman admin tetap tertutup untuk leader",
          status(nicho, "/admin.php", host.base), 403)

    print("\n=== mengganti password ===")
    chief.reload()
    chief.wait_for_load_state("networkidle")
    row = chief.locator("tr", has_text="nicho@example.test")
    row.locator("input[name=password]").fill(NEW_PASSWORD)
    row.locator("button:has-text('Ganti')").click()
    chief.wait_for_load_state("networkidle")
    check("passwordnya diganti", chief.inner_text(".ok"), lambda s: "Password diganti" in s)

    nicho.goto(host.base + "/index.php")
    check("sesi lamanya ikut mati — ganti password berarti keluar di mana-mana",
          nicho.url, lambda u: u.endswith("/login.php"))

    stale = browser.new_context(viewport={"width": 400, "height": 900})
    old = sign_in(stale, host.base, "nicho@example.test", PASSWORD, results=results)
    check("password lama tidak berlaku lagi", old.inner_text(".err"), lambda s: "salah" in s)
    fresh = sign_in(stale, host.base, "nicho@example.test", NEW_PASSWORD, results=results)
    check("password baru berlaku", fresh.inner_text("h1"), "Berapa banyak hari ini?")
    stale.close()

    print("\n=== link masuk sekali pakai ===")
    chief.reload()
    chief.wait_for_load_state("networkidle")
    row = chief.locator("tr", has_text="nicho@example.test")
    row.locator("button:has-text('Link masuk')").click()
    chief.wait_for_load_state("networkidle")
    link = chief.inner_text(".ok code").strip()
    check("linknya lengkap dan sekali pakai", link,
          lambda s: s.startswith(host.base) and "login.php?t=" in s)
    # The warning belongs next to the link itself, not in the notice above it.
    check("admin diingatkan mengirimnya japri, bukan ke space",
          chief.locator(".note.ok", has=chief.locator("code")).inner_text(),
          lambda s: "japri" in s)

    spent = browser.new_context(viewport={"width": 400, "height": 900})
    sp = spent.new_page()
    sp.goto(link)
    sp.wait_for_load_state("networkidle")
    sp.wait_for_timeout(600)
    check("linknya memang memasukkan orangnya", sp.inner_text("h1"),
          lambda s: "Berapa banyak" in s or "Sudah terkirim" in s)
    spent.close()

    again = browser.new_context(viewport={"width": 400, "height": 900})
    ag = again.new_page()
    ag.goto(link)
    ag.wait_for_load_state("networkidle")
    check("dan tidak bisa dipakai kedua kalinya",
          ag.inner_text(".err"), lambda s: "sudah dipakai" in s or "kedaluwarsa" in s)
    again.close()

    print("\n=== menonaktifkan ===")
    chief.reload()
    chief.wait_for_load_state("networkidle")
    row = chief.locator("tr", has_text="nicho@example.test")
    row.locator("button:has-text('Nonaktifkan')").click()
    chief.wait_for_load_state("networkidle")
    gone = browser.new_context(viewport={"width": 400, "height": 900})
    blocked = sign_in(gone, host.base, "nicho@example.test", NEW_PASSWORD, results=results)
    check("yang dinonaktifkan tidak bisa masuk lagi",
          blocked.inner_text(".err"), lambda s: "salah" in s)
    gone.close()

    print("\n=== halaman cek hosting ===")
    check("cek.php tertutup untuk yang belum masuk",
          nicho.evaluate("""async () => { const r = await fetch("cek.php", {credentials:"omit"});
                                          return r.status; }"""), 403)
    chief.goto(host.base + "/cek.php")
    chief.wait_for_load_state("networkidle")
    body = chief.inner_text("body")
    check("admin melihat hasilnya", body, lambda s: "Cek hosting" in s)
    check("versi PHP diperiksa", body, lambda s: "Versi PHP" in s)
    check("tabelnya diperiksa", body, lambda s: "lengkap, enam tabel" in s)
    check("base_url dicocokkan dengan alamat yang sedang dibuka",
          body, lambda s: "base_url" in s)
    check("perintah cron dibuatkan lengkap dengan path-nya",
          body, lambda s: "cron/tick.php" in s)
    check("tidak pernah menampilkan password database",
          "ganti-ini" not in body and "kata-sandi" not in body)

    print("\n=== yang tidak boleh terjadi ===")
    forged = chief.evaluate(
        """async url => {
             const body = new URLSearchParams({ do: "add", name: "Penyusup",
               email: "susup@example.test", password: "password-panjang-sekali",
               role: "admin", joined: "2026-09-28", csrf: "salah" });
             const r = await fetch(url, { method: "POST", body });
             return r.status;
           }""", host.base + "/admin.php")
    check("form tanpa token yang benar ditolak", forged, 403)
    chief.reload()
    chief.wait_for_load_state("networkidle")
    check("dan penyusupnya tidak masuk daftar",
          chief.inner_text("body"), lambda s: "Penyusup" not in s)

    other.close()
    ctx.close()
    browser.close()

sys.exit(report("admin & pemasangan"))
