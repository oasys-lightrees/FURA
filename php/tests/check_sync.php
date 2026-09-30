<?php
/**
 * app.html must be the page web/tests already checks. A copy that has drifted is a copy
 * whose tests describe something else.
 *
 * Run: php tests/check_sync.php   (and `cp ../web/messi.html app.html` when it complains)
 */

declare(strict_types=1);

$here = dirname(__DIR__) . '/app.html';
$source = dirname(__DIR__, 2) . '/web/messi.html';

if (!is_file($source)) {
    echo "web/messi.html tidak ada di sini — lewati.\n";
    exit(0);
}
if (!is_file($here)) {
    echo "FAIL  app.html belum disalin dari web/messi.html\n";
    exit(1);
}
if (hash_file('sha256', $here) !== hash_file('sha256', $source)) {
    echo "FAIL  app.html berbeda dari web/messi.html — jalankan: cp ../web/messi.html app.html\n";
    exit(1);
}
echo "app.html sama dengan web/messi.html\n";
