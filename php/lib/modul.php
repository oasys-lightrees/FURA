<?php
/**
 * Modul sebagai data, bukan sebagai kode.
 *
 * Sampai sekarang MESSI adalah satu-satunya modul, dan bentuknya tertulis di dalam
 * program: kolom angkanya, pertanyaan lanjutannya, barisan rencananya. Menambah modul
 * kedua berarti menulis program kedua — yang artinya perusahaan yang memakai ini tidak
 * bisa menambah apa pun sendiri, dan tiap permintaan kecil harus lewat kami.
 *
 * Berkas ini memindahkan bentuk itu ke sebuah dokumen: daftar pertanyaan beserta
 * jenisnya. Yang menggambar layar, menyimpan jawaban, membuat laporan dan menagih janji
 * membaca dokumen itu — bukan lagi menebak dari nama modulnya.
 *
 * Kosakatanya sengaja kecil. Enam jenis pertanyaan yang semuanya benar-benar dipakai,
 * bukan dua puluh yang menunggu dipakai: tiap jenis baru adalah satu cabang baru di
 * layar, di laporan, di rekap dan di tes. MESSI sendiri bisa ditulis utuh dengan enam
 * ini — itu ukurannya: kalau modul yang sudah ada saja tidak muat, kosakatanya salah,
 * bukan modulnya.
 *
 * Semua yang tersimpan di sini ditulis admin dan dibaca pemain. Disimpan apa adanya,
 * dan yang menjaganya adalah pelolosan di tempat ia dicetak — h() di PHP, esc() di
 * halaman. Membuang tag di sini pernah dicoba dan salah: "Gantung <3 hari", judul kolom
 * MESSI sendiri, berubah jadi "Gantung " karena "<3 hari" terbaca sebagai awal tag.
 * Penyaring yang memakan teks yang sah lebih berbahaya daripada tidak ada penyaring,
 * karena yang hilang tidak kelihatan hilang.
 */

declare(strict_types=1);

require_once __DIR__ . '/engine.php';

/** Jenis pertanyaan yang dikenali. Urutannya ikut jadi urutan pilihan di layar admin. */
const MODUL_FIELD_TYPES = ['grid', 'text', 'textarea', 'number', 'choice', 'plans'];

/** Kapan sebuah pertanyaan ditanyakan. */
const MODUL_WHEN = ['selalu', 'merah'];

/** Penanda kolom angka: kolom bertanda 'merah' yang membuat lampu laporan menyala. */
const MODUL_FLAGS = ['', 'merah', 'hijau'];

const MODUL_MAX_FIELDS   = 20;
const MODUL_MAX_ROWS     = 12;      // baris pada pertanyaan angka
const MODUL_MAX_COLS     = 6;       // kolom pada pertanyaan angka
const MODUL_MAX_OPTIONS  = 12;
const MODUL_MAX_PLANROWS = 10;

/* ------------------------------------------------------------------ bantuan */

function modul_text($v, string $fallback = '', int $max = 300): string
{
    $s = is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v) ?? '') : '';
    return $s === '' ? $fallback : mb_substr($s, 0, $max);
}

/** Teks panjang, di mana baris baru memang bagian dari isinya. */
function modul_long($v, string $fallback = '', int $max = 1200): string
{
    $s = is_string($v) ? trim($v) : '';
    $s = (string) preg_replace("/\r\n?/", "\n", $s);
    $s = (string) preg_replace("/\n{3,}/", "\n\n", $s);
    return $s === '' ? $fallback : mb_substr($s, 0, $max);
}

function modul_int($v, int $fallback, int $min, int $max): int
{
    if (is_string($v)) { $v = trim($v); }
    if (!is_numeric($v)) { return $fallback; }
    return max($min, min($max, (int) $v));
}

/**
 * Kunci: huruf besar, angka, garis bawah.
 *
 * Dipakai sebagai nama kolom di dalam jawaban yang tersimpan, jadi bentuknya harus tetap
 * sama selamanya — dan harus aman dirangkai ke dalam HTML maupun JSON tanpa dikutip.
 */
function modul_key($v, string $fallback = ''): string
{
    $s = is_string($v) ? strtoupper(trim($v)) : '';
    $s = (string) preg_replace('/[^A-Z0-9_]/', '', $s);
    return $s === '' ? $fallback : substr($s, 0, 12);
}

