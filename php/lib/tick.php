<?php
/**
 * What the hourly job actually does, as a function so it can be called twice in a test
 * and prove it means the same thing both times.
 *
 * It no longer posts a recap. The recap belongs on the Squad screen, where a leader can
 * read any day, open anybody's full report, and act on it — none of which a chat message
 * can do. What stays here is only what chat is actually good at: a nudge that gets
 * people to the site.
 *
 * Everything here is idempotent. Opening a cycle is guarded by the unique key on
 * (user_id, day); each message is recorded in job_log before it can be sent again. The
 * cron may fire twice in an hour, or miss an hour and catch up, and the day still comes
 * out the same.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/chat.php';

/** Has this job already run today? job_log is the memory that makes repeating safe. */
function job_done(string $kind, string $day): bool
{
    return (bool) q1('SELECT id FROM job_log WHERE kind = ? AND detail LIKE ? LIMIT 1',
                     [$kind, $day . '%']);
}

function messi_tick(): array
{
    $today = Clock::today();
    $hour  = Clock::hour();
    $did   = [];

    // Databasenya belum menyusul versi kodenya. Berhenti dengan catatan, bukan dengan
    // fatal: cron yang mati diam-diam adalah cron yang tidak ada yang tahu sudah mati.
    require_once __DIR__ . '/schema.php';
    $pending = schema_pending();
    if ($pending) {
        log_job('needs_upgrade', $today . ' ' . implode(', ', array_column($pending, 'id')));
        return ['needs_upgrade' => count($pending)];
    }

    /* -- housekeeping, every run -------------------------------------------- */

    $missed = repo_reap($today);
    if ($missed) { $did['missed'] = $missed; log_job('reap', $today . ' n=' . $missed); }

    $broken = repo_break_overdue($today);
    if ($broken) { $did['broken'] = $broken; log_job('break_overdue', $today . ' n=' . $broken); }

    $swept = auth_sweep();
    if ($swept) { $did['swept'] = $swept; }

    if (!messi_is_workday($today)) {
        return $did;
    }

    /* -- the working day, satu tim demi satu tim ----------------------------- */

    // Tiap tim punya jam, ambang dan space-nya sendiri, jadi hari kerja dijalankan per
    // tim. Penanda job_log juga per tim: tim yang sudah disapa tidak menghalangi tim
    // yang belum, dan cron yang jalan dua kali tetap mengirim satu pesan per tim.
    foreach (repo_teams() as $teamId => $team) {
        // Satu tim yang bermasalah tidak boleh menelan tim lainnya. Tanpa ini, setelan
        // rusak di satu tim berarti seluruh squad berhenti dapat pengingat, dan yang
        // terlihat cuma cron yang mati tanpa sebab.
        try {
            $did = tick_team($did, $today, $hour, $teamId);
        } catch (Throwable $e) {
            $did['gagal'] = ($did['gagal'] ?? 0) + 1;
            log_job('tick_error', $today . ' t=' . $teamId . ' ' . substr($e->getMessage(), 0, 300));
        }
    }

    return $did;
}

/** Hari kerja satu tim: buka harinya, sapa, lalu ingatkan. */
function tick_team(array $did, string $today, int $hour, int $teamId): array
{
    $cfg = repo_config($teamId);
    if ($hour < $cfg['open_hour']) {
        return $did;
    }
    $tag = $today . ' t=' . $teamId;

    $made = repo_generate($today, $teamId);
    if ($made) {
        $did['opened'] = ($did['opened'] ?? 0) + count($made);
        log_job('generate', $tag . ' n=' . count($made));
    }

    if (!job_done('notify_open', $tag)) {
        $did = tick_announce($did, 'notify_open', $tag, 'asked',
                             fn() => tick_notify_open($today, $teamId, $cfg));
    }

    // One hour before closing — the nudge, and only to the people it is about.
    if ($hour >= $cfg['due_hour'] - 1 && $hour < $cfg['due_hour']
        && !job_done('notify_due', $tag)) {
        $did = tick_announce($did, 'notify_due', $tag, 'nudged',
                             fn() => tick_notify_due($today, $teamId, $cfg));
    }

    return $did;
}

/**
 * Mengirim satu pengumuman, lalu memutuskan apakah hari ini boleh dianggap selesai.
 *
 * Bedanya "tidak ada yang perlu dikirim" dan "gagal mengirim" adalah seluruh isi fungsi
 * ini. Dulu keduanya sama-sama dicatat selesai, jadi satu menit buruk di jam 9 — Google
 * sedang lambat, webhook sedang disegarkan, jaringan hosting tersendat — berarti tidak
 * ada pengingat sama sekali untuk seharian, tanpa ada yang tahu kenapa. Sekarang yang
 * gagal tidak dicatat, jadi jam berikutnya mencobanya lagi.
 *
 * @param callable():string $kirim mengembalikan 'sent', 'kosong', atau 'gagal'
 */
function tick_announce(array $did, string $kind, string $tag, string $key, callable $kirim): array
{
    $hasil = $kirim();
    if ($hasil === 'gagal') {
        $did['gagal'] = ($did['gagal'] ?? 0) + 1;
        return $did;                      // sengaja tidak dicatat: biar dicoba lagi
    }
    $did[$key] = ($did[$key] ?? 0) + ($hasil === 'sent' ? 1 : 0);
    log_job($kind, $tag . ' sent=' . ($hasil === 'sent' ? '1' : '0 ' . $hasil));
    return $did;
}

/**
 * One message to the team's own space, naming what is already owed today.
 *
 * @return string 'sent' | 'kosong' (tidak ada space untuk disapa) | 'gagal'
 */
function tick_notify_open(string $today, int $teamId, array $cfg): string
{
    if (!chat_enabled($cfg['chat_webhook'])) {
        return 'kosong';
    }
    $due = q('SELECT u.name, c.action_text FROM commitments c JOIN users u ON u.id = c.user_id
               WHERE c.status = ? AND c.due_date <= ? AND u.active = 1 AND u.team_id = ?
               ORDER BY u.name', ['open', $today, $teamId])->fetchAll();
    return chat_send(chat_morning($today, $due), 15, $cfg['chat_webhook']) ? 'sent' : 'gagal';
}

/**
 * Only sent when somebody is actually missing, and it names them.
 *
 * @return string 'sent' | 'kosong' (tidak ada yang telat, atau tidak ada space) | 'gagal'
 */
function tick_notify_due(string $today, int $teamId, array $cfg): string
{
    if (!chat_enabled($cfg['chat_webhook'])) {
        return 'kosong';
    }
    $names = q('SELECT u.name FROM users u
                  LEFT JOIN cycles c ON c.user_id = u.id AND c.day = ?
                 WHERE u.active = 1 AND u.accepted_at IS NOT NULL AND u.joined_on <= ?
                   AND u.team_id = ? AND c.submitted_at IS NULL
                 ORDER BY u.name', [$today, $today, $teamId])->fetchAll(PDO::FETCH_COLUMN);
    if (!$names) {
        return 'kosong';
    }
    return chat_send(chat_reminder($names, $cfg['due_hour']), 15, $cfg['chat_webhook'])
        ? 'sent' : 'gagal';
}
