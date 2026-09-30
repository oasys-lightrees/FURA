<?php
/**
 * The machine. One cron entry, run every hour:
 *
 *   0 * * * *  /usr/local/bin/php /home/AKUN/public_html/messi/cron/tick.php >/dev/null 2>&1
 *
 * Everything it does is idempotent — opening a cycle is guarded by the unique key on
 * (user_id, day), and each message is recorded in job_log before it can be sent again.
 * Running it twice in an hour, or missing an hour and catching up, both come out right.
 *
 * Reachable over HTTP too, for hosts that only offer URL crons:
 *   https://contoh.com/messi/cron/tick.php?key=CRON_KEY
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/telegram.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    // Without this, anyone could make the bot message the whole squad at will.
    $given = (string) ($_GET['key'] ?? '');
    $want = (string) cfg('cron_key');
    if ($want === '' || !hash_equals($want, $given)) {
        http_response_code(403);
        exit("no\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

/** Has this job already run for this day? job_log is the memory that makes the hourly
 *  cron safe to repeat. */
function job_done(string $kind, string $day): bool
{
    return (bool) q1('SELECT id FROM job_log WHERE kind = ? AND detail LIKE ? LIMIT 1',
                     [$kind, $day . '%']);
}

$today = Clock::today();
$hour  = Clock::hour();
$done  = [];

/* -- housekeeping, every run ------------------------------------------------ */

$missed = repo_reap($today);
if ($missed) { $done[] = "missed=$missed"; log_job('reap', $today . ' n=' . $missed); }

$broken = repo_break_overdue($today);
if ($broken) { $done[] = "broken=$broken"; log_job('break_overdue', $today . ' n=' . $broken); }

$swept = auth_sweep();
if ($swept) { $done[] = "swept=$swept"; }

/* -- the working day -------------------------------------------------------- */

if (messi_is_workday($today) && $hour >= MESSI_OPEN_HOUR) {

    $made = repo_generate($today);
    if ($made) { $done[] = 'opened=' . count($made); log_job('generate', $today . ' n=' . count($made)); }

    // 09:00 — the ask.
    if (!job_done('notify_open', $today)) {
        $sent = 0;
        foreach (q('SELECT * FROM users WHERE active = 1 AND joined_on <= ? AND telegram_chat_id IS NOT NULL',
                   [$today]) as $u) {
            $uid = (int) $u['id'];
            $due = q('SELECT action_text FROM commitments
                       WHERE user_id = ? AND status = ? AND due_date <= ?',
                     [$uid, 'open', $today])->fetchAll();
            $rows = [];
            foreach (q('SELECT day, status, submitted_at FROM cycles WHERE user_id = ? AND day < ?',
                       [$uid, $today]) as $r) {
                $rows[$r['day']] = $r;
            }
            $absent = messi_missed_days($rows, max($u['joined_on'], (string) cfg('first_day')), $today, 5);
            if (tg_morning($u, auth_make_login_link($uid), $due, $absent)) {
                $sent++;
            }
        }
        log_job('notify_open', $today . ' sent=' . $sent);
        $done[] = "asked=$sent";
    }

    // 17:00 — the nudge, and only to the people it is about.
    if ($hour >= 17 && $hour < MESSI_DUE_HOUR && !job_done('notify_due', $today)) {
        $sent = 0;
        foreach (q('SELECT u.* FROM users u
                      LEFT JOIN cycles c ON c.user_id = u.id AND c.day = ?
                     WHERE u.active = 1 AND u.joined_on <= ? AND u.telegram_chat_id IS NOT NULL
                       AND c.submitted_at IS NULL', [$today, $today]) as $u) {
            if (tg_reminder($u, auth_make_login_link((int) $u['id'], 180))) {
                $sent++;
            }
        }
        log_job('notify_due', $today . ' sent=' . $sent);
        $done[] = "nudged=$sent";
    }

    // 18:00 — what the day came to, for whoever has to act on it.
    if ($hour >= MESSI_DUE_HOUR && !job_done('notify_leader', $today)) {
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
        log_job('notify_leader', $today . ' sent=' . $sent);
        $done[] = "recap=$sent";
    }
}

echo $today . ' ' . sprintf('%02d:00', $hour) . ' ' . ($done ? implode(' ', $done) : 'nothing to do') . "\n";