/**
 * Kunci baris dan kolom di dalam pertanyaan angka.
 *
 * Besar-kecilnya huruf dipertahankan, berbeda dari kunci modul. Kunci ini jadi nama
 * kolom di dalam jawaban yang sudah tersimpan — menyeragamkannya jadi huruf besar hari
 * ini berarti laporan bulan lalu tidak ketemu angkanya sendiri.
 */
function modul_slot($v, string $fallback = ''): string
{
    $s = is_string($v) ? trim($v) : '';
    $s = (string) preg_replace('/[^A-Za-z0-9_]/', '', $s);
    return $s === '' ? $fallback : substr($s, 0, 12);
}

/** Nama pengenal pertanyaan di dalam dokumen jawaban: huruf kecil. */
function modul_id($v, string $fallback = ''): string
{
    $s = is_string($v) ? strtolower(trim($v)) : '';
    $s = (string) preg_replace('/[^a-z0-9_]/', '', $s);
    return $s === '' ? $fallback : substr($s, 0, 24);
}

/* ---------------------------------------------------------------- bawaannya */

/**
 * Modul kosong yang sudah bisa dipakai.
 *
 * Bukan benar-benar kosong: satu pertanyaan isian sudah ada di dalamnya. Layar yang
 * kosong tidak memberi tahu apa pun tentang apa yang bisa dibuat di situ, dan yang
 * membuka halaman ini pertama kali sedang menebak-nebak, bukan sedang hafal.
 */
function modul_default(string $key = '', string $name = ''): array
{
    return modul_normalize([
        'key'  => $key !== '' ? $key : 'MODUL',
        'name' => $name !== '' ? $name : 'Modul baru',
        'full' => '',
        'what' => 'Belum ada keterangan.',
        'intro' => '',
        'open_hour' => MESSI_OPEN_HOUR,
        'due_hour'  => MESSI_DUE_HOUR,
        'workdays'  => MESSI_WORKDAYS,
        'declaration' => '',
        'fields' => [
            ['id' => 'catatan', 'type' => 'textarea', 'label' => 'Apa yang terjadi hari ini?',
             'required' => true, 'error' => 'Tulis dulu isinya.'],
        ],
    ]);
}

/**
 * MESSI, ditulis sebagai dokumen.
 *
 * Ada dua gunanya. Yang pertama: membuktikan kosakata di berkas ini cukup — modul yang
 * sudah dipakai sehari-hari harus muat tanpa satu pun pengecualian, kalau tidak yang
 * salah kosakatanya. Yang kedua: jadi titik awal yang bisa disalin perusahaan lain,
 * karena menyalin sesuatu yang sudah jalan jauh lebih mudah daripada memulai dari kosong.
 */
function modul_messi(array $cfg): array
{
    $rows = [];
    foreach ($cfg['channels'] as $c) {
        $rows[] = ['key' => $c['key'], 'label' => $c['label'], 'full' => $c['full']];
    }
    $n = (int) $cfg['threshold_days'];
    $q = $cfg['questions'];

    $fields = [
        ['id' => 'grid', 'type' => 'grid',
         'label' => $q['grid']['label'], 'hint' => $q['grid']['hint'],
         'rows' => $rows,
         'cols' => [
             ['key' => 'open',  'label' => 'Masih aktif',        'flag' => ''],
             ['key' => 'gt3',   'label' => 'Gantung >' . $n . ' hari', 'flag' => 'merah'],
             ['key' => 'lt3',   'label' => 'Gantung <' . $n . ' hari', 'flag' => ''],
             ['key' => 'reply', 'label' => 'Sudah dibalas',      'flag' => 'hijau'],
         ]],
        ['id' => 'detail', 'type' => 'textarea', 'when' => 'merah', 'required' => true,
         'label' => $q['detail']['label'], 'placeholder' => $q['detail']['placeholder'],
         'error' => $q['detail']['error']],
        ['id' => 'plans', 'type' => 'plans', 'when' => 'merah', 'required' => true,
         'label' => $q['plan']['label'], 'placeholder' => $q['plan']['placeholder'],
         'error' => $q['plan']['error'],
         'due_label' => $q['due']['label'], 'due_error' => $q['due']['error'],
         'max' => (int) $cfg['max_plans']],
    ];
    if (!empty($q['escalation']['show'])) {
        $fields[] = ['id' => 'escalation', 'type' => 'textarea', 'announce' => true,
                     'label' => $q['escalation']['label'], 'hint' => $q['escalation']['hint'],
                     'placeholder' => $q['escalation']['placeholder']];
    }
    if (!empty($q['prista']['show'])) {
        $fields[] = ['id' => 'prista', 'type' => 'textarea',
                     'label' => $q['prista']['label'],
                     'placeholder' => $q['prista']['placeholder']];
    }

    return modul_normalize([
        'key'  => 'MESSI',
        'name' => 'MESSI',
        'full' => 'Messenger Screening',
        'what' => 'Sapuan harian semua channel chat: berapa yang aktif, berapa yang '
                . 'gantung, dan apa rencana untuk yang gantung.',
        'intro' => 'Satu pertanyaan yang diulang tiap hari kerja: dari semua chat yang '
                 . 'masuk, adakah yang dibiarkan menggantung? Satu menit mengisi, supaya '
                 . 'tidak ada yang hilang tanpa ada yang tahu.',
        'open_hour'   => (int) $cfg['open_hour'],
        'due_hour'    => (int) $cfg['due_hour'],
        'workdays'    => MESSI_WORKDAYS,
        'declaration' => $cfg['declaration'],
        'fields'      => $fields,
    ]);
}

