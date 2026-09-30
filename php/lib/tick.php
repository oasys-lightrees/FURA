<?php
/**
 * What the hourly job actually does, as a function so it can be called twice in a test
 * and prove it means the same thing both times.
 *
 * Everything here is idempotent. Opening a cycle is guarded by the unique key on
 * (user_id, day); each message is recorded in job_log before it can be sent again. The
 * cron may fire twice in an hour, or miss an hour and catch up, and the day still comes
 * out the same.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/telegram.php';

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

    if (!messi_is_workday($today) || $hour < MESSI_OPEN_HOUR) {
        return $did;
    }

    /* -- the working day ---------------------------------------------------- */

    $made = repo_generate($today);
    if ($made) { $did['opened'] = count($made); log_job('generate', $today . ' n=' . count($made)); }

    // 09:00 — the ask.
    if (!job_done('notify_open', $today)) {
        $did['asked'] = tick_notify_open($today);
        log_job('notify_open', $today . ' sent=' . $did['asked']);
    }

    // 17:00 — the nudge, and only to the people it is about.
    if ($hour >= 17 && $hour < MESSI_DUE_HOUR && !job_done('notify_due', $today)) {
        $did['nudged'] = tick_notify_due($today);
        log_job('notify_due', $today . ' sent=' . $did['nudged']);
    }

    // 18:00 — what the day came to, for whoever has to act on it.
    if ($hour >= MESSI_DUE_HOUR && !job_done('notify_leader', $today)) {
        $did['recap'] = tick_notify_leader($today);
        log_job('notify_leader', $today . ' sent=' . $did['recap']);
    }

    return $did;
}

function tick_notify_open(string $today): int
{
    $sent = 0;
    foreach (q('SELECT * FROM users WHERE active = 1 AND joined_on <= ? AND telegram_chat_id IS NOT NULL',
               [$today]) as $u) {
        $uid = (int) $u['id'];
        $due = q('SELECT action_text FROM commitments WHERE user_id = ? AND status = ? AND due_date <= ?',
                 [$uid, 'open', $today])->fetchAll();
        $rows = [];
        foreach (q('SELECT day, status, submitted_at FROM cycles WHERE user_id = ? AND day < ?',
                   [$uid, $today]) as $r) {
            $rows[$r['day']] = $r;
        }
        $floor = max((string) $u['joined_on'], (string) cfg('first_day'));
        $absent = messi_missed_days($rows, $floor, $today, 5);
        if (tg_morning($u, auth_make_login_link($uid), $due, $absent)) {
            $sent++;
        }
    }
    return $sent;
}

function tick_notify_due(string $today): int
{
    $sent = 0;
    foreach (q('SELECT u.* FROM users u
                  LEFT JOIN cycles c ON c.user_id = u.id AND c.day = ?
                 WHERE u.active = 1 AND u.joined_on <= ? AND u.telegram_chat_id IS NOT NULL
                   AND c.submitted_at IS NULL', [$today, $today]) as $u) {
        if (tg_reminder($u, auth_make_login_link((int) $u['id'], 180))) {
            $sent++;
        }
    }
    return $sent;
}

function tick_notify_leader(string $today): int
{
    $lines = ['Rekap MESSI ' . messi_fmt_day($today), ''];
    $waiting = [];
    foreach (q('SELECT u.name, c.status, c.answers FROM users u
                  LEFT JOIN cycles c ON c.user_id = u.id AND c.day = ?
                 WHERE u.active = 1 AND u.joined_on <= ? ORDER BY u.name',
               [$today, $today]) as $r) {
        if (empty($r['answers'])) {
            $waiting[] = $r['name'];
            continue;
        }
        $a = json_decode((string) $r['answers'], true) ?: [];
        $t = messi_totals($a['grid'] ?? [], array_keys(MESSI_CHANNELS));
        $mark = ['green' => '🟢', 'amber' => '🟡', 'red' => '🔴'][messi_lamp($t)['level']];
        $lines[] = $mark . ' ' . $r['name'] . ' — ' . $t['open'] . ' aktif, '
                 . $t['hanging'] . ' gantung' . ($r['status'] === 'late' ? ' (telat)' : '');
    }
    if ($waiting) {
        $lines[] = '';
        $lines[] = 'Belum lapor: ' . implode(', ', $waiting) . '.';
    }
    $text = implode("\n", $lines);

    $sent = 0;
    foreach (q("SELECT * FROM users WHERE active = 1 AND role IN ('leader','admin')
                 AND telegram_chat_id IS NOT NULL") as $u) {
        if (tg_send($u['telegram_chat_id'], $text)) {
            $sent++;
        }
    }
    return $sent;
}
