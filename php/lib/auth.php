<?php
/**
 * Who is asking.
 *
 * Two ways in: a password, and a one-time link an admin hands out privately for
 * somebody locked out. The link is single-use and short-lived, which is what makes it
 * affordable — and why it must never be posted into a shared Google Chat space, where
 * spending it would be open to everyone who can read the space.
 *
 * Sessions last 30 days on purpose. Google Chat webhooks cannot send a private
 * message, so there is no per-person morning link; a long session is what keeps this
 * from becoming a daily password prompt, which is a daily report people skip.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const MESSI_COOKIE = 'messi_session';

/** Who this request turned out to be, looked up once. A plain static inside auth_user()
 *  could not be cleared when a login happens mid-request, and then signing in would not
 *  take effect until the next page. */
final class Auth
{
    public static bool $looked = false;
    public static ?array $user = null;
}

function auth_cookie_options(int $expires): array
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,      // the page never needs to read it, so JS cannot either
        'samesite' => 'Lax',     // enough to stop cross-site form posts reaching the API
    ];
}

function auth_start_session(int $userId): string
{
    $token = random_token();
    $days = (int) (cfg('session_days') ?: 30);
    $expires = Clock::utc()->modify('+' . $days . ' days');
    q('INSERT INTO sessions (token, user_id, expires_at, created_at) VALUES (?,?,?,?)',
      [$token, $userId, $expires->format('Y-m-d H:i:s'), Clock::nowUtcSql()]);
    setcookie(MESSI_COOKIE, $token, auth_cookie_options($expires->getTimestamp()));
    // The cookie only reaches $_COOKIE on the next request, but the person is signed in
    // now — so the rest of this one should already know it.
    $_COOKIE[MESSI_COOKIE] = $token;
    Auth::$looked = false;
    Auth::$user = null;
    return $token;
}

/** The signed-in user, or null. Cached per request. */
function auth_user(): ?array
{
    if (Auth::$looked) {
        return Auth::$user;
    }
    Auth::$looked = true;
    $token = $_COOKIE[MESSI_COOKIE] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    Auth::$user = q1(
        'SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id
          WHERE s.token = ? AND s.expires_at > ? AND u.active = 1',
        [$token, Clock::nowUtcSql()]
    );
    return Auth::$user;
}

function auth_logout(): void
{
    $token = $_COOKIE[MESSI_COOKIE] ?? '';
    if ($token !== '') {
        q('DELETE FROM sessions WHERE token = ?', [$token]);
    }
    setcookie(MESSI_COOKIE, '', auth_cookie_options(1));
    unset($_COOKIE[MESSI_COOKIE]);
    Auth::$looked = true;
    Auth::$user = null;
}

/**
 * Masuk dengan password. Mengembalikan null kalau gagal — pemanggilnya yang memilih
 * kalimatnya, supaya halaman ini tidak bisa dipakai menebak siapa yang punya akun.
 */
