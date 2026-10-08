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
const MESSI_THRESHOLD = 3;     // "gantung lebih dari N hari" — batas merahnya
const MESSI_MAX_PLANS = 5;     // lebih dari ini bukan sapuan harian lagi, tapi daftar tugas
const MESSI_MAX_CHANNELS = 12;  // sapuan harian, bukan inventaris seluruh perusahaan
const MESSI_DECLARATION =
    'Saya sudah memastikan semua channel yang saya pegang terkelola dengan baik tanpa '
    . 'gantung, dan saya melaporkan laporan di atas dengan jujur sesuai kondisi sebenarnya.';

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

/* ---------------------------------------------------------------- konfigurasi */

/**
 * Pertanyaan MESSI, ambangnya, dan jamnya — sebagai data, bukan sebagai kode.
 *
 * Dulu semuanya konstanta: tiga channel, batas tiga hari, jam 9 sampai 18. Satu squad
 * saja memang cukup begitu. Begitu modulnya dipakai squad lain, hal yang di sini
 * kebetulan benar jadi hal yang harus bisa diubah tanpa menyentuh kode — "tidak boleh
 * gantung lebih dari 3 hari" di satu tempat bisa berarti 1 hari di tempat lain.
 *
 * Satu dokumen, dibaca di satu tempat, dipakai halaman maupun server. Yang tidak boleh
 * ikut: HTML. Semua teks di sini ditulis admin dan ditampilkan ke pemain, jadi semuanya
 * teks biasa yang di-escape saat digambar — kalau tidak, admin punya jalan menyuntikkan
 * skrip ke layar rekannya.
 */
function messi_config_default(): array
{
    return [
        'channels' => [
            ['key' => 'WAG', 'label' => 'WAG', 'full' => 'WhatsApp Group'],
            ['key' => 'TGG', 'label' => 'TGG', 'full' => 'Telegram Group'],
            ['key' => 'GCG', 'label' => 'GCG', 'full' => 'Google Chat'],
        ],
        'team_name'      => '',            // diisi tiap tim sendiri; kosong = tidak dicetak
        'chat_webhook'   => '',            // space tim ini; kosong = pakai yang di config.php
        'threshold_days' => MESSI_THRESHOLD,
        'open_hour'      => MESSI_OPEN_HOUR,
        'due_hour'       => MESSI_DUE_HOUR,
        'max_plans'      => MESSI_MAX_PLANS,
        'declaration'    => MESSI_DECLARATION,
        'questions' => [
            'grid' => [
                'label' => 'Berapa banyak hari ini?',
                'hint'  => 'Hitung channel yang kamu pegang. Gantung = ada yang nanya tapi belum dibalas.',
            ],
            'detail' => [
                'label'       => 'Yang mana saja?',
                'placeholder' => 'WAG Klien A — belum dibalas 4 hari',
                'error'       => 'Tulis dulu yang mana saja yang gantung.',
            ],
            'plan' => [
                'label'       => 'Mau diapakan?',
                'placeholder' => 'Balas setelah harga di-approve',
                'error'       => 'Tulis rencananya.',
            ],
            'due' => [
                'label' => 'Kapan beres?',
                'error' => 'Pilih tanggalnya. Tanpa tanggal, tidak ada yang bisa mengingatkan.',
            ],
            'escalation' => [
                'label'       => 'Ada yang perlu atasan tahu?',
                'hint'        => 'Ini yang dibaca atasan. Angkanya sudah otomatis.',
                'placeholder' => 'Butuh approve harga Klien A',
                'show'        => true,
            ],
            'prista' => [
                'label'       => 'Ada yang perlu jadi project?',
                'placeholder' => 'Revisi jadwal Vendor B',
                'show'        => true,
            ],
        ],
    ];
}

/** Satu baris teks yang ditulis admin: tanpa HTML, tanpa baris baru, tidak kosong. */
function messi_cfg_text($v, string $fallback, int $max = 300): string
{
    $t = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
    $t = mb_substr($t, 0, $max);
    return $t === '' ? $fallback : $t;
}

function messi_cfg_int($v, int $fallback, int $min, int $max): int
{
    if (!is_numeric($v)) {
        return $fallback;
    }
    $n = (int) $v;
    return $n < $min || $n > $max ? $fallback : $n;
}

