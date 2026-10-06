<?php
/** Builds the database tests/test_live.py drives. Not for production. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/repo.php';

$c = cfg('db');
$dsn = $c['socket'] ? 'mysql:unix_socket=' . $c['socket'] : 'mysql:host=' . $c['host'];
$root = new PDO($dsn . ';charset=utf8mb4', $c['user'], $c['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('DROP DATABASE IF EXISTS `' . $c['name'] . '`');
$root->exec('CREATE DATABASE `' . $c['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root->exec('USE `' . $c['name'] . '`');

$sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(__DIR__ . '/../install.sql'));
foreach (explode(';', $sql) as $stmt) {
    if (trim($stmt) !== '') {
        $root->exec($stmt);
    }
}

// `php tests/seed_live.php empty` leaves the database bare, which is what the setup
// page needs to have anything to do.
if (($argv[1] ?? '') === 'empty') {
    echo "seeded empty, today=" . Clock::today() . "\n";
    exit(0);
}

$joined = messi_add_days(Clock::today(), -2);
foreach ([['nicho@example.test', 'Nicho', 'player'],
          ['rio@example.test',   'Rio',   'player'],
          ['lead@example.test', 'Lia', 'leader']] as [$email, $name, $role]) {
    q('INSERT INTO users (email, name, password_hash, role, joined_on, created_at) VALUES (?,?,?,?,?,?)',
      [$email, $name, password_hash('kata-sandi-panjang', PASSWORD_DEFAULT), $role, $joined, Clock::nowUtcSql()]);
}
echo "seeded, joined=$joined today=" . Clock::today() . "\n";
