<?php
/**
 * Reading and writing the squad's data.
 *
 * The page sends whole documents, so every write is checked here rather than trusted:
 * the browser decides what to show, the server decides what is true. Three rules carry
 * most of the weight — you may only write your own rows, only for today, and the server
 * recomputes anything that judges you (late, kept, broken) from its own clock.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Channel keys and their labels, in the order the report prints them. */
const MESSI_CHANNELS = ['WAG' => 'WAG', 'TGG' => 'TGG', 'GCG' => 'GCG'];

/** The page's ids for people and promises. Prefixed so the two can never be confused,
 *  and so a raw database id is never a valid document id by accident. */
function uid(int $id): string { return 'u_' . $id; }
function unuid(string $u): ?int { return preg_match('/^u_(\d+)$/', $u, $m) ? (int) $m[1] : null; }
function cid(int $id): string { return 'k' . $id; }
function uncid(string $c): ?int { return preg_match('/^k(\d+)$/', $c, $m) ? (int) $m[1] : null; }

/* ------------------------------------------------------------------ reading */

function repo_roster(): array
{
    $out = [];
    foreach (q('SELECT id, name, joined_on, role FROM users WHERE active = 1 ORDER BY name') as $r) {
        $out[uid((int) $r['id'])] = [
            'name'   => $r['name'],
            'joined' => $r['joined_on'],
            'leader' => $r['role'] !== 'player',
        ];
    }
    return $out;
}

/**
 * Cycles as the page wants them: keyed `u_7__2026-09-30`, with the answers unpacked.
 *
 * `$onlyUser` is not an optimisation. A player's page has no screen that shows anyone
 * else's report, so sending them one would be handing out something they cannot see but
 * could read — and a report says what someone did all day.
 */
