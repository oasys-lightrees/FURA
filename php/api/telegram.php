<?php
/**
 * The bot's ear. Only one thing to hear: someone pairing their Telegram account.
 *
 * Pairing is by a short code an admin hands out, not by email — otherwise anyone who
 * knows a colleague's address could point that colleague's morning link at their own
 * chat. The code is a one-time token with a deadline, so a screenshot in a group chat
 * stops being useful quickly.
 *
 * Point Telegram at it once:
 *   https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://contoh.com/messi/api/telegram.php?key=CRON_KEY
 */

declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require dirname(__DIR__) . '/lib/require-php8.php';

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/telegram.php';

$want = (string) cfg('cron_key');
if ($want === '' || !hash_equals($want, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit;
}

$update = json_in();
$message = $update['message'] ?? $update['edited_message'] ?? null;
$chatId  = (string) ($message['chat']['id'] ?? '');
$text    = trim((string) ($message['text'] ?? ''));
if ($chatId === '') {
    json_out(['ok' => true]);            // nothing we handle; Telegram only wants a 200
}

if (!preg_match('~^/(?:start|mulai)\s+([a-f0-9]{8,64})$~i', $text, $m)) {
    tg_send($chatId, 'Halo. Untuk menyambungkan akun MESSI, minta kode ke admin squad, '
                   . 'lalu kirim di sini: <code>/mulai KODE</code>');
    json_out(['ok' => true]);
}

$code = strtolower($m[1]);
$row = q1('SELECT lt.token, u.id, u.name FROM login_tokens lt JOIN users u ON u.id = lt.user_id
            WHERE lt.token LIKE ? AND lt.used_at IS NULL AND lt.expires_at > ? AND u.active = 1
            LIMIT 1',
          [$code . '%', Clock::nowUtcSql()]);

if (!$row) {
    tg_send($chatId, 'Kodenya tidak dikenal atau sudah kedaluwarsa. Minta yang baru ke admin.');
    json_out(['ok' => true]);
}

// The chat id is unique in the schema: one Telegram account speaks for one person. If it
// was pointed somewhere else before, that link is replaced rather than duplicated.
q('UPDATE users SET telegram_chat_id = NULL WHERE telegram_chat_id = ?', [$chatId]);
q('UPDATE users SET telegram_chat_id = ? WHERE id = ?', [$chatId, $row['id']]);
q('UPDATE login_tokens SET used_at = ? WHERE token = ?', [Clock::nowUtcSql(), $row['token']]);
log_job('telegram_paired', 'user=' . $row['id']);

tg_send($chatId, 'Tersambung, ' . htmlspecialchars($row['name'], ENT_QUOTES)
      . '. Tiap pagi jam 09:00 saya kirim link laporannya ke sini.');
json_out(['ok' => true]);