/* --------------------------------------------------------------- merapikan */

/**
 * Merapikan satu pertanyaan.
 *
 * Yang tidak dikenali dibuang, bukan dibiarkan lewat: dokumen ini dibaca lagi berbulan
 * kemudian oleh kode yang mungkin sudah berbeda, dan satu kunci asing yang menumpang
 * akan muncul sebagai layar kosong tanpa satu pun petunjuk kenapa.
 */
function modul_field($in, int $urutan): ?array
{
    if (!is_array($in)) {
        return null;
    }
    $type = is_string($in['type'] ?? null) && in_array($in['type'], MODUL_FIELD_TYPES, true)
        ? $in['type'] : 'text';
    $id = modul_id($in['id'] ?? '', 'f' . $urutan);

    $f = [
        'id'    => $id,
        'type'  => $type,
        'label' => modul_text($in['label'] ?? '', 'Pertanyaan ' . $urutan),
        'hint'  => modul_text($in['hint'] ?? ''),
        'when'  => in_array($when = (is_string($in['when'] ?? null) ? $in['when'] : ''),
                            MODUL_WHEN, true) ? $when : 'selalu',
        'required' => !empty($in['required']),
    ];

    if ($type !== 'grid') {
        $f['placeholder'] = modul_text($in['placeholder'] ?? '');
        $f['error'] = modul_text($in['error'] ?? '', 'Bagian ini belum diisi.');
    }

    if ($type === 'grid') {
        // Pertanyaan angka tidak pernah "wajib": kotak yang dikosongkan berarti nol, dan
        // menyuruh orang mengetik nol dua belas kali adalah cara tercepat membuat orang
        // berhenti mengisi.
        $f['required'] = false;
        $f['when'] = 'selalu';          // lampunya sendiri lahir dari sini
        $f['rows'] = [];
        foreach (is_array($in['rows'] ?? null) ? $in['rows'] : [] as $r) {
            $key = modul_slot(is_array($r) ? ($r['key'] ?? '') : '');
            if ($key === '' || isset($f['rows'][$key])) {
                continue;               // tanpa kunci tidak ada tempat menyimpan angkanya
            }
            $f['rows'][$key] = [
                'key'   => $key,
                'label' => modul_text($r['label'] ?? '', $key, 24),
                'full'  => modul_text($r['full'] ?? '', '', 60),
            ];
        }
        $f['rows'] = array_slice(array_values($f['rows']), 0, MODUL_MAX_ROWS);

        $f['cols'] = [];
        foreach (is_array($in['cols'] ?? null) ? $in['cols'] : [] as $c) {
            $key = modul_slot(is_array($c) ? ($c['key'] ?? '') : '');
            if ($key === '' || isset($f['cols'][$key])) {
                continue;
            }
            $flag = is_string($c['flag'] ?? null) ? $c['flag'] : '';
            $f['cols'][$key] = [
                'key'   => $key,
                'label' => modul_text($c['label'] ?? '', $key, 40),
                'flag'  => in_array($flag, MODUL_FLAGS, true) ? $flag : '',
            ];
        }
        $f['cols'] = array_slice(array_values($f['cols']), 0, MODUL_MAX_COLS);

    } elseif ($type === 'number') {
        $f['min'] = modul_int($in['min'] ?? null, 0, 0, 999999);
        $f['max'] = modul_int($in['max'] ?? null, 9999, $f['min'], 999999);

    } elseif ($type === 'choice') {
        $opts = [];
        foreach (is_array($in['options'] ?? null) ? $in['options'] : [] as $o) {
            $t = modul_text($o, '', 60);
            if ($t !== '' && !in_array($t, $opts, true)) {
                $opts[] = $t;
            }
        }
        $f['options'] = array_slice($opts, 0, MODUL_MAX_OPTIONS);

    } elseif ($type === 'plans') {
        $f['due_label'] = modul_text($in['due_label'] ?? '', 'Kapan beres?');
        $f['due_error'] = modul_text($in['due_error'] ?? '',
            'Pilih tanggalnya. Tanpa tanggal, tidak ada yang bisa mengingatkan.');
        $f['max'] = modul_int($in['max'] ?? null, 5, 1, MODUL_MAX_PLANROWS);
    }

    if (in_array($type, ['text', 'textarea'], true)) {
        // Diumumkan ke chat begitu diisi, tanpa menunggu jam berapa pun. Hanya masuk akal
        // untuk isian bebas: angka yang diumumkan sendirian tidak berarti apa-apa.
        $f['announce'] = !empty($in['announce']);
    }

    return $f;
}

