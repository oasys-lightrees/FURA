<?php
/**
 * Config, database and the few helpers every entry point needs.
 *
 * Shared hosting has no composer and no environment variables worth trusting, so this is
 * deliberately plain: one config file, one PDO handle, no autoloader.
 */

declare(strict_types=1);

require_once __DIR__ . '/engine.php';

function cfg(?string $key = null)
{
    static $config = null;
    if ($config === null && isset($GLOBALS['MESSI_CONFIG'])) {
        // Set by the test runner before anything else loads, so a test database never
        // needs a config.php sitting next to the real one.
        $config = $GLOBALS['MESSI_CONFIG'];
    }
    if ($config === null) {
        // MESSI_CONFIG_FILE lets the config live outside the web root, which is the
        // safer place for a database password on shared hosting.
        $path = getenv('MESSI_CONFIG_FILE') ?: dirname(__DIR__) . '/config.php';
        if (!is_file($path)) {
            http_response_code(500);
            exit('config.php belum ada. Salin config.example.php jadi config.php.');
        }
        $config = require $path;
    }
    return $key === null ? $config : ($config[$key] ?? null);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = cfg('db');
    $dsn = 'mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4';
    if (!empty($c['socket'])) {                  // handy locally; cPanel uses the host
        $dsn = 'mysql:unix_socket=' . $c['socket'] . ';dbname=' . $c['name'] . ';charset=utf8mb4';
    }
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // Every DATETIME in this schema is UTC. Saying so stops the server's own timezone
    // from quietly shifting NOW() and CURRENT_TIMESTAMP.
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function q(string $sql, array $args = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}

function q1(string $sql, array $args = []): ?array
{
    $row = q($sql, $args)->fetch();
    return $row === false ? null : $row;
}

/* --------------------------------------------------------------------- http */

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_err(string $message, int $code = 400): void
{
    json_out(['ok' => false, 'error' => $message], $code);
}

/** Reads a JSON request body. Returns [] rather than throwing, so a bad body becomes a
 *  validation message in Indonesian instead of a 500. */
function json_in(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function random_token(): string
{
    return bin2hex(random_bytes(32));       // 64 hex chars, matching CHAR(64)
}

function log_job(string $kind, string $detail = ''): void
{
    q('INSERT INTO job_log (ran_at, kind, detail) VALUES (?,?,?)',
      [Clock::nowUtcSql(), $kind, $detail]);
}