/**
 * Dokumen apa pun yang masuk, dokumen yang bisa dipakai yang keluar.
 *
 * Dipakai dua kali: saat admin menyimpan, dan lagi saat dibaca. Yang kedua bukan
 * berlebihan — baris di database bisa lebih tua dari kode yang membacanya, dan satu
 * ambang yang hilang tidak boleh berarti halaman kosong.
 */
function messi_config_normalize($in): array
{
    $d = messi_config_default();
    if (!is_array($in)) {
        return $d;
    }

    $channels = [];
    $seen = [];
    foreach (is_array($in['channels'] ?? null) ? $in['channels'] : [] as $c) {
        if (!is_array($c)) {
            continue;
        }
        // Kodenya jadi kunci di grid dan atribut di halaman, jadi dibatasi keras.
        $key = strtoupper(trim((string) ($c['key'] ?? '')));
        if (!preg_match('/^[A-Z0-9_]{1,12}$/', $key) || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $label = messi_cfg_text($c['label'] ?? '', $key, 24);
        $channels[] = ['key' => $key, 'label' => $label,
                       'full' => messi_cfg_text($c['full'] ?? '', $label, 60)];
        if (count($channels) >= MESSI_MAX_CHANNELS) {
            break;
        }
    }
    // Nol channel bukan konfigurasi, itu modul tanpa pertanyaan. Kembali ke bawaannya.
    if (!$channels) {
        $channels = $d['channels'];
    }

    $q = [];
    $qin = is_array($in['questions'] ?? null) ? $in['questions'] : [];
    foreach ($d['questions'] as $name => $def) {
        $got = is_array($qin[$name] ?? null) ? $qin[$name] : [];
        $one = [];
        foreach ($def as $field => $fallback) {
            if ($field === 'show') {
                $one['show'] = !isset($got['show']) || (bool) $got['show'];
            } else {
                $one[$field] = messi_cfg_text($got[$field] ?? '', (string) $fallback);
            }
        }
        $q[$name] = $one;
    }

    $open = messi_cfg_int($in['open_hour'] ?? null, $d['open_hour'], 0, 23);
    $due  = messi_cfg_int($in['due_hour'] ?? null, $d['due_hour'], 1, 24);
    // Batas sebelum pembukaan berarti setiap laporan telat sejak detik pertama.
    if ($due <= $open) {
        $due = $d['due_hour'] > $open ? $d['due_hour'] : 24;
    }

    return [
        'channels'       => $channels,
        'team_name'      => messi_cfg_text($in['team_name'] ?? '', '', 60),
        'chat_webhook'   => messi_cfg_text($in['chat_webhook'] ?? '', '', 500),
        'threshold_days' => messi_cfg_int($in['threshold_days'] ?? null, $d['threshold_days'], 1, 90),
        'open_hour'      => $open,
        'due_hour'       => $due,
        'max_plans'      => messi_cfg_int($in['max_plans'] ?? null, $d['max_plans'], 1, 20),
        'declaration'    => messi_cfg_text($in['declaration'] ?? '', $d['declaration'], 600),
        'questions'      => $q,
    ];
}

/**
 * Setelan yang boleh dilihat browser.
 *
 * Webhook adalah alamat rahasia: siapa pun yang memegangnya bisa menulis ke space tim
 * itu. Halaman tidak pernah membutuhkannya — yang mengirim pesan adalah cron di server —
 * jadi dibuang di sini, satu tempat, bukan diingat satu per satu di tiap endpoint.
 */
function messi_config_public(array $cfg): array
{
    $cfg['chat_webhook'] = $cfg['chat_webhook'] === '' ? '' : 'tersimpan';
    return $cfg;
}

/** Kunci channel, urutannya seperti yang dicetak laporan. */
function messi_channel_keys(array $cfg): array
{
    return array_column($cfg['channels'], 'key');
}

/** Channel sebagai kunci => label, bentuk yang dipakai laporan. */
function messi_channel_defs(array $cfg): array
{
    return array_column($cfg['channels'], 'label', 'key');
}

/**
 * Konfigurasi untuk membaca sebuah laporan — miliknya sendiri kalau ada.
 *
 * Laporan menyimpan channel dan ambang yang berlaku saat dikirim. Tanpa itu, mengganti
 * batas dari 3 hari ke 1 hari akan mengubah arti laporan bulan lalu, dan menghapus satu
 * channel akan menghilangkan angkanya dari laporan yang sudah jadi. Riwayat bukan milik
 * halaman setelan untuk diubah.
 */
function messi_doc_config(array $doc, array $cfg): array
{
    $snap = $doc['cfg'] ?? null;
    if (!is_array($snap)) {
        return $cfg;
    }
    $out = $cfg;
    if (is_array($snap['channels'] ?? null) && $snap['channels']) {
        $kept = [];
        foreach ($snap['channels'] as $c) {
            if (is_array($c) && isset($c['key'])) {
                $kept[] = ['key' => (string) $c['key'],
                           'label' => messi_cfg_text($c['label'] ?? '', (string) $c['key'], 24),
                           'full' => messi_cfg_text($c['full'] ?? '', (string) $c['key'], 60)];
            }
        }
        if ($kept) {
            $out['channels'] = $kept;
        }
    }
    if (isset($snap['threshold_days'])) {
        $out['threshold_days'] = messi_cfg_int($snap['threshold_days'], $cfg['threshold_days'], 1, 90);
    }
    return $out;
}

/** Yang ikut disimpan di dalam laporan, supaya nanti tetap terbaca seperti saat dikirim. */
function messi_config_snapshot(array $cfg): array
{
    return ['channels' => $cfg['channels'], 'threshold_days' => $cfg['threshold_days']];
}

/**
 * Apakah ini benar-benar tanggal berbentuk YYYY-MM-DD, dan tanggal yang memang ada.
 *
 * Dibutuhkan karena dua hal. DateTimeImmutable melempar untuk teks yang tidak dia kenali,
 * dan tanggal selalu datang dari formulir. Dan "2026-02-31" tidak dia tolak — dia
 * menerimanya sebagai 3 Maret, yang berarti seseorang mengetik tanggal mustahil dan
 * sistemnya diam-diam memilih tanggal lain untuknya.
 */
function messi_is_day(string $day): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m)) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/** Berapa hari dari $from sampai $to, dua-duanya ikut dihitung. */
function messi_day_span(string $from, string $to): int
{
    $a = new DateTimeImmutable($from . ' 00:00:00', new DateTimeZone('UTC'));
    $b = new DateTimeImmutable($to . ' 00:00:00', new DateTimeZone('UTC'));
    return (int) $a->diff($b)->days + 1;
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

/** The report's traffic light, derived from the figures instead of typed. The boundary is
 *  whatever the squad set it to, so the label has to say the same number the grid asked. */
function messi_lamp(array $t, int $days = 3): array
{
    if ($t['gt3'] > 0) {
        return ['level' => 'red', 'label' => 'Ada yang gantung lebih dari ' . $days . ' hari'];
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

function messi_submit_status(int $hourNow, int $dueHour = MESSI_DUE_HOUR): string
{
    return $hourNow >= $dueHour ? 'late' : 'submitted';
}

/** A promise met after its date was not met. A rate that counts late as kept measures
 *  nothing at all. */
/**
 * Hari kerja pertama pada atau sesudah tanggal ini.
 *
 * Janji yang jatuh di hari libur baru bisa dikerjakan hari kerja berikutnya, jadi hari
 * kerja itulah tenggat sebenarnya. Tanpa ini, janji hari Sabtu ditandai tidak ditepati
 * Minggu pagi — orangnya tidak pernah punya satu pun hari kerja untuk menyelesaikannya,
 * dan tetap tercatat ingkar.
 */
function messi_workday_on_or_after(string $day): string
{
    for ($i = 0; $i < 7; $i++) {
        $d = messi_add_days($day, $i);
        if (messi_is_workday($d)) {
            return $d;
        }
    }
    return $day;            // tidak ada hari kerja sama sekali: setelan yang mustahil
}

function messi_commitment_outcome(string $dueDate, string $today): string
{
    return $today <= messi_workday_on_or_after($dueDate) ? 'kept' : 'broken';
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

/**
 * Rencana sebuah laporan, selalu sebagai daftar.
 *
 * Laporan lama menyimpan satu `plan` + `due`; yang baru menyimpan `plans`. Dibaca di satu
 * tempat saja supaya laporan dua minggu pertama tetap terbaca utuh tanpa diubah apa pun
 * di database.
 */
function messi_plans(array $a, int $max = MESSI_MAX_PLANS): array
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
        if (count($out) >= $max) {
            break;
        }
    }
    return $out;
}

/* ----------------------------------------------------------------- validation */

/** Consistency first: the declaration attests that the figures above are true, so asking
 *  for it before they add up gets the order backwards. */
function messi_validate(array $a, array $cfg): ?string
{
    $q = $cfg['questions'];
    $grid = $a['grid'] ?? [];
    $typed = false;
    foreach (messi_channel_keys($cfg) as $ch) {
        foreach (['open', 'gt3', 'lt3', 'reply'] as $col) {
            if (isset($grid[$ch][$col]) && $grid[$ch][$col] !== '') {
                $typed = true;
            }
        }
    }
    if (!$typed) {
        return 'Isi dulu angkanya. Kalau kosong semua, tulis 0.';
    }
    $t = messi_totals($grid, messi_channel_keys($cfg));
    if ($t['hanging'] > $t['open']) {
        return 'Yang gantung lebih banyak daripada yang masih aktif. Cek lagi angkanya.';
    }
    if ($t['hanging'] > 0) {
        if (trim((string) ($a['detail'] ?? '')) === '') {
            return $q['detail']['error'];
        }
        $plans = messi_plans($a, $cfg['max_plans']);
        if (!$plans) {
            return $q['plan']['error'];
        }
        foreach ($plans as $p) {
            if ($p['action'] === '') {
                return 'Ada tanggal tanpa rencana. Tulis rencananya, atau hapus barisnya.';
            }
            if ($p['due'] === '') {
                return $q['due']['error'];
            }
        }
    }
    if (empty($a['declared'])) {
        return 'Centang pernyataannya dulu.';
    }
    return null;
}

/* --------------------------------------------------------------------- report */

/** The format the squad already knows, generated instead of typed. Kept byte-identical to
 *  the browser's version so a report looks the same whichever built it. */
function messi_build_report(array $doc, string $reporter, array $cfg): string
{
    // Laporan lama dibaca dengan channel dan ambang yang berlaku saat dikirim.
    $cfg = messi_doc_config($doc, $cfg);
    $channelDefs = messi_channel_defs($cfg);
    $days = $cfg['threshold_days'];
    $q = $cfg['questions'];
    $grid = $doc['grid'] ?? [];
    $t = messi_totals($grid, array_keys($channelDefs));
    $lamp = messi_lamp($t, $days);
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
    foreach (messi_plans($doc, $cfg['max_plans']) as $p) {
        $rows[] = $p['action'] . ($p['due'] !== '' ? ' (target ' . messi_fmt_day($p['due']) . ')' : '');
    }
    // $line() memangkas spasi di ujung, jadi daftar berbutir disusun di luar helper itu —
    // kalau tidak, butir pertamanya naik ke baris judulnya.
    $planLine = count($rows) > 1
        ? 'g. Rencana:' . "\n- " . implode("\n- ", $rows)
        : null;
    $plan = $rows[0] ?? '';

    // Hurufnya berjalan terus walau satu pertanyaan dimatikan, supaya "poin h" di laporan
    // seseorang tidak menunjuk hal lain daripada "poin h" di laporan rekannya.
    $lines = [
        'MESSI Report',
        'Date: ' . messi_fmt_day($doc['day']) . ' (' . $doc['day'] . ')',
        ($cfg['team_name'] === '' ? '' : 'Tim: ' . $cfg['team_name'] . '   ') . 'Report by: ' . $reporter,
        '',
        $tag . ' ' . $lamp['label'],
        '',
        'a. Channel aktif/open: ' . implode(', ', $open),
        'b. Gantung >' . $days . ' hari: ' . $t['gt3'],
        'c. Gantung <' . $days . ' hari: ' . $t['lt3'],
        'd. Tidak gantung: ' . $t['clear'],
        'e. Sudah dibalas hari ini: ' . $t['reply'],
        $line('f. Yang masih gantung', $doc['detail'] ?? ''),
        $planLine ?? $line('g. Rencana', $plan),
    ];
    if (!empty($q['escalation']['show'])) {
        $lines[] = $line('h. Eskalasi', $doc['escalation'] ?? '');
    }
    if (!empty($q['prista']['show'])) {
        $lines[] = $line('i. Calon project baru', $doc['prista'] ?? '');
    }
    $lines[] = '';
    $lines[] = 'Deklarasi: ' . $cfg['declaration'];

    return implode("\n", $lines);
}
