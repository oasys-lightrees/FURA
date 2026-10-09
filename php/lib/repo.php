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

/**
 * Setelan modul: pertanyaannya, ambangnya, jamnya.
 *
 * Dibaca sekali per permintaan, karena hampir setiap jalur membutuhkannya dan tidak ada
 * satu pun yang boleh berubah di tengah satu permintaan — laporan yang divalidasi dengan
 * satu ambang lalu disimpan dengan ambang lain adalah laporan yang tidak pernah benar.
 */
final class Cfg
{
    /** Per tim, karena satu permintaan bisa membaca setelan beberapa tim sekaligus —
     *  cron menyapa semuanya, dan layar owner membaca rekap semuanya. */
    private static array $held = [];

    public static function get(int $teamId): array
    {
        if (!array_key_exists($teamId, self::$held)) {
            self::$held[$teamId] = messi_config_normalize(self::stored($teamId));
        }
        return self::$held[$teamId];
    }

    /**
     * Setelan tersimpan, atau null kalau belum ada.
     *
     * Tabel yang belum dibuat bukan alasan untuk mematikan aplikasinya: setelan punya
     * bawaan, dan bawaan itu persis yang dipakai sebelum halaman setelan ada. Yang
     * memberitahu bahwa tabelnya kurang adalah halaman cek dan halaman pemutakhiran —
     * bukan 500 kosong dari sini.
     */
    private static function stored(int $teamId): ?array
    {
        try {
            $row = q1('SELECT value FROM settings WHERE name = ? AND team_id = ?',
                      ['messi', $teamId]);
        } catch (PDOException $e) {
            // 42S02 tabelnya belum ada, 42S22 kolom team_id-nya belum ada: dua-duanya
            // pemasangan yang belum dimutakhirkan, dan dua-duanya berarti bawaan.
            if (in_array($e->getCode(), ['42S02', '42S22'], true)) {
                return null;
            }
            throw $e;
        }
        return $row ? json_decode((string) $row['value'], true) : null;
    }

    /** Dipakai setelah admin menyimpan, dan oleh tes yang berganti setelan. */
    public static function forget(): void { self::$held = []; }
}

/** Setelan sebuah tim. Tanpa argumen: tim bawaan, yang satu-satunya tim di pemasangan
 *  yang belum pernah membuat tim kedua. */
function repo_config(?int $teamId = null): array
{
    return Cfg::get($teamId ?? repo_default_team());
}

function repo_save_config(array $user, int $teamId, $in): array
{
    if (!in_array($user['role'] ?? '', ['admin', 'owner'], true)) {
        throw new RepoError('Halaman ini untuk admin.');
    }
    $cfg = messi_config_normalize($in);
    q('INSERT INTO settings (name, team_id, value, updated_at, updated_by) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at),
                               updated_by = VALUES(updated_by)',
      ['messi', $teamId, json_encode($cfg, JSON_UNESCAPED_UNICODE), Clock::nowUtcSql(),
       (int) $user['id']]);
    Cfg::forget();
    // Modul MESSI tim ini adalah hasil setelan di atas, bukan dokumen kedua di sebelahnya.
    // Dituliskan ulang di sini, sesudah Cfg::forget() — kalau sebelumnya, yang dipakai
    // adalah setelan lama yang masih tersimpan di memori permintaan ini.
    require_once __DIR__ . '/katalog.php';
    katalog_sync_messi($teamId);
    return $cfg;
}

/* ------------------------------------------------------------------------ tim */

/** Tim yang ada, berurut nama. */
function repo_teams(bool $withInactive = false): array
{
    $where = $withInactive ? '' : ' WHERE active = 1';
    $out = [];
    foreach (q('SELECT id, name, active FROM teams' . $where . ' ORDER BY name') as $r) {
        $out[(int) $r['id']] = ['id' => (int) $r['id'], 'name' => $r['name'],
                                'active' => (bool) $r['active']];
    }
    return $out;
}

