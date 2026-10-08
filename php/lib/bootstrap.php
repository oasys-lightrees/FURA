<?php
/**
 * Config, database and the few helpers every entry point needs.
 *
 * Shared hosting has no composer and no environment variables worth trusting, so this is
 * deliberately plain: one config file, one PDO handle, no autoloader.
 */

declare(strict_types=1);

require_once __DIR__ . '/engine.php';

/**
 * Stops with something readable instead of a blank page.
 *
 * The three ways a first install goes wrong — no config.php, wrong database password,
 * install.sql never imported — all end as an uncaught error, and cPanel has
 * display_errors off by default, so what the person actually sees is a white screen.
 * A white screen is the one failure nobody can act on.
 */
function messi_stop(string $title, string $detail, string $whatToDo): void
{
    $isApi = str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/api/');

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $title . ': ' . $detail . "\n" . $whatToDo . "\n");
        exit(1);
    }
    http_response_code(500);
    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $title . ' ' . $whatToDo],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    $e = fn($t) => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><meta charset="utf-8"><title>FURA belum siap</title>'
       . '<div style="max-width:34rem;margin:4rem auto;padding:0 1.5rem;'
       . 'font:400 16px/1.6 system-ui,-apple-system,sans-serif;color:#1a1c1f">'
       . '<p style="color:#6b6d73;font-size:14px;margin:0">FURA belum siap dipakai</p>'
       . '<h1 style="font-size:1.25rem;margin:.25rem 0 1rem">' . $e($title) . '</h1>'
       . ($detail === '' ? '' : '<p style="background:#f7e7e4;color:#97322a;padding:.75rem 1rem;'
         . 'border-radius:.5rem;font-size:14px;margin:0 0 1rem">' . $e($detail) . '</p>')
       . '<p>' . $whatToDo . '</p>'
       . '<p style="color:#6b6d73;font-size:14px">Langkah lengkapnya ada di '
       . '<strong>PASANG.txt</strong>, satu folder dengan berkas ini.</p></div>';
    exit;
}

function cfg(?string $key = null)
{
    static $config = null;
    if (isset($GLOBALS['MESSI_CONFIG'])) {
        // Set by the test runner before anything else loads, so a test database never
        // needs a config.php sitting next to the real one. Dibaca ulang tiap kali, bukan
        // disimpan: tes yang berpindah database mengubahnya di tengah jalan.
        return $key === null ? $GLOBALS['MESSI_CONFIG']
                             : ($GLOBALS['MESSI_CONFIG'][$key] ?? null);
    }
    if ($config === null) {
        // MESSI_CONFIG_FILE lets the config live outside the web root, which is the
        // safer place for a database password on shared hosting.
        $path = getenv('MESSI_CONFIG_FILE') ?: dirname(__DIR__) . '/config.php';
        if (!is_file($path)) {
            messi_stop(
                'config.php belum dibuat',
                '',
                'Di File Manager, ganti nama <code>config.example.php</code> menjadi '
                . '<code>config.php</code>, lalu isi nama database, user, password, dan '
                . '<code>base_url</code>.'
            );
        }
        $config = require $path;
    }
    return $key === null ? $config : ($config[$key] ?? null);
}

/** Satu sambungan per permintaan. Kelas, bukan static di dalam fungsi, supaya tes yang
 *  berpindah database bisa membuangnya — static di dalam fungsi tidak bisa dibuang. */
final class Db
{
    public static ?PDO $pdo = null;
    public static function forget(): void { self::$pdo = null; }
}

function db(): PDO
{
    if (Db::$pdo instanceof PDO) {
        return Db::$pdo;
    }
    $c = cfg('db');
    $dsn = 'mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4';
    if (!empty($c['socket'])) {                  // handy locally; cPanel uses the host
        $dsn = 'mysql:unix_socket=' . $c['socket'] . ';dbname=' . $c['name'] . ';charset=utf8mb4';
    }
    try {
        Db::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // MySQL's own words help here — they distinguish a wrong password from a
        // database that does not exist. They name the user and host, never the password.
        messi_stop(
            'Database tidak bisa dihubungi',
            $e->getMessage(),
            'Cek <code>name</code>, <code>user</code>, dan <code>pass</code> di '
            . '<code>config.php</code>. Di cPanel ketiganya berawalan nama akun, '
            . 'misalnya <code>akunanda_messi</code> — bukan <code>messi</code> saja. '
            . 'Pastikan juga user-nya sudah dikaitkan ke database lewat '
            . '<em>MySQL Databases &rarr; Add User To Database</em> dengan '
            . '<em>All Privileges</em>.'
        );
    }
    // Every DATETIME in this schema is UTC. Saying so stops the server's own timezone
    // from quietly shifting NOW() and CURRENT_TIMESTAMP.
    Db::$pdo->exec("SET time_zone = '+00:00'");
    return Db::$pdo;
}

/**
 * Checked once on every page a person can open, so a half-finished install says what is
 * missing on the first screen rather than on the first click.
 */
