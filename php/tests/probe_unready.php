<?php
/** Loads the readiness check against the test database, so test_repo.php can see what a
 *  person would see when install.sql was never imported. Not part of the app. */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test.php';

$socket = getenv('MESSI_TEST_SOCKET') ?: '';
$GLOBALS['MESSI_CONFIG'] = [
    'db' => [
        'host'   => getenv('MESSI_TEST_HOST') ?: ($socket ? '' : 'localhost'),
        'socket' => $socket,
        'name'   => getenv('MESSI_TEST_DB') ?: 'messi_test',
        'user'   => getenv('MESSI_TEST_USER') ?: 'root',
        'pass'   => getenv('MESSI_TEST_PASS') ?: '',
    ],
    'base_url' => 'https://example.test', 'chat_webhook' => '', 'cron_key' => 'x',
    'first_day' => '2026-09-28', 'session_days' => 30,
];

require_once __DIR__ . '/../lib/bootstrap.php';
messi_require_ready();
echo "siap\n";