/**
 * Tim yang dipakai kalau tidak ada yang menyebutkan tim mana.
 *
 * Selalu ada satu, dibuatkan kalau belum: orang tanpa tim tidak muncul di rekap mana pun,
 * dan laporan tanpa tim tidak punya pertanyaan untuk dijawab.
 */
function repo_default_team(): int
{
    // Yang aktif dulu. Tim yang sudah dimatikan tidak muncul di pemilih mana pun, jadi
    // menjadikannya tim bawaan berarti orang baru dimasukkan ke tim yang tidak terlihat
    // oleh siapa pun — termasuk oleh yang memasukkannya.
    $row = q1('SELECT id FROM teams WHERE active = 1 ORDER BY id LIMIT 1')
        ?: q1('SELECT id FROM teams ORDER BY id LIMIT 1');
    if ($row) {
        return (int) $row['id'];
    }
    q('INSERT INTO teams (name, created_at) VALUES (?,?)', ['Tim', Clock::nowUtcSql()]);
    return (int) db()->lastInsertId();
}

/** Tim orang ini, atau tim bawaan kalau dia belum punya. */
function repo_team_of(array $user): int
{
    return !empty($user['team_id']) ? (int) $user['team_id'] : repo_default_team();
}

function repo_add_team(array $user, string $name): int
{
    if (!in_array($user['role'] ?? '', ['admin', 'owner'], true)) {
        throw new RepoError('Halaman ini untuk admin.');
    }
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    if ($name === '') {
        throw new RepoError('Isi nama timnya.');
    }
    if (mb_strlen($name) > 60) {
        throw new RepoError('Nama timnya kepanjangan.');
    }
    if (q1('SELECT id FROM teams WHERE name = ?', [$name])) {
        throw new RepoError('Sudah ada tim dengan nama itu.');
    }
    q('INSERT INTO teams (name, created_at) VALUES (?,?)', [$name, Clock::nowUtcSql()]);
    return (int) db()->lastInsertId();
}

/**
 * Berapa orang yang masih menunjuk ke tim ini, dan berapa laporan yang sudah menyebutnya.
 *
 * Dua angka ini yang memutuskan sebuah tim boleh dihapus atau cuma boleh dimatikan.
 *
 * @return array{orang:int, laporan:int}
 */
