<?php
/**
 * The cron entry point. One line in cPanel → Cron Jobs → Once Per Hour:
 *
 *   0 * * * *  /usr/local/bin/php /home/AKUN/public_html/messi/cron/tick.php >/dev/null 2>&1
 *
 * Or, on hosts that only offer URL crons:
 *   https://contoh.com/messi/cron/tick.php?key=CRON_KEY
 *
 * The work itself is in lib/tick.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/tick.php';

if (PHP_SAPI !== 'cli') {
    // Without this, anyone could make the bot message the whole squad at will.
    $want = (string) cfg('cron_key');
    if ($want === '' || !hash_equals($want, (string) ($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("no\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$did = messi_tick();
$parts = [];
foreach ($did as $what => $n) {
    $parts[] = $what . '=' . $n;
}
echo Clock::today() . ' ' . sprintf('%02d:00', Clock::hour()) . ' '
   . ($parts ? implode(' ', $parts) : 'nothing to do') . "\n";
