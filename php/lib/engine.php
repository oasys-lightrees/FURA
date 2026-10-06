<?php
/**
 * MESSI engine — the rules, with no database and no HTTP attached.
 *
 * A port of core/messi_core (Python) and the same logic the page runs in the browser.
 * Three implementations of one set of rules is two too many, so tests/test_engine.php
 * checks this one against the cases the Python suite pins down. If they ever disagree,
 * the Python tests are the reference.
 *
 * MESSI is a daily module, so this file carries daily cadence only. The general engine
 * (weekly, monthly, commitment-driven) lives in core/messi_core and comes across when a
 * second module does.
 */

declare(strict_types=1);

const MESSI_TZ = 'Asia/Jakarta';
const MESSI_OPEN_HOUR = 9;     // the day's report opens
const MESSI_DUE_HOUR  = 18;    // after this, a submission is late
const MESSI_WORKDAYS  = [1, 2, 3, 4, 5];   // Mon–Fri, ISO numbering

/** A clock that tests can freeze, so weekends and deadlines are reachable. */
final class Clock
{
    private static ?DateTimeImmutable $fixed = null;

    public static function freeze(string $utcIso): void
    {
        self::$fixed = new DateTimeImmutable($utcIso, new DateTimeZone('UTC'));
    }

    public static function unfreeze(): void
    {
        self::$fixed = null;
    }

    public static function utc(): DateTimeImmutable
    {
        return self::$fixed ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** The wall clock the squad actually works by. */
    public static function jakarta(): DateTimeImmutable
    {
        return self::utc()->setTimezone(new DateTimeZone(MESSI_TZ));
    }

    /** The Jakarta calendar day a report belongs to. Keying by UTC would give someone
     *  two reports on one working evening, or none. */
    public static function today(): string
    {
        return self::jakarta()->format('Y-m-d');
    }

    public static function hour(): int
    {
        return (int) self::jakarta()->format('G');
    }

    public static function nowUtcSql(): string
    {
        return self::utc()->format('Y-m-d H:i:s');
    }
}

function messi_is_workday(string $day): bool
{
    $dt = new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone('UTC'));
    return in_array((int) $dt->format('N'), MESSI_WORKDAYS, true);
}

function messi_add_days(string $day, int $n): string
{
    $dt = new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone('UTC'));
    return $dt->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
}

/** Working days from `$from` backwards, newest first, never before `$floor`. */
function messi_past_workdays(string $from, int $limit, string $floor): array
{
    $out = [];
    for ($d = $from; $d >= $floor && count($out) < $limit; $d = messi_add_days($d, -1)) {
        if (messi_is_workday($d)) {
            $out[] = $d;
        }
    }
    return $out;
}

const MESSI_ID_DAY = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const MESSI_ID_MON = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
                      'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

function messi_fmt_day(string $day): string
{
    $dt = new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone('UTC'));
    return MESSI_ID_DAY[(int) $dt->format('w')] . ' '
        . (int) $dt->format('j') . ' ' . MESSI_ID_MON[(int) $dt->format('n')];
}

/* ----------------------------------------------------------------- arithmetic */

function messi_num($v): float
{
    return is_numeric($v) ? (float) $v : 0.0;
}

/** The four figures nobody types. Reporting 13 open, 0 hanging and 2 hanging over three
 *  days at the same time has to be impossible, not merely discouraged. */
function messi_totals(array $grid, array $channels): array
{
    $sum = function (string $col) use ($grid, $channels): int {
        $t = 0;
        foreach ($channels as $ch) {
            $t += (int) messi_num($grid[$ch]['' . $col] ?? 0);
        }
        return $t;
    };
    $open = $sum('open');
    $gt3 = $sum('gt3');
    $lt3 = $sum('lt3');
    return [
        'open' => $open, 'gt3' => $gt3, 'lt3' => $lt3,
        'hanging' => $gt3 + $lt3, 'clear' => $open - $gt3 - $lt3, 'reply' => $sum('reply'),
    ];
}

/** The report's traffic light, derived from the figures instead of typed. */
function messi_lamp(array $t): array
{
    if ($t['gt3'] > 0) {
        return ['level' => 'red', 'label' => 'Ada yang gantung lebih dari 3 hari'];
    }
    if ($t['hanging'] > 0) {
        return ['level' => 'amber', 'label' => 'Masih ada yang gantung'];
    }
    return ['level' => 'green', 'label' => 'Tidak ada yang gantung'];
}

/* ------------------------------------------------------------------ lifecycle */

/** A day nobody reported is a recorded fact, not a missing row — derived here so a cron
 *  outage never quietly rewrites history. */
function messi_cycle_status(string $day, ?array $row, string $today): string
{
    if ($row && !empty($row['submitted_at'])) {
        return $row['status'] === 'late' ? 'late' : 'submitted';
    }
    return $day < $today ? 'missed' : 'pending';
}

function messi_submit_status(int $hourNow): string
{
    return $hourNow >= MESSI_DUE_HOUR ? 'late' : 'submitted';
}

/** A promise met after its date was not met. A rate that counts late as kept measures
 *  nothing at all. */
function messi_commitment_outcome(string $dueDate, string $today): string
{
    return $today <= $dueDate ? 'kept' : 'broken';
}

/** Missed working days, never counting the days before someone joined. */
function messi_missed_days(array $cyclesByDay, string $joinedOn, string $today, int $limit = 5): array
{
    $out = [];
    foreach (messi_past_workdays(messi_add_days($today, -1), $limit, $joinedOn) as $day) {
        if (messi_cycle_status($day, $cyclesByDay[$day] ?? null, $today) === 'missed') {
            $out[] = $day;
        }
    }
    return $out;
}