/** Pertanyaan angka tidak punya kunci — tidak ada yang bisa disimpan, jadi tidak ada gunanya. */
function modul_field_usable(array $f): bool
{
    if ($f['type'] === 'grid') {
        return $f['rows'] !== [] && $f['cols'] !== [];
    }
    if ($f['type'] === 'choice') {
        return $f['options'] !== [];
    }
    return true;
}

/**
 * Merapikan seluruh modul.
 *
 * Dipanggil saat menyimpan *dan* saat membaca. Baris di database selalu bisa lebih tua
 * daripada kode yang membacanya — modul yang disimpan bulan lalu tidak boleh berarti
 * layar kosong hari ini hanya karena ada kunci baru yang belum ada waktu itu.
 */
function modul_normalize($in): array
{
    $in = is_array($in) ? $in : [];

    $fields = [];
    $pakai = [];
    $urutan = 0;
    foreach (is_array($in['fields'] ?? null) ? $in['fields'] : [] as $raw) {
        if (count($fields) >= MODUL_MAX_FIELDS) {
            break;
        }
        $urutan++;
        $f = modul_field($raw, $urutan);
        if ($f === null || !modul_field_usable($f)) {
            continue;
        }
        // Dua pertanyaan dengan id sama berarti yang kedua menimpa jawaban yang pertama.
        if (isset($pakai[$f['id']])) {
            $f['id'] = $f['id'] . $urutan;
        }
        $pakai[$f['id']] = true;
        $fields[] = $f;
    }

    $hari = [];
    foreach (is_array($in['workdays'] ?? null) ? $in['workdays'] : MESSI_WORKDAYS as $d) {
        $d = (int) $d;
        if ($d >= 1 && $d <= 7 && !in_array($d, $hari, true)) {
            $hari[] = $d;
        }
    }
    sort($hari);
    if (!$hari) {
        $hari = MESSI_WORKDAYS;          // modul tanpa satu pun hari tidak akan pernah terbuka
    }

    $open = modul_int($in['open_hour'] ?? null, MESSI_OPEN_HOUR, 0, 23);
    $due  = modul_int($in['due_hour'] ?? null, MESSI_DUE_HOUR, 1, 24);
    if ($due <= $open) {
        // Jam tutup sebelum jam buka berarti hari yang tidak pernah terbuka. Yang paling
        // mungkin dimaksud adalah "sampai tengah malam".
        $due = 24;
    }

    $key = modul_key($in['key'] ?? '', 'MODUL');
    return [
        'key'   => $key,
        'name'  => modul_text($in['name'] ?? '', $key, 40),
        'full'  => modul_text($in['full'] ?? '', '', 60),
        'what'  => modul_text($in['what'] ?? '', 'Belum ada keterangan.', 300),
        'intro' => modul_long($in['intro'] ?? '', '', 1200),
        'open_hour'   => $open,
        'due_hour'    => $due,
        'workdays'    => $hari,
        'declaration' => modul_long($in['declaration'] ?? '', '', 600),
        'fields'      => $fields,
    ];
}