/**
 * Tabel yang dibutuhkan, dipisah menurut akibat kalau hilang.
 *
 * Tanpa salah satu tabel inti tidak ada yang bisa dikerjakan sama sekali. `settings` lain
 * urusannya: setelan punya bawaan, jadi pelaporan harian tetap jalan tanpa tabel itu —
 * yang hilang cuma kemampuan admin menyimpan perubahan. Mematikan seluruh aplikasi untuk
 * itu akan menghukum delapan orang karena satu langkah pemasangan yang terlewat.
 */
const MESSI_TABLES_CORE = ['users', 'sessions', 'login_tokens', 'cycles', 'commitments',
                           'job_log'];
// Semua yang dibuat install.sql. Sempat cuma tujuh, sehingga halaman cek berkata
// "lengkap, 7 tabel" sementara panduannya menyuruh menghitung sembilan di phpMyAdmin —
// dua angka benar untuk dua pertanyaan berbeda, dan tidak ada yang tahu itu.
const MESSI_TABLES = ['teams', 'users', 'sessions', 'login_tokens', 'cycles',
                      'commitments', 'settings', 'login_attempts', 'job_log'];

/** Tabel mana saja yang belum ada. Dipakai halaman cek dan halaman setelan. */
function messi_missing_tables(): array
{
    $have = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_diff(MESSI_TABLES, $have));
}

function messi_import_again(): string
{
    return 'Buka <em>phpMyAdmin</em> di cPanel, pilih databasenya di kiri, lalu tab '
        . '<em>Import</em> &rarr; pilih <code>install.sql</code> &rarr; <em>Go</em>. '
        . 'Aman diulang walaupun tabel lain sudah ada: data yang sudah tersimpan tidak '
        . 'tersentuh, yang terjadi cuma tabel yang kurang ikut dibuat. '
        . 'Setelah itu harus ada ' . count(MESSI_TABLES) . ' tabel.';
}

/**
 * Seluruh tabel inti diperiksa, bukan satu.
 *
 * Dulu yang diperiksa cuma `users`, jadi pemasangan lama yang kehilangan satu tabel baru
 * lolos di sini lalu mati dengan halaman 500 kosong beberapa baris kemudian — persis
 * kegagalan yang halaman ini ada untuk mencegahnya. Yang kurang disebutkan namanya.
 */
/**
 * Selain tabelnya ada, bentuknya juga harus sudah sesuai versi kode ini.
 *
 * Dipakai halaman yang memang membutuhkan yang baru. Yang tidak memakainya cuma tiga:
 * login — karena orang harus bisa masuk untuk menjalankan pemutakhirannya; halaman
 * pemutakhiran itu sendiri; dan halaman cek, yang gunanya memang melaporkan keadaan.
 * Kalau login ikut dijaga di sini, pemasangan yang tertinggal akan terkunci dari luar
 * oleh pintu yang cuma bisa dibuka dari dalam.
 */
function messi_require_current(): void
{
    messi_require_ready();
    require_once __DIR__ . '/schema.php';
    if (!schema_pending()) {
        return;
    }
    // Dicoba dikerjakan sendiri dulu. Langkah-langkahnya menambah dan bukan membuang, dan
    // masing-masing memeriksa dirinya sendiri — jadi yang dulu menunggu satu tombol
    // sekarang beres sebelum orangnya sempat sadar ada yang perlu dikerjakan.
    schema_upgrade_auto();
    $pending = schema_pending();
    if (!$pending) {
        return;
    }
    messi_stop(
        'Database perlu dimutakhirkan',
        count($pending) . ' hal belum dikerjakan: ' . implode(', ', array_column($pending, 'id')),
        'Berkas aplikasinya sudah versi baru, databasenya belum. Buka '
        . '<a href="upgrade.php"><strong>Pemutakhiran database</strong></a> lalu tekan '
        . 'Jalankan — sekali tekan, data yang ada tidak dihapus. Kalau belum masuk, '
        . '<a href="login.php">masuk dulu sebagai admin</a>.'
    );
}

function messi_require_ready(): void
{
    $have = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff(MESSI_TABLES_CORE, $have));
    if (!$missing) {
        return;
    }
    messi_stop('Tabelnya belum dibuat', 'Yang kurang: ' . implode(', ', $missing),
               messi_import_again());
}

/**
 * Query yang boleh gagal karena tabel atau kolomnya belum dibuat.
 *
 * Fitur baru datang dengan tabel baru, dan pemasangan yang sudah jalan selalu punya
 * database yang lebih tua daripada kodenya — di antara upload dan pemutakhiran, selalu,
 * walau cuma semenit. Jalur yang sudah ada sebelum fitur itu tidak boleh ikut mati di
 * jendela itu. Mengembalikan null, bukan melempar, supaya pemanggilnya memilih sendiri
 * apa artinya "belum ada".
 */
function q_opt(string $sql, array $args = []): ?PDOStatement
{
    try {
        return q($sql, $args);
    } catch (PDOException $e) {
        // 42S02 tabelnya belum ada, 42S22 kolomnya belum ada.
        if (in_array($e->getCode(), ['42S02', '42S22'], true)) {
            return null;
        }
        throw $e;
    }
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
