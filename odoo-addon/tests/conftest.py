"""Puts messi/ on sys.path so these tests import `lib` directly.

They live outside the addon package on purpose: importing anything under messi/ makes
pytest load messi/__init__.py, which imports the Odoo models. messi/tests/ is reserved
for Odoo's own TransactionCase tests, which do need a running Odoo.
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "messi"))
