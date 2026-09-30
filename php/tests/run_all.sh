#!/bin/sh
# Every check this folder has.
#
#   sh php/tests/run_all.sh                                       # tanpa database
#   MESSI_TEST_SOCKET=/var/run/mysqld/mysqld.sock sh php/tests/run_all.sh
#
# Without a database, the four suites that need one say DILEWATI and the run still
# succeeds. Point it at a database and they must run: a database that was named but
# could not be reached is a failure, not a skip — otherwise a suite that never ran
# reads exactly like a suite that passed.

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

echo
if [ -n "$MESSI_TEST_SOCKET" ] || [ -n "$MESSI_TEST_HOST" ]; then
  echo "SEMUA LOLOS — termasuk yang pakai database sungguhan."
else
  echo "LOLOS, TAPI SEBAGIAN DILEWATI — yang butuh database belum dijalankan."
  echo "Jalankan lagi dengan MESSI_TEST_SOCKET=... atau MESSI_TEST_HOST=... untuk yang lengkap."
fi
