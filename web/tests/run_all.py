"""Runs every browser suite and reports one total.  python3 web/tests/run_all.py"""

import re
import subprocess
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
SUITES = ["test_player.py", "test_manager.py", "test_edge.py", "test_keyboard.py",
          "test_catalog.py"]

failed, lines = [], []
for name in SUITES:
    print(f"\n{'=' * 60}\n{name}\n{'=' * 60}")
    r = subprocess.run([sys.executable, str(HERE / name)], text=True, capture_output=True)
    print(r.stdout.rstrip())
    if r.stderr.strip():
        print(r.stderr.rstrip(), file=sys.stderr)
    tail = [l for l in r.stdout.splitlines() if "lolos" in l]
    summary = tail[-1].strip() if tail else "tidak selesai"
    lines.append(f"  {name:<20} {summary}")
    # Dua-duanya diperiksa. Exit code pernah berbohong karena suite-nya lupa keluar
    # dengan kode gagal; angka "x/y lolos" yang tidak sama besar juga tidak boleh lolos.
    counts = re.match(r"^(\d+)/(\d+) lolos", summary)
    if r.returncode != 0 or not counts or counts.group(1) != counts.group(2):
        failed.append(name)
        # Namanya saja tidak cukup untuk ditindaklanjuti, apalagi kalau kegagalannya
        # sesekali: yang perlu terbaca di ringkasan adalah pemeriksaan mana yang jatuh.
        for l in r.stdout.splitlines():
            if l.startswith("FAIL "):
                lines.append(f"  {'':<20} ↳ {l[5:].split('   ->')[0]}")

print(f"\n{'=' * 60}\nRINGKASAN\n{'=' * 60}")
print("\n".join(lines))
print("\nSEMUA LOLOS" if not failed else "\nGAGAL: " + ", ".join(failed))
sys.exit(1 if failed else 0)
