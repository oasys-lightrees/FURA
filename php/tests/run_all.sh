#!/bin/sh
# Every check this folder has. The ones that need a database or a browser skip
# themselves rather than fail when there is none.
#
#   sh php/tests/run_all.sh
#   MESSI_TEST_SOCKET=/var/run/mysqld/mysqld.sock sh php/tests/run_all.sh

set -e
cd "$(dirname "$0")/.."

echo "== aturan mesin =="
MESSI_TEST_DB= php tests/test_engine.php

echo
echo "== halaman sama dengan yang sudah diuji =="
php tests/check_sync.php

echo
echo "== database sungguhan =="
MESSI_TEST_DB=messi_test php tests/test_repo.php

echo
echo "== seminggu penuh, jam demi jam =="
MESSI_TEST_DB=messi_cron_test php tests/test_cron.php

echo
echo "== aplikasi hidup =="
MESSI_TEST_DB=messi_live_test python3 tests/test_live.py

echo
echo "== pemasangan & admin =="
MESSI_TEST_DB=messi_admin_test python3 tests/test_admin.py
