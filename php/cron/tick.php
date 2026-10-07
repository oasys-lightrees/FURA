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

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require dirname(__DIR__) . '/lib/require-php8.php';

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

/**
 * Catatan kematian.
 *
 * Fatal error tidak sempat menulis apa pun tentang dirinya sendiri, jadi yang tertinggal
 * di job_log cuma baris terakhir dari jalannya yang berhasil — berjam-jam atau berhari-hari
 * sebelumnya. Yang terlihat lalu cuma "cron-nya mati", tanpa satu pun petunjuk kenapa.
 * Penutup ini yang menuliskannya.
 */
$selesai = false;
register_shutdown_function(function () use (&$selesai) {
    if ($selesai) {
        return;
    }
    $e = error_get_last();
    $pesan = $e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
        ? $e['message'] . ' @ ' . basename((string) $e['file']) . ':' . $e['line']
        : 'berhenti di tengah jalan tanpa pesan';
    try {
        log_job('tick_fatal', substr($pesan, 0, 400));
    } catch (Throwable $x) {
        // Databasenya sendiri yang tidak bisa dihubungi: tidak ada tempat mencatatnya.
    }
});

try {
    $did = messi_tick();
    // Denyut: satu baris tiap kali dijalankan, berhasil atau tidak ada yang perlu
    // dikerjakan. Tanpa ini, akhir pekan dan cron yang mati terlihat sama persis —
    // sama-sama tidak meninggalkan baris baru.
    log_job('tick', Clock::today() . ' ' . sprintf('%02d:00', Clock::hour()));
    $selesai = true;
} catch (Throwable $e) {
    $selesai = true;
    try {
        log_job('tick_fatal', substr($e->getMessage(), 0, 400));
    } catch (Throwable $x) {
        // sama seperti di atas
    }
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    fwrite(PHP_SAPI === 'cli' ? STDERR : STDOUT, 'GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}

$parts = [];
foreach ($did as $what => $n) {
    $parts[] = $what . '=' . $n;
}
echo Clock::today() . ' ' . sprintf('%02d:00', Clock::hour()) . ' '
   . ($parts ? implode(' ', $parts) : 'nothing to do') . "\n";
