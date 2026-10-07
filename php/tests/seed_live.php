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

// `php tests/seed_live.php lama` membangun database dalam bentuk versi sebelumnya —
// keadaan tiap pemasangan yang sudah jalan tepat setelah berkas barunya di-upload dan
// sebelum pemutakhirannya dijalankan. Satu-satunya cara menguji jendela itu.
$lama = ($argv[1] ?? '') === 'lama';
$skema = $lama ? __DIR__ . '/fixtures/skema-lama-dengan-settings.sql'
               : __DIR__ . '/../install.sql';
$sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($skema));
foreach (explode(';', $sql) as $stmt) {
    if (trim($stmt) !== '') {
        $root->exec($stmt);
    }
}

if ($lama) {
    // Orang dan laporan yang sudah ada, dalam bentuk lama: tanpa tim, tanpa jejak
    // undangan, dan dengan peran admin — belum ada owner.
    $now = Clock::nowUtcSql();
    $kemarin = messi_add_days(Clock::today(), -2);
    foreach ([['lead@example.test', 'Lia', 'admin'],
              ['nicho@example.test', 'Nicho', 'player']] as [$email, $name, $role]) {
        $root->exec("INSERT INTO users (email, name, password_hash, role, joined_on, created_at)
                     VALUES (" . $root->quote($email) . ", " . $root->quote($name) . ", "
                     . $root->quote(password_hash('kata-sandi-panjang', PASSWORD_DEFAULT))
                     . ", " . $root->quote($role) . ", " . $root->quote($kemarin) . ", "
                     . $root->quote($now) . ")");
    }
    $root->exec("INSERT INTO cycles (user_id, day, status, answers, submitted_at, created_at)
                 VALUES (2, " . $root->quote($kemarin) . ", 'submitted',
                         '{\"detail\":\"WAG Klien A\"}', " . $root->quote($now) . ", "
                 . $root->quote($now) . ")");
    $root->exec("INSERT INTO settings (name, value, updated_at)
                 VALUES ('messi', '{\"threshold_days\":3}', " . $root->quote($now) . ")");
    echo "seeded lama, today=" . Clock::today() . "\n";
    exit(0);
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
    q('INSERT INTO users (email, name, password_hash, role, team_id, joined_on,
                          accepted_at, created_at)
       VALUES (?,?,?,?,?,?,?,?)',
      [$email, $name, password_hash('kata-sandi-panjang', PASSWORD_DEFAULT), $role,
       repo_default_team(), $joined, Clock::nowUtcSql(), Clock::nowUtcSql()]);
}
echo "seeded, joined=$joined today=" . Clock::today() . "\n";