function repo_cycles(int $sinceDays = 90, ?int $onlyUser = null): array
{
    $floor = messi_add_days(Clock::today(), -$sinceDays);
    $where = 'day >= ?';
    $args = [$floor];
    if ($onlyUser !== null) {
        $where .= ' AND user_id = ?';
        $args[] = $onlyUser;
    }
    $out = [];
    foreach (q('SELECT user_id, day, status, answers, submitted_at FROM cycles
                 WHERE ' . $where . ' ORDER BY day', $args) as $r) {
        $doc = json_decode((string) $r['answers'], true);
        $doc = is_array($doc) ? $doc : [];
        $doc['owner'] = uid((int) $r['user_id']);
        $doc['day']   = $r['day'];
        // submitted_at is UTC in the database; the page only ever tests it for presence
        // and prints the Jakarta time, so hand it over already converted.
        $doc['submittedAt'] = $r['submitted_at']
            ? (new DateTimeImmutable($r['submitted_at'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(MESSI_TZ))->format('c')
            : null;
        $doc['late'] = $r['status'] === 'late';
        $out[$doc['owner'] . '__' . $r['day']] = $doc;
    }
    return $out;
}

function repo_commitments(int $sinceDays = 90, ?int $onlyUser = null): array
{
    $floor = messi_add_days(Clock::today(), -$sinceDays);
    $where = '(c.due_date >= ? OR c.status = ?)';
    $args = [$floor, 'open'];
    if ($onlyUser !== null) {
        $where .= ' AND c.user_id = ?';
        $args[] = $onlyUser;
    }
    $out = [];
    foreach (q('SELECT c.id, c.user_id, c.action_text, c.due_date, c.status, c.resolved_at,
                       cy.day AS from_day
                  FROM commitments c LEFT JOIN cycles cy ON cy.id = c.cycle_id
                 WHERE ' . $where . '
                 ORDER BY c.due_date', $args) as $r) {
        $id = cid((int) $r['id']);
        $out[$id] = [
            'id'     => $id,
            'owner'  => uid((int) $r['user_id']),
            'action' => $r['action_text'],
            'due'    => $r['due_date'],
            'status' => $r['status'],
            'from'   => $r['from_day'],
            'resolvedAt' => $r['resolved_at'] ? substr($r['resolved_at'], 0, 10) : null,
        ];
    }
    return $out;
}

/* ------------------------------------------------------------------ writing */

class RepoError extends RuntimeException {}

/** Today's cycle row for this person, created if the cron has not run yet. Returns the
 *  row id. The unique key on (user_id, day) is what makes this safe to call twice. */
function repo_ensure_cycle(int $userId, string $day): int
{
    q('INSERT INTO cycles (user_id, day, status, created_at) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
      [$userId, $day, 'pending', Clock::nowUtcSql()]);
    return (int) db()->lastInsertId();
}

/**
 * Saves a report. Everything that judges the reporter is computed here: the day comes
 * from the server's Jakarta clock, and late from the server's hour. A browser with a
 * wrong clock, or a helpful one, cannot make yesterday's silence disappear.
 */
function repo_save_cycle(array $user, string $docId, array $doc): array
{
    $userId = (int) $user['id'];
    $today = Clock::today();

    if ($docId !== uid($userId) . '__' . $today) {
        throw new RepoError('Laporan hanya bisa diisi untuk hari ini, dan hanya untuk diri sendiri.');
    }
    if ($today < $user['joined_on']) {
        throw new RepoError('Tanggal mulai kamu belum tiba.');
    }

    $answers = [
        'grid'       => is_array($doc['grid'] ?? null) ? $doc['grid'] : [],
        'detail'     => trim((string) ($doc['detail'] ?? '')),
        'plans'      => messi_plans($doc),      // membaca bentuk lama maupun baru
        'escalation' => trim((string) ($doc['escalation'] ?? '')),
        'prista'     => trim((string) ($doc['prista'] ?? '')),
        'declared'   => !empty($doc['declared']),
    ];

    // The same check the page ran, run again where it cannot be skipped.
    $problem = messi_validate($answers, array_keys(MESSI_CHANNELS));
    if ($problem !== null) {
        throw new RepoError($problem);
    }
    foreach ($answers['plans'] as $p) {
        if ($p['due'] !== '' && $p['due'] < $today) {
            throw new RepoError('Tanggalnya sudah lewat. Pilih hari ini atau sesudahnya.');
        }
    }

    $status = messi_submit_status(Clock::hour());
    $now = Clock::nowUtcSql();
    $cycleId = repo_ensure_cycle($userId, $today);

    // Apakah permintaan bantuan ini perlu diumumkan? Hanya kalau ada isinya dan berbeda
    // dari yang sudah pernah diumumkan untuk hari yang sama — memperbaiki angka tidak
    // boleh mengirim ulang, tapi mengganti isi permintaannya harus.
    $before = q1('SELECT answers FROM cycles WHERE id = ?', [$cycleId]);
    $beforeA = json_decode((string) ($before['answers'] ?? ''), true);
    $announced = is_array($beforeA) ? (string) ($beforeA['escalation_announced'] ?? '') : '';
    $toAnnounce = ($answers['escalation'] !== '' && $answers['escalation'] !== $announced)
        ? $answers['escalation'] : null;
    // Penanda ikut disimpan di dalam answers, jadi tidak perlu kolom baru.
    $answers['escalation_announced'] = $toAnnounce ?? $announced;

    q('UPDATE cycles SET answers = ?, status = ?, submitted_at = ? WHERE id = ?',
      [json_encode($answers, JSON_UNESCAPED_UNICODE), $status, $now, $cycleId]);

    repo_sync_commitments($userId, $cycleId, $answers['plans'], $now);

    // Dikembalikan, tidak dikirim dari sini: repository tidak bicara ke pihak ketiga,
    // supaya laporan yang sudah tersimpan tidak pernah bisa digagalkan oleh Google.
    return ['status' => $status, 'day' => $today, 'cycle_id' => $cycleId,
            'announce' => $toAnnounce];
}

/**
 * Brings this cycle's promises in line with what the report now says.
 *
 * Matched by the text of the action, never by position in the list: deleting the first
 * row would shift every other one, and somebody's promise would silently become somebody
 * else's. A promise that is no longer in the report is cancelled rather than deleted, and
 * one that has already been settled is never touched — history is not the report's to
 * rewrite.
 */
function repo_sync_commitments(int $userId, int $cycleId, array $plans, string $now): void
{
    $open = [];
    foreach (q('SELECT id, action_text FROM commitments WHERE cycle_id = ? AND status = ?',
               [$cycleId, 'open']) as $r) {
        $open[$r['action_text']] = (int) $r['id'];
    }

    $keep = [];
    foreach ($plans as $p) {
        if ($p['action'] === '' || $p['due'] === '') {
            continue;
        }
        if (isset($open[$p['action']])) {
            q('UPDATE commitments SET due_date = ? WHERE id = ?', [$p['due'], $open[$p['action']]]);
            $keep[$open[$p['action']]] = true;
        } else {
            q('INSERT INTO commitments (user_id, cycle_id, action_text, due_date, status, created_at)
               VALUES (?,?,?,?,?,?)',
              [$userId, $cycleId, $p['action'], $p['due'], 'open', $now]);
        }
    }

    foreach ($open as $id) {
        if (!isset($keep[$id])) {
            q('UPDATE commitments SET status = ?, resolved_at = ? WHERE id = ?',
              ['cancelled', $now, $id]);
        }
    }
}

/**
 * Resolves a promise. The page may say "done"; whether that counts as kept is the
 * server's call, from the due date it recorded and the day it is now (ADR-0008).
 */
function repo_resolve_commitment(array $user, string $docId, array $doc): array
{
    $id = uncid($docId);
    if ($id === null) {
        // An id the page invented for a promise it has just made. The promise itself is
        // created by the cycle save, so there is nothing to do and nothing to report.
        return ['ignored' => true];
    }
    $row = q1('SELECT * FROM commitments WHERE id = ?', [$id]);
    if (!$row || (int) $row['user_id'] !== (int) $user['id']) {
        throw new RepoError('Janji itu bukan punyamu.');
    }
    if ($row['status'] !== 'open') {
        return ['status' => $row['status']];          // already settled; saying so twice is fine
    }

    $wanted = (string) ($doc['status'] ?? '');
    if ($wanted === 'cancelled') {
        q('UPDATE commitments SET status = ?, resolved_at = ? WHERE id = ?',
          ['cancelled', Clock::nowUtcSql(), $id]);
        return ['status' => 'cancelled'];
    }
    if ($wanted !== 'kept' && $wanted !== 'broken') {
        return ['ignored' => true];                   // not a resolution, so not our business
    }

    $outcome = messi_commitment_outcome($row['due_date'], Clock::today());
    q('UPDATE commitments SET status = ?, resolved_at = ?, resolved_cycle_id = ? WHERE id = ?',
      [$outcome, Clock::nowUtcSql(), repo_ensure_cycle((int) $user['id'], Clock::today()), $id]);
    return ['status' => $outcome];
}

/** The page writes its own roster entry on first run. Only the name is the browser's to
 *  set: joining dates decide who is counted absent, so they stay with the server. */
function repo_save_roster(array $user, string $docId, array $doc): array
{
    if ($docId !== uid((int) $user['id'])) {
        throw new RepoError('Itu bukan barismu.');
    }
    $name = trim((string) ($doc['name'] ?? ''));
    if ($name !== '' && $name !== $user['name']) {
        q('UPDATE users SET name = ? WHERE id = ?', [mb_substr($name, 0, 120), $user['id']]);
    }
    return ['ok' => true];
}

/* -------------------------------------------------------------- the machine */

/** Opens today's cycles. Idempotent by the unique key, so the cron may run hourly.
 *  Returns the user ids that now have a cycle waiting. */
function repo_generate(string $day): array
{
    if (!messi_is_workday($day)) {
        return [];
    }
    $made = [];
    foreach (q('SELECT id FROM users WHERE active = 1 AND joined_on <= ?', [$day]) as $u) {
        $before = q1('SELECT id FROM cycles WHERE user_id = ? AND day = ?', [$u['id'], $day]);
        repo_ensure_cycle((int) $u['id'], $day);
        if (!$before) {
            $made[] = (int) $u['id'];
        }
    }
    return $made;
}

/** Marks yesterday and earlier. A day nobody answered is recorded as missed rather than
 *  left blank, so an outage cannot quietly turn absence into "no data". */
function repo_reap(string $today): int
{
    return q('UPDATE cycles SET status = ? WHERE status = ? AND day < ?',
             ['missed', 'pending', $today])->rowCount();
}

/** A promise whose date has passed without an answer is broken, and says so on its own. */
function repo_break_overdue(string $today): int
{
    return q('UPDATE commitments SET status = ?, resolved_at = ?
               WHERE status = ? AND due_date < ?',
             ['broken', Clock::nowUtcSql(), 'open', $today])->rowCount();
}