function auth_login(string $email, string $password): ?array
{
    $user = q1('SELECT * FROM users WHERE email = ? AND active = 1', [strtolower(trim($email))]);
    // Hash even when the address is unknown, so a missing account and a wrong password
    // take the same time to answer.
    $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringfosomethingnothing.here.aa';
    // Yang diundang tapi belum membuat password belum punya akun yang bisa dipakai.
    // Hash kosong tidak akan cocok dengan apa pun, tapi dikatakan di sini supaya alasannya
    // terbaca dan tidak bergantung pada perilaku password_verify terhadap hash tak sah.
    // accepted_at baru ada setelah pemutakhiran; sebelum itu semua akun memang sudah
    // punya password, jadi tidak ada yang perlu ditahan di sini.
    if ($user && array_key_exists('accepted_at', $user) && $user['accepted_at'] === null) {
        auth_note_failure($email);
        return null;
    }
    if (!password_verify($password, $hash) || !$user) {
        auth_note_failure($email);
        return null;
    }
    auth_clear_failures($email);
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?',
          [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    auth_start_session((int) $user['id']);
    return $user;
}

/* ------------------------------------------------------- percobaan yang gagal */

const MESSI_TRY_WINDOW = 15;   // menit yang dihitung
const MESSI_TRY_PAIR   = 8;    // gagal dari satu alamat IP untuk satu email
const MESSI_TRY_IP     = 30;   // gagal dari satu alamat IP untuk email mana pun

/**
 * Siapa yang sedang mencoba.
 *
 * Di balik proxy cPanel alamat aslinya ada di header; header bisa dipalsukan, jadi yang
 * dipakai tetap REMOTE_ADDR. Yang memalsukan header tidak mendapat jatah percobaan baru.
 */
function auth_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/**
 * Apakah percobaan ini harus ditolak sebelum passwordnya diperiksa?
 *
 * Dihitung per pasangan (email, IP), bukan per email saja. Per email saja berarti siapa
 * pun yang tahu alamat email seseorang bisa mengunci orang itu di luar dengan sengaja
 * salah delapan kali — pembatasan yang justru jadi senjata. Per IP juga dibatasi, lebih
 * longgar, untuk yang mencoba banyak email sekaligus dari satu tempat.
 */
function auth_throttled(string $email, ?string $ip = null): bool
{
    $ip = $ip ?? auth_ip();
    $since = Clock::utc()->modify('-' . MESSI_TRY_WINDOW . ' minutes')->format('Y-m-d H:i:s');
    $email = strtolower(trim($email));

    // Tabelnya dibuat oleh pemutakhiran, dan pemutakhiran cuma bisa dijalankan setelah
    // masuk. Jadi tanpa tabel itu pintunya tidak boleh ikut terkunci: belum ada catatan
    // berarti belum ada yang perlu ditahan.
    $pair = q_opt('SELECT COUNT(*) AS n FROM login_attempts
                    WHERE email = ? AND ip = ? AND at > ?', [$email, $ip, $since]);
    if ($pair === null) {
        return false;
    }
    if ((int) $pair->fetch()['n'] >= MESSI_TRY_PAIR) {
        return true;
    }
    $all = q_opt('SELECT COUNT(*) AS n FROM login_attempts WHERE ip = ? AND at > ?',
                 [$ip, $since]);
    return $all !== null && (int) $all->fetch()['n'] >= MESSI_TRY_IP;
}

function auth_note_failure(string $email, ?string $ip = null): void
{
    q_opt('INSERT INTO login_attempts (at, email, ip) VALUES (?,?,?)',
          [Clock::nowUtcSql(), substr(strtolower(trim($email)), 0, 190), $ip ?? auth_ip()]);
}

/** Berhasil masuk menghapus jejak gagalnya: yang ingat password tidak sedang menebak. */
function auth_clear_failures(string $email, ?string $ip = null): void
{
    q_opt('DELETE FROM login_attempts WHERE email = ? AND ip = ?',
          [strtolower(trim($email)), $ip ?? auth_ip()]);
}

/* ------------------------------------------------------------------ undangan */

/**
 * Undangan: akun dibuat admin, passwordnya dibuat orangnya sendiri.
 *
 * Admin yang mengetikkan password lalu mengirimkannya berarti atasan tahu password
 * bawahannya, dan password itu lewat chat. Undangan menghapus dua-duanya sekaligus.
 */
function auth_make_invite(int $userId, int $hours = 72): string
{
    $token = random_token();
    q('INSERT INTO login_tokens (token, user_id, kind, expires_at) VALUES (?,?,?,?)',
      [$token, $userId, 'invite',
       Clock::utc()->modify('+' . $hours . ' hours')->format('Y-m-d H:i:s')]);
    q('UPDATE users SET invited_at = ? WHERE id = ?', [Clock::nowUtcSql(), $userId]);
    return rtrim((string) cfg('base_url'), '/') . '/undang.php?t=' . $token;
}

/** Siapa yang diundang token ini, tanpa menebusnya — untuk menggambar formulirnya. */
function auth_peek_invite(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    return q1('SELECT u.* FROM login_tokens lt JOIN users u ON u.id = lt.user_id
                WHERE lt.token = ? AND lt.kind = ? AND lt.used_at IS NULL
                  AND lt.expires_at > ? AND u.active = 1',
              [$token, 'invite', Clock::nowUtcSql()]);
}

/**
 * Menebus undangan: orangnya memilih passwordnya sendiri, lalu langsung masuk.
 * Mengembalikan null kalau tokennya salah, kedaluwarsa, atau sudah dipakai.
 */
function auth_accept_invite(string $token, string $password): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $row = q1('SELECT lt.token, u.* FROM login_tokens lt JOIN users u ON u.id = lt.user_id
                    WHERE lt.token = ? AND lt.kind = ? AND lt.used_at IS NULL
                      AND lt.expires_at > ? AND u.active = 1
                    FOR UPDATE',
                  [$token, 'invite', Clock::nowUtcSql()]);
        if (!$row) {
            $pdo->rollBack();
            return null;
        }
        q('UPDATE users SET password_hash = ?, accepted_at = ? WHERE id = ?',
          [password_hash($password, PASSWORD_DEFAULT), Clock::nowUtcSql(), (int) $row['id']]);
        q('UPDATE login_tokens SET used_at = ? WHERE token = ?', [Clock::nowUtcSql(), $token]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    auth_start_session((int) $row['id']);
    return q1('SELECT * FROM users WHERE id = ?', [(int) $row['id']]);
}

/* ------------------------------------------------------- one-time login links */

function auth_make_login_link(int $userId, int $minutes = 720): string
{
    $token = random_token();
    q('INSERT INTO login_tokens (token, user_id, expires_at) VALUES (?,?,?)',
      [$token, $userId, Clock::utc()->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s')]);
    return rtrim((string) cfg('base_url'), '/') . '/login.php?t=' . $token;
}

/** Spends a one-time token. Returns the user, or null if it was wrong, stale or already
 *  used — the three cases are deliberately indistinguishable to whoever tried it. */
function auth_consume_login_link(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $row = q1(
            'SELECT lt.token, u.* FROM login_tokens lt JOIN users u ON u.id = lt.user_id
              WHERE lt.token = ? AND lt.used_at IS NULL AND lt.expires_at > ? AND u.active = 1
              FOR UPDATE',
            [$token, Clock::nowUtcSql()]
        );
        if (!$row) {
            $pdo->rollBack();
            return null;
        }
        q('UPDATE login_tokens SET used_at = ? WHERE token = ?', [Clock::nowUtcSql(), $token]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    auth_start_session((int) $row['id']);
    return $row;
}

/** Expired sessions and spent links are not evidence of anything. Called by the cron. */
function auth_sweep(): int
{
    $now = Clock::nowUtcSql();
    $n  = q('DELETE FROM sessions WHERE expires_at < ?', [$now])->rowCount();
    $n += q('DELETE FROM login_tokens WHERE expires_at < ? OR used_at IS NOT NULL', [$now])->rowCount();
    // Percobaan yang gagal cuma berguna selama jendelanya; sesudah itu ia catatan tentang
    // orang yang salah ketik sebulan lalu, dan itu bukan sesuatu yang perlu disimpan.
    $basi = q_opt('DELETE FROM login_attempts WHERE at < ?',
                  [Clock::utc()->modify('-1 day')->format('Y-m-d H:i:s')]);
    $n += $basi ? $basi->rowCount() : 0;
    return $n;
}

function require_login_json(): array
{
    $u = auth_user();
    if (!$u) {
        json_err('Sesi habis. Silakan masuk lagi.', 401);
    }
    return $u;
}

/** Punya layar rekap: leader membaca timnya, admin dan owner membaca semuanya. */
function is_leader(array $user): bool
{
    return in_array($user['role'] ?? '', ['leader', 'admin', 'owner'], true);
}

/** Boleh mengurus orang, tim dan pertanyaan — dan membaca semua tim, bukan satu. */
function is_manager(array $user): bool
{
    return in_array($user['role'] ?? '', ['admin', 'owner'], true);
}

/** Satu-satunya yang boleh mengangkat dan mencabut admin. */
function is_owner(array $user): bool
{
    return ($user['role'] ?? '') === 'owner';
}

/* ------------------------------------------------------------------- forms */

/** Derived from the session cookie rather than stored, so it needs no extra table and
 *  dies with the session. The cookie is httponly, so another site cannot read it to
 *  forge one. */
function csrf_token(): string
{
    return substr(hash_hmac('sha256', 'messi-csrf', (string) ($_COOKIE[MESSI_COOKIE] ?? '')), 0, 32);
}

function csrf_check(): void
{
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Form kedaluwarsa. Muat ulang halamannya.');
    }
}
