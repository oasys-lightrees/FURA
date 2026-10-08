"""Starts the real thing: php -S over the php/ folder, with its own MySQL database.

Used by the browser tests. Everything it makes is thrown away afterwards, and it skips
rather than fails when there is no database to lend.
"""

from __future__ import annotations

import json
import os
import socket
import subprocess
import sys
import tempfile
import time
from pathlib import Path

PHP_DIR = Path(__file__).resolve().parent.parent
PASSWORD = "kata-sandi-panjang"


def _free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


class Host:
    """A running copy of the app. Use as a context manager."""

    def __init__(self, db: str, seed: str = "", extra: dict | None = None):
        self.db = os.environ.get("MESSI_TEST_DB", db)
        self.seed_arg = seed
        self.port = _free_port()
        self.base = f"http://127.0.0.1:{self.port}"
        self.dir = Path(tempfile.mkdtemp(prefix="messi-live-"))
        self.config = self.dir / "config.php"
        socket_path = os.environ.get("MESSI_TEST_SOCKET", "")
        # Written as JSON the PHP side decodes, rather than hand-built PHP syntax — a
        # base_url with a "://" in it defeats naive string surgery.
        self.config.write_text(
            "<?php return json_decode(<<<'JSON'\n"
            + json.dumps({
                "db": {
                    "host": os.environ.get("MESSI_TEST_HOST", "" if socket_path else "localhost"),
                    "socket": socket_path,
                    "name": self.db,
                    "user": os.environ.get("MESSI_TEST_USER", "root"),
                    "pass": os.environ.get("MESSI_TEST_PASS", ""),
                },
                "base_url": self.base,
                "chat_webhook": "",
                "chat_webhook_leader": "",
                "cron_key": "test-key",
                "first_day": "2026-09-28",
                "session_days": 30,
                # Bawaannya mail_from kosong, jadi pemasangan ini tidak menjanjikan email
                # apa pun — persis seperti pemasangan yang belum mengisinya. Tes yang
                # memang tentang email menyalakannya lewat `extra`.
                **(extra or {}),
            }, indent=2)
            + "\nJSON, true);\n"
        )
        self.env = {**os.environ, "MESSI_CONFIG_FILE": str(self.config), "MESSI_TEST_DB": self.db}
        self.server: subprocess.Popen | None = None

    def php(self, *args: str) -> subprocess.CompletedProcess:
        return subprocess.run(["php", *args], env=self.env, capture_output=True, text=True,
                              cwd=str(PHP_DIR))

    def __enter__(self) -> "Host":
        seed = self.php(str(PHP_DIR / "tests" / "seed_live.php"),
                        *( [self.seed_arg] if self.seed_arg else [] ))
        if seed.returncode != 0:
            # Same rule as bootstrap_test.php: a skip is honest on a machine with no
            # database, and a lie once somebody has pointed at one.
            told = os.environ.get("MESSI_TEST_SOCKET") or os.environ.get("MESSI_TEST_HOST")
            where = "GAGAL  database yang kamu tunjuk tidak bisa dipakai" if told \
                else "DILEWATI  tidak ada database"
            print(where + "\n" + (seed.stderr or seed.stdout).strip())
            sys.exit(1 if told else 0)
        print(seed.stdout.strip())

        self.server = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{self.port}", "-t", str(PHP_DIR)],
            env=self.env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(60):
            try:
                with socket.create_connection(("127.0.0.1", self.port), 0.2):
                    return self
            except OSError:
                time.sleep(0.1)
        return self

    def __exit__(self, *exc) -> None:
        if self.server:
            self.server.terminate()
            self.server.wait(timeout=10)


def accept_invite(ctx, link: str, password: str, results=None, enter="messi"):
    """Menerima undangan lalu berada di dalam aplikasi, seperti orangnya sendiri.

    Menerima undangan sudah memulai sesinya, jadi halaman ini tidak perlu — dan tidak
    bisa — lewat halaman login lagi.
    """
    pg = ctx.new_page()
    if results is not None:
        pg.on("pageerror", lambda e: results.append((False, "JS error: " + str(e), "")))
    if enter:
        pg.add_init_script(
            f'try {{ localStorage.setItem("fura.seen.{enter}", "1"); }} catch (e) {{}}')
    pg.goto(link)
    pg.wait_for_load_state("networkidle")
    pg.fill("#password", password)
    pg.fill("#password2", password)
    pg.click("button[type=submit]")
    pg.wait_for_load_state("networkidle")
    try:
        pg.wait_for_selector("#view *", timeout=10_000)
    except Exception:
        pass
    pg.wait_for_timeout(200)
    if enter and pg.query_selector(f"[data-mod={enter}]"):
        pg.click(f"[data-mod={enter}]")
        pg.wait_for_timeout(300)
    return pg


def sign_in(ctx, base: str, email: str, password: str = PASSWORD, results=None,
            enter="messi"):
    """A browser page signed in as `email`, sitting on whatever the app showed next.

    Signing in lands on the module catalogue, so this walks into MESSI the way a
    returning user does — with the explainer already read. Pass `enter=None` to stay
    on the catalogue.
    """
    pg = ctx.new_page()
    if results is not None:
        pg.on("pageerror", lambda e: results.append((False, "JS error: " + str(e), "")))
    if enter:
        pg.add_init_script(
            f'try {{ localStorage.setItem("fura.seen.{enter}", "1"); }} catch (e) {{}}')
    pg.goto(base + "/login.php")
    pg.fill("#email", email)
    pg.fill("#password", password)
    pg.click("button[type=submit]")
    pg.wait_for_load_state("networkidle")
    # Halamannya menggambar setelah boot(), jadi menunggu "networkidle" saja belum tentu
    # cukup di host yang sedang sibuk — tunggu sampai ada yang benar-benar tergambar.
    try:
        pg.wait_for_selector("#view *", timeout=10_000)
    except Exception:                       # halaman login, atau galat: biar pemanggil yang menilai
        pass
    pg.wait_for_timeout(200)
    if enter and pg.query_selector(f"[data-mod={enter}]"):
        pg.click(f"[data-mod={enter}]")
        pg.wait_for_timeout(300)
    return pg