function repo_team_usage(int $teamId): array
{
    $orang = (int) q1('SELECT COUNT(*) AS n FROM users WHERE team_id = ?', [$teamId])['n'];
    // Laporan bisa menunjuk ke tim lewat dua jalan: timnya sendiri saat dikirim, dan
    // modul milik tim itu. Yang kedua yang menggigit — modul ikut terhapus bersama
    // timnya, dan kunci asing laporan ke modul itu RESTRICT.
    $lap = (int) q1('SELECT COUNT(*) AS n FROM cycles c
                       LEFT JOIN modules m ON m.id = c.module_id
                      WHERE c.team_id = ? OR m.team_id = ?', [$teamId, $teamId])['n'];
    return ['orang' => $orang, 'laporan' => $lap];
}

/**
 * Mematikan atau menghidupkan sebuah tim.
 *
 * Yang dimatikan hilang dari semua pemilih — pemilih tim di rekap, kotak "Tim" di halaman
 * orang, halaman Pertanyaan dan Modul — tapi laporan lamanya tetap utuh dan tetap terbaca.
 * Inilah jalan yang benar untuk divisi yang bubar: menghapusnya berarti membuang rekap
 * tiga bulan bersamanya.
 */
function repo_set_team_active(array $user, int $teamId, bool $active): void
{
    require_once __DIR__ . '/auth.php';
    if (!is_manager($user)) {
        throw new RepoError('Halaman ini untuk admin.');
    }
    $tim = repo_teams(true)[$teamId] ?? null;
    if (!$tim) {
        throw new RepoError('Tim itu tidak ada.');
    }
    if (!$active) {
        $pakai = repo_team_usage($teamId);
        if ($pakai['orang'] > 0) {
            throw new RepoError('Masih ada ' . $pakai['orang'] . ' orang di tim ini. '
                              . 'Pindahkan dulu mereka ke tim lain.');
        }
        // Selalu harus ada satu tim yang hidup: tanpa itu orang berikutnya yang ditambahkan
        // tidak punya tim untuk ditempati, dan pelaporan harian berhenti punya pertanyaan.
        $lain = (int) q1('SELECT COUNT(*) AS n FROM teams WHERE active = 1 AND id <> ?',
                         [$teamId])['n'];
        if ($lain === 0) {
            throw new RepoError('Ini satu-satunya tim yang aktif. Buat tim lain dulu.');
        }
    }
    q('UPDATE teams SET active = ? WHERE id = ?', [$active ? 1 : 0, $teamId]);
}

/**
 * Menghapus tim — hanya yang belum pernah dipakai sama sekali.
 *
 * Begitu ada satu laporan yang menyebutnya, timnya jadi bagian dari riwayat: menghapusnya
 * membuat rekap bulan lalu kehilangan namanya, dan modul beserta pertanyaannya ikut
 * terbawa. Yang itu dimatikan, bukan dihapus.
 *
 * Penjagaan ini juga menyelamatkan orangnya dari pesan yang tidak bisa dibaca: tanpa ini
 * MySQL sendiri yang menolak, lewat kunci asing laporan ke modul, dan yang muncul di layar
 * adalah SQLSTATE[23000] — benar, tapi tidak memberi tahu apa pun tentang apa yang harus
 * dikerjakan sekarang.
 */
function repo_delete_team(array $user, int $teamId): void
{
    require_once __DIR__ . '/auth.php';
    if (!is_manager($user)) {
        throw new RepoError('Halaman ini untuk admin.');
    }
    if (!isset(repo_teams(true)[$teamId])) {
        throw new RepoError('Tim itu tidak ada.');
    }
    $pakai = repo_team_usage($teamId);
    if ($pakai['orang'] > 0) {
        throw new RepoError('Masih ada ' . $pakai['orang'] . ' orang di tim ini. '
                          . 'Pindahkan dulu mereka ke tim lain.');
    }
    if ($pakai['laporan'] > 0) {
        throw new RepoError('Tim ini sudah punya ' . $pakai['laporan'] . ' laporan, jadi '
                          . 'tidak bisa dihapus — rekap lamanya akan ikut hilang. '
                          . 'Matikan saja: namanya hilang dari semua pilihan, laporannya tetap bisa dibaca.');
    }
    if ((int) q1('SELECT COUNT(*) AS n FROM teams')['n'] <= 1) {
        throw new RepoError('Ini satu-satunya tim. Buat tim lain dulu.');
    }
    // Modul dan setelan tim ini ikut terhapus (CASCADE) — dan itu memang yang diinginkan:
    // keduanya tidak berarti apa-apa tanpa timnya, dan sampai di sini sudah dipastikan
    // belum ada satu pun laporan yang menunjuk ke sana.
    q('DELETE FROM teams WHERE id = ?', [$teamId]);
}

/** The page's ids for people and promises. Prefixed so the two can never be confused,
 *  and so a raw database id is never a valid document id by accident. */
function uid(int $id): string { return 'u_' . $id; }
function unuid(string $u): ?int { return preg_match('/^u_(\d+)$/', $u, $m) ? (int) $m[1] : null; }
function cid(int $id): string { return 'k' . $id; }
function uncid(string $c): ?int { return preg_match('/^k(\d+)$/', $c, $m) ? (int) $m[1] : null; }

/* ------------------------------------------------------------------ reading */

/**
 * Siapa saja yang ada, untuk halaman.
 *
 * `$onlyTeam` membatasi daftarnya ke satu tim: leader membaca rekap timnya sendiri, dan
 * nama orang di tim lain bukan miliknya untuk dibaca. Owner dan admin tidak dibatasi.
 */
function repo_roster(?int $onlyTeam = null): array
{
    $where = 'active = 1 AND accepted_at IS NOT NULL';
    $args = [];
    if ($onlyTeam !== null) {
        $where .= ' AND team_id = ?';
        $args[] = $onlyTeam;
    }
    $out = [];
    foreach (q('SELECT id, name, joined_on, role, team_id FROM users
                 WHERE ' . $where . ' ORDER BY name', $args) as $r) {
        $out[uid((int) $r['id'])] = [
            'name'   => $r['name'],
            'joined' => $r['joined_on'],
            'leader' => $r['role'] !== 'player',
            'team'   => $r['team_id'] === null ? null : (int) $r['team_id'],
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
function repo_cycles(int $sinceDays = 90, ?int $onlyUser = null, ?int $onlyTeam = null): array
{
    $floor = messi_add_days(Clock::today(), -$sinceDays);
    $where = 'day >= ?';
    $args = [$floor];
    if ($onlyUser !== null) {
        $where .= ' AND user_id = ?';
        $args[] = $onlyUser;
    }
    if ($onlyTeam !== null) {
        // Timnya diambil dari laporannya, bukan dari orangnya: orang yang pindah tim
        // tidak membawa laporan lamanya ikut pindah.
        $where .= ' AND team_id = ?';
        $args[] = $onlyTeam;
    }
    $out = [];
    foreach (q('SELECT user_id, day, team_id, status, answers, submitted_at FROM cycles
                 WHERE ' . $where . ' ORDER BY day', $args) as $r) {
        $doc = json_decode((string) $r['answers'], true);
        $doc = is_array($doc) ? $doc : [];
        $doc['owner'] = uid((int) $r['user_id']);
        $doc['day']   = $r['day'];
        $doc['team']  = $r['team_id'] === null ? null : (int) $r['team_id'];
        // submitted_at is UTC in the database; the page only ever tests it for presence
        // and prints the Jakarta time, so hand it over already converted.
        $doc['submittedAt'] = $r['submitted_at']
            ? (new DateTimeImmutable($r['submitted_at'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(MESSI_TZ))->format('c')
            : null;
        $doc['late'] = $r['status'] === 'late';
        // Izin: hari yang ditandai leader sebagai cuti, sakit atau dinas luar. Dikirim
        // sebagai penanda tersendiri, bukan sebagai status mentah, karena halaman tidak
        // pernah membaca status — dia menghitungnya sendiri dari ada-tidaknya laporan.
        $doc['excused'] = $r['status'] === 'excused';
        $out[$doc['owner'] . '__' . $r['day']] = $doc;
    }
    return $out;
}

function repo_commitments(int $sinceDays = 90, ?int $onlyUser = null, ?int $onlyTeam = null): array
{
    $floor = messi_add_days(Clock::today(), -$sinceDays);
    $where = '(c.due_date >= ? OR c.status = ?)';
    $args = [$floor, 'open'];
    if ($onlyUser !== null) {
        $where .= ' AND c.user_id = ?';
        $args[] = $onlyUser;
    }
    if ($onlyTeam !== null) {
        $where .= ' AND c.user_id IN (SELECT id FROM users WHERE team_id = ?)';
        $args[] = $onlyTeam;
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
/**
 * Modul yang dilaporkan sebuah laporan harian.
 *
 * Untuk sekarang selalu MESSI: dialah satu-satunya modul yang punya layar pengisian.
 * Yang disusun sendiri sudah bisa dibuat dan disimpan, tapi belum punya layarnya — jadi
 * belum ada laporan yang menunjuk ke sana.
 *
 * Mengembalikan null kalau tabel modulnya belum ada, yaitu di sela antara berkas baru
 * diunggah dan database dimutakhirkan. Di keadaan itu kunci unik cycles juga masih yang
 * lama, jadi menyimpan tanpa modul tetap aman.
 */
function repo_module_id(?int $teamId): ?int
{
    if ($teamId === null) {
        return null;
    }
    // Sengaja tidak disimpan di memori: satu pencarian berindeks tiap kali jauh lebih
    // murah daripada satu lagi tempat yang harus dibersihkan saat tes berpindah database.
    require_once __DIR__ . '/katalog.php';
    $row = q_opt('SELECT id FROM modules WHERE team_id = ? AND code = ? LIMIT 1',
                 [$teamId, MODUL_MESSI])?->fetch();
    if (!$row) {
        katalog_seed($teamId);
        $row = q_opt('SELECT id FROM modules WHERE team_id = ? AND code = ? LIMIT 1',
                     [$teamId, MODUL_MESSI])?->fetch();
    }
    return $row ? (int) $row['id'] : null;
}

/**
 * Satu laporan per orang per hari per modul.
 *
 * module_id wajib ikut. Kunci uniknya sekarang (user_id, day, module_id), dan MySQL
 * menganggap dua NULL sebagai dua nilai yang berbeda — menyimpan tanpa modul membuat
 * ON DUPLICATE KEY tidak pernah menyala, dan satu orang bisa punya dua laporan untuk
 * hari yang sama tanpa satu pun pesan kesalahan.
 */
function repo_ensure_cycle(int $userId, string $day, ?int $teamId = null,
                           ?int $moduleId = null): int
{
    if ($moduleId === null) {
        if ($teamId === null) {
            $u = q1('SELECT team_id FROM users WHERE id = ?', [$userId]);
            $teamId = $u && $u['team_id'] !== null ? (int) $u['team_id'] : null;
        }
        $moduleId = repo_module_id($teamId);
    }
    q('INSERT INTO cycles (user_id, day, team_id, module_id, status, created_at)
       VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
      [$userId, $day, $teamId, $moduleId, 'pending', Clock::nowUtcSql()]);
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

    $team = repo_team_of($user);
    $cfg = repo_config($team);
    $answers = [
        'grid'       => is_array($doc['grid'] ?? null) ? $doc['grid'] : [],
        'detail'     => trim((string) ($doc['detail'] ?? '')),
        'plans'      => messi_plans($doc, $cfg['max_plans']),   // bentuk lama maupun baru
        'escalation' => trim((string) ($doc['escalation'] ?? '')),
        'prista'     => trim((string) ($doc['prista'] ?? '')),
        'declared'   => !empty($doc['declared']),
        // Pertanyaan boleh berubah; laporan yang sudah dikirim tidak. Cuplikan ini yang
        // membuat laporan bulan lalu tetap terbaca dengan ambang dan channel saat itu.
        'cfg'        => messi_config_snapshot($cfg),
    ];

    // The same check the page ran, run again where it cannot be skipped.
    $problem = messi_validate($answers, $cfg);
    if ($problem !== null) {
        throw new RepoError($problem);
    }
    foreach ($answers['plans'] as $p) {
        if ($p['due'] !== '' && $p['due'] < $today) {
            throw new RepoError('Tanggalnya sudah lewat. Pilih hari ini atau sesudahnya.');
        }
    }

    $status = messi_submit_status(Clock::hour(), $cfg['due_hour']);
    $now = Clock::nowUtcSql();
    $cycleId = repo_ensure_cycle($userId, $today, $team);
    // Timnya ditetapkan saat laporan dikirim, dan tidak diubah lagi sesudahnya: rekap
    // bulan lalu tidak boleh ikut berpindah waktu orangnya pindah tim.
    q('UPDATE cycles SET team_id = ? WHERE id = ? AND team_id IS NULL', [$team, $cycleId]);

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

/* ------------------------------------------------------------------- izin */

/** Sejauh mana ke depan izin boleh dicatat sekaligus. Cuti dua bulan masih masuk; satu
 *  tahun hampir selalu salah ketik tanggal. */
const MESSI_IZIN_MAX_DAYS = 62;

/**
 * Menandai hari-hari seseorang sebagai izin, atau membatalkannya.
 *
 * Inilah satu-satunya jalan menghapus tanda "tidak lapor" yang tidak adil. Sebelum ini
 * ada, orang yang cuti seminggu kembali ke kantor dengan lima hari merah di rekapnya dan
 * tidak ada satu pun tombol untuk membetulkannya — dan rekap yang menyimpan tuduhan yang
 * semua orang tahu salah adalah rekap yang berhenti dibaca.
 *
 * Dua hal yang dijaga di sini:
 *   - Yang menandai admin atau owner, bukan orangnya sendiri, dan bukan juga leadernya.
 *     Izin yang bisa diberikan sendiri bukan izin, cuma tombol "hapus tanda merah" — dan
 *     begitu satu baris merahnya tidak berarti apa-apa, tidak ada baris merah lain yang
 *     berarti apa-apa.
 *   - Hari yang laporannya sudah masuk tidak disentuh. Menimpanya dengan izin berarti
 *     laporan yang sungguhan hilang dari rekap, dan tidak ada yang akan tahu kenapa.
 *
 * @return int berapa hari yang benar-benar berubah
 */
function repo_set_excused(array $actor, int $userId, string $from, string $to, bool $on,
                          string $note = ''): int
{
    require_once __DIR__ . '/auth.php';
    if (!is_manager($actor)) {
        throw new RepoError('Yang bisa menandai izin cuma admin dan owner.');
    }
    $target = q1('SELECT id, name, team_id, joined_on FROM users WHERE id = ?', [$userId]);
    if (!$target) {
        throw new RepoError('Orang itu tidak ada.');
    }
    if (!messi_is_day($from) || !messi_is_day($to)) {
        throw new RepoError('Tanggalnya belum lengkap.');
    }
    if ($to < $from) {
        throw new RepoError('Tanggal selesainya sebelum tanggal mulai.');
    }
    if (messi_day_span($from, $to) > MESSI_IZIN_MAX_DAYS) {
        throw new RepoError('Rentangnya lebih dari ' . MESSI_IZIN_MAX_DAYS . ' hari. '
                          . 'Catat per bulan saja.');
    }

    $today = Clock::today();
    $now = Clock::nowUtcSql();
    $team = $target['team_id'] === null ? null : (int) $target['team_id'];
    $n = 0;

    for ($day = $from; $day <= $to; $day = messi_add_days($day, 1)) {
        // Hari libur tidak dihitung tidak lapor sejak awal, jadi menandainya izin cuma
        // menambah baris yang tidak berarti apa-apa.
        if (!messi_is_workday($day) || $day < $target['joined_on']) {
            continue;
        }
        $row = q1('SELECT id, status FROM cycles WHERE user_id = ? AND day = ?',
                  [$userId, $day]);
        if ($row && in_array($row['status'], ['submitted', 'late'], true)) {
            continue;                   // laporannya sudah masuk; itu yang benar
        }
        if ($on) {
            if ($row && $row['status'] === 'excused') {
                continue;
            }
            $id = repo_ensure_cycle($userId, $day, $team);
            // Catatannya disimpan di dalam answers, bukan di kolom baru: hari izin tidak
            // punya jawaban apa pun, jadi tempat itu memang kosong. Siapa yang menandai
            // ikut dicatat — izin tanpa nama yang memberikannya tidak bisa ditanyakan
            // ke siapa pun.
            q('UPDATE cycles SET status = ?, answers = ?, submitted_at = NULL WHERE id = ?',
              ['excused',
               json_encode(['izin' => ['note' => mb_substr(trim($note), 0, 120),
                                       'by' => (int) $actor['id'], 'at' => $now]],
                           JSON_UNESCAPED_UNICODE),
               $id]);
            $n++;
        } elseif ($row && $row['status'] === 'excused') {
            // Dibatalkan: hari yang sudah lewat kembali jadi tidak lapor, hari yang belum
            // kembali menunggu. Membiarkan semuanya 'pending' akan membuat hari kemarin
            // terbaca "belum lapor" selamanya, karena penyapu hanya menyentuh hari
            // sebelum hari ini sekali — dan hari itu sudah dilewatinya.
            q('UPDATE cycles SET status = ?, answers = NULL WHERE id = ?',
              [$day < $today ? 'missed' : 'pending', $row['id']]);
            $n++;
        }
    }
    return $n;
}

/**
 * Hari-hari izin yang tercatat, untuk ditampilkan.
 *
 * Jendelanya dua arah: yang sudah lewat masih perlu terlihat untuk diperiksa, yang akan
 * datang perlu terlihat supaya cuti yang sudah dicatat tidak dicatat dua kali.
 *
 * @return array<int, array{user_id:int, name:string, day:string, note:string, by:int}>
 */
function repo_excused(?int $onlyTeam = null, int $back = 30, int $ahead = 92): array
{
    $where = 'c.status = ? AND c.day >= ? AND c.day <= ?';
    $args = ['excused', messi_add_days(Clock::today(), -$back),
             messi_add_days(Clock::today(), $ahead)];
    if ($onlyTeam !== null) {
        // Dari orangnya, bukan dari laporannya: izin bulan depan untuk orang yang baru
        // pindah tim harus terlihat oleh leader timnya yang sekarang.
        $where .= ' AND u.team_id = ?';
        $args[] = $onlyTeam;
    }
    $out = [];
    foreach (q('SELECT c.user_id, c.day, c.answers, u.name FROM cycles c
                  JOIN users u ON u.id = c.user_id
                 WHERE ' . $where . ' ORDER BY u.name, c.day', $args) as $r) {
        $a = json_decode((string) $r['answers'], true);
        $izin = is_array($a) && is_array($a['izin'] ?? null) ? $a['izin'] : [];
        $out[] = ['user_id' => (int) $r['user_id'], 'name' => (string) $r['name'],
                  'day' => (string) $r['day'], 'note' => (string) ($izin['note'] ?? ''),
                  'by' => (int) ($izin['by'] ?? 0)];
    }
    return $out;
}

/* -------------------------------------------------------------- the machine */

/** Opens today's cycles. Idempotent by the unique key, so the cron may run hourly.
 *  Returns the user ids that now have a cycle waiting. */
function repo_generate(string $day, ?int $teamId = null): array
{
    if (!messi_is_workday($day)) {
        return [];
    }
    $made = [];
    // Yang diundang tapi belum membuat passwordnya belum pernah bisa masuk, jadi hari
    // yang tidak dia isi bukan hari yang dia lewatkan.
    $where = 'active = 1 AND accepted_at IS NOT NULL AND joined_on <= ?';
    $args = [$day];
    if ($teamId !== null) {
        $where .= ' AND team_id = ?';
        $args[] = $teamId;
    }
    foreach (q('SELECT id, team_id FROM users WHERE ' . $where, $args) as $u) {
        $before = q1('SELECT id FROM cycles WHERE user_id = ? AND day = ?', [$u['id'], $day]);
        repo_ensure_cycle((int) $u['id'], $day, $u['team_id'] === null ? null : (int) $u['team_id']);
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

/**
 * A promise whose date has passed without an answer is broken, and says so on its own.
 *
 * Tenggat sebenarnya adalah hari kerja pertama pada atau sesudah tanggalnya, bukan
 * tanggalnya mentah-mentah. Satu UPDATE tidak cukup untuk aturan itu — hari libur tidak
 * bisa dihitung di dalam SQL tanpa menyalin daftar hari kerjanya ke sana, dan daftar yang
 * ditulis dua kali adalah daftar yang suatu hari berbeda di salah satunya. Barisnya
 * sedikit: yang diputar di sini cuma janji yang sudah lewat dan belum dijawab.
 */
function repo_break_overdue(string $today): int
{
    $n = 0;
    foreach (q('SELECT id, due_date FROM commitments WHERE status = ? AND due_date < ?',
               ['open', $today])->fetchAll() as $r) {
        if (messi_workday_on_or_after((string) $r['due_date']) >= $today) {
            continue;                   // hari kerjanya belum lewat; dia belum ingkar
        }
        q('UPDATE commitments SET status = ?, resolved_at = ? WHERE id = ?',
          ['broken', Clock::nowUtcSql(), (int) $r['id']]);
        $n++;
    }
    return $n;
}
