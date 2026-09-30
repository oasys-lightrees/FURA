<?php
/**
 * Shared scaffolding for the PHP tests: a throwaway database, and the three assertions
 * everything here is written with.
 *
 * Point it at a MySQL/MariaDB it may create and drop a database on:
 *   MESSI_TEST_SOCKET=/var/run/mysqld/mysqld.sock php tests/test_repo.php
 *   MESSI_TEST_HOST=localhost MESSI_TEST_USER=root MESSI_TEST_PASS=secret php tests/test_repo.php
 * With none of those set, test_db() says so and the suite exits 0 — it never fails a
 * machine that has no database to lend.
 */

declare(strict_types=1);

$passed = 0;
$failed = [];

function ok(string $what, bool $cond): void
{
    global $passed, $failed;
    if ($cond) { $passed++; return; }
    $failed[] = $what;
}

function eq(string $what, $got, $want): void
{
    global $passed, $failed;
    if ($got === $want) { $passed++; return; }
    $failed[] = $what . "\n      got:  " . var_export($got, true)
                      . "\n      want: " . var_export($want, true);
}

function throws(string $what, callable $fn, string $needle = ''): void
{
    global $passed, $failed;
    try {
        $fn();
    } catch (Throwable $e) {
        if ($needle === '' || str_contains($e->getMessage(), $needle)) { $passed++; return; }
        $failed[] = $what . "\n      pesan: " . $e->getMessage();
        return;
    }
    $failed[] = $what . ' (tidak ditolak)';
}

function done(): void
{
    global $passed, $failed;
    echo $passed . ' checks passed';
    if ($failed) {
        echo ', ' . count($failed) . " FAILED\n\n";
        foreach ($failed as $f) { echo '  FAIL  ' . $f . "\n"; }
        exit(1);
    }
    echo ", 0 failed\n";
}

/**
 * Builds an empty database from install.sql and points the app at it. Returns the root
 * handle, or exits 0 when there is nothing to connect to.
 */
function test_db(string $name): PDO
{
    $socket = getenv('MESSI_TEST_SOCKET') ?: '';
    $host   = getenv('MESSI_TEST_HOST') ?: ($socket ? '' : 'localhost');
    $user   = getenv('MESSI_TEST_USER') ?: 'root';
    $pass   = getenv('MESSI_TEST_PASS') ?: '';
    $name   = getenv('MESSI_TEST_DB') ?: $name;

    $dsn = $socket ? "mysql:unix_socket=$socket" : "mysql:host=$host";
    try {
        $root = new PDO($dsn . ';charset=utf8mb4', $user, $pass,
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable $e) {
        // Skipping quietly is right on a machine with no database, and wrong the moment
        // somebody says where the database is: then a skip that reads like a pass is how
        // a suite gets trusted without ever having run.
        if (getenv('MESSI_TEST_SOCKET') || getenv('MESSI_TEST_HOST')) {
            echo "GAGAL  database yang kamu tunjuk tidak bisa dipakai: " . $e->getMessage() . "\n";
            exit(1);
        }
        echo "DILEWATI  tidak ada database (set MESSI_TEST_SOCKET atau MESSI_TEST_HOST)\n";
        exit(0);
    }

    $root->exec("DROP DATABASE IF EXISTS `$name`");
    $root->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $root->exec("USE `$name`");

    // Comments first, then statements: a chunk that opens with a comment line is still a
    // CREATE TABLE, and skipping it leaves the next table's foreign key pointing at nothing.
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(__DIR__ . '/../install.sql'));
    foreach (explode(';', $sql) as $stmt) {
        if (trim($stmt) !== '') {
            $root->exec($stmt);
        }
    }

    $GLOBALS['MESSI_TEST_DBNAME'] = $name;
    $GLOBALS['MESSI_CONFIG'] = [
        'db' => ['host' => $host, 'socket' => $socket, 'name' => $name, 'user' => $user, 'pass' => $pass],
        'base_url' => 'https://example.test/messi',
        'telegram_token' => '',
        'cron_key' => 'test',
        'first_day' => '2026-09-28',
        'session_days' => 30,
    ];
    return $root;
}

const TEST_PASSWORD = 'kata-sandi-panjang';

function make_user(string $email, string $name, string $role, string $joined,
                   ?string $chat = null): array
{
    q('INSERT INTO users (email, name, password_hash, role, joined_on, telegram_chat_id, created_at)
       VALUES (?,?,?,?,?,?,?)',
      [$email, $name, password_hash(TEST_PASSWORD, PASSWORD_DEFAULT), $role, $joined, $chat,
       Clock::nowUtcSql()]);
    return q1('SELECT * FROM users WHERE email = ?', [$email]);
}