/* ------------------------------------------------------------------- rencana */

const MESSI_MAX_PLANS = 5;   // lebih dari ini bukan sapuan harian lagi, tapi daftar tugas

/**
 * Rencana sebuah laporan, selalu sebagai daftar.
 *
 * Laporan lama menyimpan satu `plan` + `due`; yang baru menyimpan `plans`. Dibaca di satu
 * tempat saja supaya laporan dua minggu pertama tetap terbaca utuh tanpa diubah apa pun
 * di database.
 */
function messi_plans(array $a): array
{
    $rows = [];
    if (isset($a['plans']) && is_array($a['plans'])) {
        $rows = $a['plans'];
    } elseif (($a['plan'] ?? '') !== '' || ($a['due'] ?? '') !== '') {
        $rows = [['action' => $a['plan'] ?? '', 'due' => $a['due'] ?? '']];
    }
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $action = trim((string) ($r['action'] ?? ''));
        $due = trim((string) ($r['due'] ?? ''));
        if ($action !== '' || $due !== '') {
            $out[] = ['action' => $action, 'due' => $due];
        }
        if (count($out) >= MESSI_MAX_PLANS) {
            break;
        }
    }
    return $out;
}

/* ----------------------------------------------------------------- validation */

/** Consistency first: the declaration attests that the figures above are true, so asking
 *  for it before they add up gets the order backwards. */
function messi_validate(array $a, array $channels): ?string
{
    $grid = $a['grid'] ?? [];
    $typed = false;
    foreach ($channels as $ch) {
        foreach (['open', 'gt3', 'lt3', 'reply'] as $col) {
            if (isset($grid[$ch][$col]) && $grid[$ch][$col] !== '') {
                $typed = true;
            }
        }
    }
    if (!$typed) {
        return 'Isi dulu angkanya. Kalau kosong semua, tulis 0.';
    }
    $t = messi_totals($grid, $channels);
    if ($t['hanging'] > $t['open']) {
        return 'Yang gantung lebih banyak daripada yang masih aktif. Cek lagi angkanya.';
    }
    if ($t['hanging'] > 0) {
        if (trim((string) ($a['detail'] ?? '')) === '') {
            return 'Tulis dulu yang mana saja yang gantung.';
        }
        $plans = messi_plans($a);
        if (!$plans) {
            return 'Tulis rencananya.';
        }
        foreach ($plans as $p) {
            if ($p['action'] === '') {
                return 'Ada tanggal tanpa rencana. Tulis rencananya, atau hapus barisnya.';
            }
            if ($p['due'] === '') {
                return 'Pilih tanggalnya. Tanpa tanggal, tidak ada yang bisa mengingatkan.';
            }
        }
    }
    if (empty($a['declared'])) {
        return 'Centang pernyataannya dulu.';
    }
    return null;
}

/* --------------------------------------------------------------------- report */

const MESSI_DECLARATION =
    'Saya sudah memastikan Messenger Squad saya semua terkelola dengan baik tanpa gantung, '
    . 'dan saya melaporkan laporan di atas dengan jujur sesuai kondisi sebenarnya.';

/** The format the squad already knows, generated instead of typed. Kept byte-identical to
 *  the browser's version so a report looks the same whichever built it. */
function messi_build_report(array $doc, string $reporter, array $channelDefs): string
{
    $grid = $doc['grid'] ?? [];
    $keys = array_keys($channelDefs);
    $t = messi_totals($grid, $keys);
    $lamp = messi_lamp($t);
    $tag = ['green' => '[HIJAU]', 'amber' => '[KUNING]', 'red' => '[MERAH]'][$lamp['level']];

    $open = [];
    foreach ($channelDefs as $key => $label) {
        $open[] = (int) messi_num($grid[$key]['open'] ?? 0) . ' ' . $label;
    }
    $line = function (string $k, ?string $v): string {
        $v = trim((string) $v);
        return $k . ': ' . ($v === '' ? 'Belum ada' : $v);
    };
    // Satu rencana tetap berbentuk satu baris, persis seperti laporan yang sudah dikenal
    // squad. Baru menjadi daftar begitu rencananya lebih dari satu.
    $rows = [];
    foreach (messi_plans($doc) as $p) {
        $rows[] = $p['action'] . ($p['due'] !== '' ? ' (target ' . messi_fmt_day($p['due']) . ')' : '');
    }
    // $line() memangkas spasi di ujung, jadi daftar berbutir disusun di luar helper itu —
    // kalau tidak, butir pertamanya naik ke baris judulnya.
    $planLine = count($rows) > 1
        ? 'g. Rencana:' . "\n- " . implode("\n- ", $rows)
        : null;
    $plan = $rows[0] ?? '';

    return implode("\n", [
        'MESSI Report',
        'Date: ' . messi_fmt_day($doc['day']) . ' (' . $doc['day'] . ')',
        'Squad: OASYS   Report by: ' . $reporter,
        '',
        $tag . ' ' . $lamp['label'],
        '',
        'a. Channel aktif/open: ' . implode(', ', $open),
        'b. Gantung >3 hari: ' . $t['gt3'],
        'c. Gantung <3 hari: ' . $t['lt3'],
        'd. Tidak gantung: ' . $t['clear'],
        'e. Sudah dibalas hari ini: ' . $t['reply'],
        $line('f. Yang masih gantung', $doc['detail'] ?? ''),
        $planLine ?? $line('g. Rencana', $plan),
        $line('h. Eskalasi', $doc['escalation'] ?? ''),
        $line('i. Calon PRISTA baru', $doc['prista'] ?? ''),
        '',
        'Deklarasi: ' . MESSI_DECLARATION,
    ]);
}