/* ----------------------------------------------------------------- membaca */

/** Pertanyaan angka yang pertama, atau null. Lampu dan hitungannya lahir dari situ. */
function modul_grid(array $modul): ?array
{
    foreach ($modul['fields'] as $f) {
        if ($f['type'] === 'grid') {
            return $f;
        }
    }
    return null;
}

/**
 * Lampu laporan, dari kolom yang ditandai.
 *
 * Kolom bertanda 'merah' adalah yang menyalakan lampunya — itu satu-satunya aturan, dan
 * sengaja satu-satunya: ambang yang bisa diatur bebas adalah ambang yang tidak ada yang
 * berani ubah, dan tidak ada yang bisa membacanya sekilas di layar.
 *
 * @return array{0: string, 1: int}  'merah'|'hijau'|'abu', lalu berapa yang menyalakannya
 */
function modul_lamp(array $modul, array $answers): array
{
    $grid = modul_grid($modul);
    if (!$grid) {
        return ['abu', 0];
    }
    $isi = is_array($answers[$grid['id']] ?? null) ? $answers[$grid['id']] : [];
    $merah = 0;
    foreach ($grid['cols'] as $c) {
        if ($c['flag'] !== 'merah') {
            continue;
        }
        foreach ($grid['rows'] as $r) {
            $merah += max(0, (int) ($isi[$r['key']][$c['key']] ?? 0));
        }
    }
    return [$merah > 0 ? 'merah' : 'hijau', $merah];
}

/** Pertanyaan yang benar-benar ditanyakan untuk jawaban ini. */
function modul_fields_for(array $modul, array $answers): array
{
    [$lamp] = modul_lamp($modul, $answers);
    $out = [];
    foreach ($modul['fields'] as $f) {
        if ($f['when'] === 'merah' && $lamp !== 'merah') {
            continue;
        }
        $out[] = $f;
    }
    return $out;
}

/** Baris rencana yang jadi janji. Satu modul paling banyak punya satu pertanyaan ini. */
function modul_plans_field(array $modul): ?array
{
    foreach ($modul['fields'] as $f) {
        if ($f['type'] === 'plans') {
            return $f;
        }
    }
    return null;
}

/** Isian yang diumumkan ke chat begitu diisi. */
function modul_announce_ids(array $modul): array
{
    $out = [];
    foreach ($modul['fields'] as $f) {
        if (!empty($f['announce'])) {
            $out[] = $f['id'];
        }
    }
    return $out;
}

/**
 * Yang dikirim ke browser.
 *
 * Sama persis dengan yang tersimpan: sebuah modul memang tidak punya rahasia — yang
 * rahasia adalah alamat space chat-nya, dan itu tidak pernah ikut di sini.
 */
function modul_public(array $modul): array
{
    return $modul;
}

/* --------------------------------------------------------------- memeriksa */

/** Baris rencana yang benar-benar berisi, dirapikan. */
function modul_plans(array $modul, array $answers): array
{
    $f = modul_plans_field($modul);
    if (!$f) {
        return [];
    }
    $out = [];
    foreach (is_array($answers[$f['id']] ?? null) ? $answers[$f['id']] : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $aksi = modul_text($row['action'] ?? '', '', 300);
        $due  = is_string($row['due'] ?? null) ? trim($row['due']) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            $due = '';
        }
        if ($aksi === '' && $due === '') {
            continue;                   // baris kosong yang memang dibiarkan kosong
        }
        $out[] = ['action' => $aksi, 'due' => $due];
        if (count($out) >= $f['max']) {
            break;
        }
    }
    return $out;
}

/**
 * Apa yang masih kurang dari jawaban ini.
 *
 * Mengembalikan satu kalimat, bukan daftar: yang mengisi sedang melihat satu layar, dan
 * daftar kesalahan membuat orang membetulkan yang paling bawah lalu bingung kenapa masih
 * ditolak. Kalimatnya pun kalimat admin sendiri — dia yang tahu kenapa isian itu penting.
 */
