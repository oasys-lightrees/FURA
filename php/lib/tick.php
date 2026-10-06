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
        $cfg = repo_config($teamId);
        if ($hour < $cfg['open_hour']) {
            continue;
        }
        $tag = $today . ' t=' . $teamId;

        $made = repo_generate($today, $teamId);
        if ($made) {
            $did['opened'] = ($did['opened'] ?? 0) + count($made);
            log_job('generate', $tag . ' n=' . count($made));
        }

        if (!job_done('notify_open', $tag)) {
            $sent = (int) tick_notify_open($today, $teamId, $cfg);
            $did['asked'] = ($did['asked'] ?? 0) + $sent;
            log_job('notify_open', $tag . ' sent=' . $sent);
        }

        // One hour before closing — the nudge, and only to the people it is about.
        if ($hour >= $cfg['due_hour'] - 1 && $hour < $cfg['due_hour']
            && !job_done('notify_due', $tag)) {
            $sent = (int) tick_notify_due($today, $teamId, $cfg);
            $did['nudged'] = ($did['nudged'] ?? 0) + $sent;
            log_job('notify_due', $tag . ' sent=' . $sent);
        }
    }

    return $did;
}

/** One message to the team's own space, naming what is already owed today. */
function tick_notify_open(string $today, int $teamId, array $cfg): bool
{
    $due = q('SELECT u.name, c.action_text FROM commitments c JOIN users u ON u.id = c.user_id
               WHERE c.status = ? AND c.due_date <= ? AND u.active = 1 AND u.team_id = ?
               ORDER BY u.name', ['open', $today, $teamId])->fetchAll();
    return chat_send(chat_morning($today, $due), 15, $cfg['chat_webhook']);
}

/** Only sent when somebody is actually missing, and it names them. */
function tick_notify_due(string $today, int $teamId, array $cfg): bool
{
    $names = q('SELECT u.name FROM users u
                  LEFT JOIN cycles c ON c.user_id = u.id AND c.day = ?
                 WHERE u.active = 1 AND u.accepted_at IS NOT NULL AND u.joined_on <= ?
                   AND u.team_id = ? AND c.submitted_at IS NULL
                 ORDER BY u.name', [$today, $today, $teamId])->fetchAll(PDO::FETCH_COLUMN);
    if (!$names) {
        return false;
    }
    return chat_send(chat_reminder($names, $cfg['due_hour']), 15, $cfg['chat_webhook']);
}
