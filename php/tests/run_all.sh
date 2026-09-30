#!/bin/sh
# Every check this folder has. The ones that need a database or a browser skip
# themselves rather than fail when there is none.
#
#   sh php/tests/run_all.sh
#   MESSI_TEST_SOCKET=/var/run/mysqld/mysqld.sock sh php/tests/run_all.sh

set -e
cd "$(dirname "$0")/.."

echo "== aturan mesin =="
php tests/test_engine.php

echo
echo "== halaman sama dengan yang sudah diuji =="
php tests/check_sync.php

echo
echo "== database sungguhan =="
php tests/test_repo.php

echo
echo "== aplikasi hidup =="
python3 tests/test_live.py
