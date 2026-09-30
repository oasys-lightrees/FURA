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

function auth_login(string $email, string $password): ?array
{
    $user = q1('SELECT * FROM users WHERE email = ? AND active = 1', [strtolower(trim($email))]);
    // Hash even when the address is unknown, so a missing account and a wrong password
    // take the same time to answer.
    $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringfosomethingnothing.here.aa';
    if (!password_verify($password, $hash) || !$user) {
        return null;
    }
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?',
          [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    auth_start_session((int) $user['id']);
    return $user;
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

function is_leader(array $user): bool
{
    return $user['role'] === 'leader' || $user['role'] === 'admin';
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
