"""Runs every browser suite and reports one total.  python3 web/tests/run_all.py"""

import subprocess
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
SUITES = ["test_player.py", "test_manager.py", "test_edge.py", "test_keyboard.py"]

failed, lines = [], []
for name in SUITES:
    print(f"\n{'=' * 60}\n{name}\n{'=' * 60}")
    r = subprocess.run([sys.executable, str(HERE / name)], text=True, capture_output=True)
    print(r.stdout.rstrip())
    if r.stderr.strip():
        print(r.stderr.rstrip(), file=sys.stderr)
    tail = [l for l in r.stdout.splitlines() if "lolos" in l]
    lines.append(f"  {name:<20} {tail[-1].strip() if tail else 'tidak selesai'}")
    if r.returncode != 0:
        failed.append(name)

print(f"\n{'=' * 60}\nRINGKASAN\n{'=' * 60}")
print("\n".join(lines))
print("\nSEMUA LOLOS" if not failed else "\nGAGAL: " + ", ".join(failed))
sys.exit(1 if failed else 0)
