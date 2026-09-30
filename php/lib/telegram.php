<?php
/**
 * Telegram, because that is where the squad already is.
 *
 * WhatsApp is deliberately absent: personal WhatsApp has no API, and the libraries that
 * pretend otherwise get the company's own number banned. See docs/09-integrations.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** A seam, so the tests can watch what the bot would say without a token or a network.
 *  Untouched in production, where Tg::$send stays null and the curl below runs. */
final class Tg
{
    /** @var null|callable(string,string,?array):bool */
    public static $send = null;
}

function tg_enabled(): bool
{
    return Tg::$send !== null || trim((string) cfg('telegram_token')) !== '';
}

/** Sends one message. Returns true on success; a failure is logged, never fatal — the
 *  report is still reachable in a browser if the bot is having a bad day. */
function tg_send(string $chatId, string $text, ?array $keyboard = null): bool
{
    if (!tg_enabled() || $chatId === '') {
        return false;
    }
    if (Tg::$send !== null) {
        return (Tg::$send)($chatId, $text, $keyboard);
    }
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($keyboard) {
        $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
    }

    $url = 'https://api.telegram.org/bot' . cfg('telegram_token') . '/sendMessage';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($code !== 200) {
        log_job('telegram_error', substr($chatId . ' ' . $code . ' ' . ($err ?: (string) $body), 0, 500));
        return false;
    }
    return true;
}

/** The 09:00 message. The link is the whole point: one tap, already signed in. */
function tg_morning(array $user, string $link, array $duePromises, array $missed): bool
{
    $lines = ['Pagi, ' . htmlspecialchars($user['name'], ENT_QUOTES) . '. Laporan MESSI hari ini sudah dibuka.'];

    if ($duePromises) {
        $lines[] = '';
        $lines[] = 'Yang kamu janjikan dan jatuh tempo hari ini:';
        foreach ($duePromises as $p) {
            $lines[] = '• ' . htmlspecialchars($p['action_text'], ENT_QUOTES);
        }
    }
    if ($missed) {
        $lines[] = '';
        $lines[] = 'Belum terisi: ' . implode(', ', array_map('messi_fmt_day', $missed)) . '.';
    }

    return tg_send($user['telegram_chat_id'], implode("\n", $lines),
                   [[['text' => 'Isi laporan', 'url' => $link]]]);
}

/** The late-afternoon nudge, sent only to people who have not reported. */
function tg_reminder(array $user, string $link): bool
{
    return tg_send($user['telegram_chat_id'],
        'Sebentar lagi tutup jam 18:00 dan laporan MESSI kamu belum masuk.',
        [[['text' => 'Isi sekarang', 'url' => $link]]]);
}