function modul_validate(array $modul, array $answers): ?string
{
    $grid = modul_grid($modul);
    if ($grid) {
        $isi = is_array($answers[$grid['id']] ?? null) ? $answers[$grid['id']] : [];
        foreach ($grid['rows'] as $r) {
            foreach ($grid['cols'] as $c) {
                $v = $isi[$r['key']][$c['key']] ?? 0;
                if ($v !== '' && $v !== null && (int) $v < 0) {
                    return 'Angka tidak boleh minus (' . $r['label'] . ' · ' . $c['label'] . ').';
                }
            }
        }
    }

    foreach (modul_fields_for($modul, $answers) as $f) {
        if ($f['type'] === 'grid') {
            continue;
        }
        if ($f['type'] === 'plans') {
            $rows = modul_plans($modul, $answers);
            if ($f['required'] && !$rows) {
                return $f['error'];
            }
            foreach ($rows as $r) {
                if ($r['action'] === '') {
                    return $f['error'];
                }
                if ($r['due'] === '') {
                    return $f['due_error'];
                }
            }
            continue;
        }
        if (!$f['required']) {
            continue;
        }
        $v = $answers[$f['id']] ?? '';
        if ($f['type'] === 'number') {
            if ($v === '' || $v === null || !is_numeric($v)) {
                return $f['error'];
            }
            continue;
        }
        if (!is_string($v) || trim($v) === '') {
            return $f['error'];
        }
        if ($f['type'] === 'choice' && !in_array(trim($v), $f['options'], true)) {
            return $f['error'];
        }
    }

    if ($modul['declaration'] !== '' && empty($answers['declared'])) {
        return 'Centang dulu pernyataannya.';
    }
    return null;
}

/* ------------------------------------------------------------- laporannya */

/**
 * Laporan yang dibuatkan, bukan diketik.
 *
 * Bentuknya tetap: kepala, lampu, lalu satu baris berhuruf per pertanyaan, lalu
 * pernyataan. Yang berubah antar modul cuma isinya — jadi orang yang sudah terbiasa
 * membaca laporan satu modul langsung bisa membaca modul lain.
 */
function modul_report(array $modul, array $doc, string $reporter, string $teamName = ''): string
{
    $answers = is_array($doc['answers'] ?? null) ? $doc['answers'] : [];
    $day = (string) ($doc['day'] ?? Clock::today());

    $lines = [$modul['name'] . ' Report'];
    $lines[] = 'Tanggal: ' . messi_fmt_day($day) . ' (' . $day . ')';
    $lines[] = ($teamName === '' ? '' : 'Tim: ' . $teamName . '   ') . 'Dilaporkan oleh: ' . $reporter;

    [$lamp, $berapa] = modul_lamp($modul, $answers);
    if ($lamp !== 'abu') {
        $lines[] = '';
        $lines[] = $lamp === 'merah'
            ? '[MERAH] Ada ' . $berapa . ' yang perlu ditindak'
            : '[HIJAU] Tidak ada yang perlu ditindak';
    }
    $lines[] = '';

    $huruf = 'a';
    foreach (modul_fields_for($modul, $answers) as $f) {
        $judul = $huruf . '. ' . $f['label'];
        $huruf = chr(ord($huruf) + 1);

        if ($f['type'] === 'grid') {
            $isi = is_array($answers[$f['id']] ?? null) ? $answers[$f['id']] : [];
            foreach ($f['cols'] as $c) {
                $bagian = [];
                foreach ($f['rows'] as $r) {
                    $n = max(0, (int) ($isi[$r['key']][$c['key']] ?? 0));
                    if ($n > 0) {
                        $bagian[] = $n . ' ' . $r['label'];
                    }
                }
                $lines[] = $judul . ' — ' . $c['label'] . ': '
                         . ($bagian ? implode(', ', $bagian) : '0');
                $judul = str_repeat(' ', mb_strlen($judul));
            }
            continue;
        }

        if ($f['type'] === 'plans') {
            $rows = modul_plans($modul, $answers);
            if (!$rows) {
                $lines[] = $judul . ': Belum ada';
                continue;
            }
            $lines[] = $judul . ':';
            foreach ($rows as $r) {
                $lines[] = '- ' . $r['action']
                         . ($r['due'] === '' ? '' : ' (target ' . messi_fmt_day($r['due']) . ')');
            }
            continue;
        }

        $v = $answers[$f['id']] ?? '';
        $v = is_scalar($v) ? trim((string) $v) : '';
        $lines[] = $judul . ': ' . ($v === '' ? 'Belum ada' : $v);
    }

    if ($modul['declaration'] !== '') {
        $lines[] = '';
        $lines[] = 'Pernyataan: ' . $modul['declaration'];
    }
    return implode("\n", $lines);
}
