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
 *   - There is no recap here. A recap belongs on the Squad screen, which can show any
 *     day, open anybody's full report and be acted on; a chat message can do none of
 *     that. What chat is good at is the nudge that gets people to the site, so that is
 *     all this sends: the day is open, and later, who has still not reported.
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
    /** @var null|callable(string,string):bool  teks, lalu space mana yang dituju */
    public static $send = null;
}

/**
 * Space mana yang disapa.
 *
 * Tiap tim punya space sendiri, jadi webhook-nya ikut setelan tim. Yang di config.php
 * tetap berlaku sebagai cadangan: pemasangan satu tim tidak perlu memindahkan apa pun,
 * dan tim yang belum mengisi webhook-nya tetap dapat pesan di space perusahaan.
 */
function chat_webhook(string $teamWebhook = ''): string
{
    $team = trim($teamWebhook);
    return $team !== '' ? $team : trim((string) cfg('chat_webhook'));
}

function chat_enabled(string $teamWebhook = ''): bool
{
    return Chat::$send !== null || chat_webhook($teamWebhook) !== '';
}

/**
 * Posts one message. A failure is logged, never fatal — the report is still reachable
 * in a browser on a day Google is having trouble.
 */
function chat_send(string $text, int $timeout = 15, string $teamWebhook = ''): bool
{
    if (!chat_enabled($teamWebhook) || trim($text) === '') {
        return false;
    }
    if (Chat::$send !== null) {
        // Space-nya ikut diberikan, supaya tes bisa membuktikan pesan tim A tidak
        // berakhir di space tim B — pembuktian yang tidak mungkin dari teksnya saja.
        return (Chat::$send)($text, chat_webhook($teamWebhook));
    }

    $url = chat_webhook($teamWebhook);
    if ($url === '') {
        return false;
    }

    $ch = curl_init($url);
    if ($ch === false) {                 // URL yang bentuknya rusak
        log_job('chat_error', 'webhook tidak bisa dibuka');
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=UTF-8'],
        CURLOPT_POSTFIELDS => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($code !== 200) {
        // The webhook URL carries its own key and token, so it never goes in the log.
        log_job('chat_error', substr($code . ' ' . ($err ?: (string) $body), 0, 500));
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
            // Kolom rencananya textarea, jadi orang boleh menekan Enter — dan memang
            // begitu kejadiannya. Satu janji harus tetap satu baris di sini, kalau tidak
            // baris keduanya lepas dari bullet dan dari nama pemiliknya.
            $act = trim((string) preg_replace('/\s*\R\s*/u', ' · ', (string) $p['action_text']));
            $lines[] = '- ' . $p['name'] . ' — ' . $act;
        }
    }
    $lines[] = '';
    $lines[] = chat_link('Isi laporan');
    return implode("\n", $lines);
}

/**
 * Somebody asked for help.
 *
 * This one is not on a schedule. A person who reported and is blocked is the one case
 * where waiting until the next hour is worse than the noise of posting at once — and
 * the system already chases people who stay silent, so staying silent about somebody
 * who spoke up had the asymmetry exactly backwards.
 */
function chat_escalation(string $name, string $text): string
{
    return implode("\n", [
        '🙋 *' . $name . '* minta bantuan',
        '',
        $text,
        '',
        chat_link('Buka MESSI'),
    ]);
}

/**
 * One hour before closing — named, because a reminder addressed to nobody is read by
 * nobody.
 *
 * $lewat berarti pesannya menyusul: cron-nya tidak jalan di jam yang seharusnya, dan yang
 * dikirim sekarang sudah sesudah jam tutup. Kalimatnya harus berbeda, kalau tidak "satu
 * jam lagi tutup" dikirim jam sembilan malam — dan pengingat yang jelas-jelas salah
 * tentang jam berapa sekarang adalah pengingat yang berhenti dipercaya.
 */
function chat_reminder(array $names, int $dueHour = MESSI_DUE_HOUR, bool $lewat = false): string
{
    $jam = sprintf('%02d:00', $dueHour);
    return implode("\n", [
        $lewat
            ? 'Sudah lewat jam tutup (' . $jam . ') dan belum lapor: '
                . implode(', ', $names) . '. Masih bisa diisi, tercatat telat.'
            : 'Satu jam lagi tutup (' . $jam . '). Belum lapor: ' . implode(', ', $names) . '.',
        '',
        chat_link('Isi sekarang'),
    ]);
}
