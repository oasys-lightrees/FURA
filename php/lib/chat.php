<?php
/**
 * Google Chat, because that is where the squad now is.
 *
 * An incoming webhook posts into one space. It cannot send a direct message, and that
 * single fact shapes everything here:
 *
 *   - There is no per-person morning link. A one-time login link posted in a shared
 *     space is a login link for everyone in that space, so the message carries the
 *     plain address and people sign in normally. Sessions last 30 days, so that is
 *     roughly a monthly login, not a daily one.
 *   - The three daily messages are one message each to the space, not one per person.
 *     That suits how the squad already works — reports were always posted in the group.
 *
 * Google Chat markup, not HTML: *bold*, _italic_, and <url|text> for links. Passing
 * HTML-escaped text here would print the escapes.
 * https://developers.google.com/workspace/chat/format-messages
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** A seam, so the tests can read what would be posted without a webhook or a network. */
final class Chat
{
    /** @var null|callable(string,string):bool */
    public static $send = null;
}

function chat_webhook(string $which = 'chat_webhook'): string
{
    $url = trim((string) cfg($which));
    // The leader recap falls back to the squad space rather than going nowhere.
    if ($url === '' && $which !== 'chat_webhook') {
        $url = trim((string) cfg('chat_webhook'));
    }
    return $url;
}

function chat_enabled(): bool
{
    return Chat::$send !== null || chat_webhook() !== '';
}

/**
 * Posts one message. A failure is logged, never fatal — the report is still reachable
 * in a browser on a day Google is having trouble.
 */
function chat_send(string $text, string $which = 'chat_webhook'): bool
{
    if (!chat_enabled() || trim($text) === '') {
        return false;
    }
    if (Chat::$send !== null) {
        return (Chat::$send)($text, $which);
    }

    $url = chat_webhook($which);
    if ($url === '') {
        return false;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=UTF-8'],
        CURLOPT_POSTFIELDS => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($code !== 200) {
        // The webhook URL carries its own key and token, so it never goes in the log.
        log_job('chat_error', substr($which . ' ' . $code . ' ' . ($err ?: (string) $body), 0, 500));
        return false;
    }
    return true;
}

/** A link the squad can tap. Plain, and deliberately not a one-time login link. */
function chat_link(string $label): string
{
    $base = rtrim((string) cfg('base_url'), '/');
    return $base === '' ? $label : '<' . $base . '|' . $label . '>';
}

/* --------------------------------------------------------------- the three */

/** 09:00 — the day is open, and here is what is already owed. */
function chat_morning(string $today, array $duePromises): string
{
    $lines = ['*MESSI ' . messi_fmt_day($today) . '* — laporan hari ini sudah dibuka.'];
    if ($duePromises) {
        $lines[] = '';
        $lines[] = 'Yang dijanjikan jatuh tempo hari ini:';
        foreach ($duePromises as $p) {
            $lines[] = '- ' . $p['name'] . ' — ' . $p['action_text'];
        }
    }
    $lines[] = '';
    $lines[] = chat_link('Isi laporan');
    return implode("\n", $lines);
}

/** 17:00 — named, because a reminder addressed to nobody is read by nobody. */
function chat_reminder(array $names): string
{
    return implode("\n", [
        'Satu jam lagi tutup (18:00). Belum lapor: ' . implode(', ', $names) . '.',
        '',
        chat_link('Isi sekarang'),
    ]);
}
