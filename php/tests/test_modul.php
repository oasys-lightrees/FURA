<?php
/**
 * Modul sebagai data: apakah kosakatanya cukup, dan apakah yang masuk sembarangan
 * keluar rapi. Jalankan: php tests/test_modul.php
 *
 * Ujian terberatnya ada di bagian terakhir — MESSI sendiri ditulis ulang sebagai dokumen,
 * lalu diperiksa apakah dia masih berperilaku persis seperti MESSI yang ditulis tangan.
 * Kalau modul yang sudah dipakai sehari-hari saja tidak muat, yang salah kosakatanya.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/modul.php';

$passed = 0;
$failed = [];

function ok(string $what, bool $cond): void
{
    global $passed, $failed;
    if ($cond) { $passed++; return; }
    $failed[] = $what;
}

function eq(string $what, $got, $want): void
{
    global $passed, $failed;
    if ($got === $want) { $passed++; return; }
    $failed[] = $what . "\n      got:  " . var_export($got, true)
                      . "\n      want: " . var_export($want, true);
}

Clock::freeze('2026-09-30T04:00:00Z');       // Rabu 11:00 Jakarta

/* ------------------------------------------------------------- merapikan */

$kosong = modul_normalize([]);
eq('modul kosong tetap punya kunci', $kosong['key'], 'MODUL');
eq('dan jam bawaan', [$kosong['open_hour'], $kosong['due_hour']], [9, 18]);
eq('dan hari kerja bawaan', $kosong['workdays'], [1, 2, 3, 4, 5]);
eq('tanpa pertanyaan sama sekali', $kosong['fields'], []);

$m = modul_normalize([
    'key' => 'pr-ista!', 'name' => '  Prista   Status ',
    'open_hour' => '7', 'due_hour' => '3', 'workdays' => [9, 1, 1, 6],
    'fields' => [
        ['id' => 'Catatan Harian', 'type' => 'textarea', 'label' => "  Apa\n\n yang terjadi? "],
    ],
]);
eq('kunci dibersihkan jadi huruf besar dan angka', $m['key'], 'PRISTA');
eq('nama dirapikan spasinya', $m['name'], 'Prista Status');
eq('id pertanyaan jadi huruf kecil tanpa spasi', $m['fields'][0]['id'], 'catatanharian');
eq('label dirapikan jadi satu baris', $m['fields'][0]['label'], 'Apa yang terjadi?');
eq('hari di luar 1-7 dibuang dan diurutkan', $m['workdays'], [1, 6]);
// Jam tutup sebelum jam buka berarti hari yang tidak pernah terbuka.
eq('jam tutup sebelum jam buka jadi tengah malam', $m['due_hour'], 24);

// Disimpan apa adanya; yang menjaganya adalah pelolosan saat dicetak. Membuang tag di
// sini pernah dicoba dan memakan judul kolom MESSI sendiri.
$jahat = modul_normalize(['key' => 'X', 'fields' => [
    ['id' => 'a', 'type' => 'text', 'label' => '<script>alert(1)</script>Halo'],
    ['id' => 'b', 'type' => 'text', 'label' => 'Gantung <3 hari'],
]]);
eq('teks disimpan apa adanya', $jahat['fields'][0]['label'], '<script>alert(1)</script>Halo');
eq('dan teks yang kebetulan berisi kurung sudut tidak dimakan',
   $jahat['fields'][1]['label'], 'Gantung <3 hari');

$ganda = modul_normalize(['fields' => [
    ['id' => 'x', 'type' => 'text', 'label' => 'Satu'],
    ['id' => 'x', 'type' => 'text', 'label' => 'Dua'],
]]);
// Dua pertanyaan dengan id sama berarti yang kedua menimpa jawaban yang pertama.
eq('id yang kembar dipisahkan', [$ganda['fields'][0]['id'], $ganda['fields'][1]['id']],
   ['x', 'x2']);

$tanpaKunci = modul_normalize(['fields' => [
    ['id' => 'g', 'type' => 'grid', 'label' => 'Angka', 'rows' => [], 'cols' => []],
    ['id' => 'c', 'type' => 'choice', 'label' => 'Pilih', 'options' => []],
    ['id' => 't', 'type' => 'text', 'label' => 'Teks'],
]]);
// Pertanyaan angka tanpa baris tidak punya tempat menyimpan apa pun.
eq('pertanyaan yang tidak bisa menyimpan apa-apa dibuang',
   array_column($tanpaKunci['fields'], 'id'), ['t']);

$asing = modul_normalize(['fields' => [
    ['id' => 'a', 'type' => 'rahasia', 'label' => 'Apa ini', 'kunci_asing' => 'ikut'],
]]);
eq('jenis yang tidak dikenal jadi teks biasa', $asing['fields'][0]['type'], 'text');
ok('kunci asing tidak ikut tersimpan', !isset($asing['fields'][0]['kunci_asing']));

/* ------------------------------------------------------- lampu dan syarat */

$spec = modul_normalize(['key' => 'UJI', 'fields' => [
    ['id' => 'grid', 'type' => 'grid', 'label' => 'Berapa?',
     'rows' => [['key' => 'WAG', 'label' => 'WAG'], ['key' => 'TGG', 'label' => 'TGG']],
     'cols' => [['key' => 'open', 'label' => 'Aktif'],
                ['key' => 'gt', 'label' => 'Gantung', 'flag' => 'merah']]],
    ['id' => 'detail', 'type' => 'textarea', 'label' => 'Yang mana?', 'when' => 'merah',
     'required' => true, 'error' => 'Tulis dulu yang mana.'],
    ['id' => 'catatan', 'type' => 'text', 'label' => 'Catatan'],
]]);

eq('tanpa yang bertanda, lampunya hijau',
   modul_lamp($spec, ['grid' => ['WAG' => ['open' => 5]]]), ['hijau', 0]);
eq('satu yang bertanda membuatnya merah',
   modul_lamp($spec, ['grid' => ['WAG' => ['open' => 5, 'gt' => 2]]]), ['merah', 2]);
eq('yang bertanda dijumlah lintas baris',
   modul_lamp($spec, ['grid' => ['WAG' => ['gt' => 2], 'TGG' => ['gt' => 3]]])[1], 5);
eq('modul tanpa pertanyaan angka tidak punya lampu',
   modul_lamp(modul_normalize(['fields' => [['id' => 'a', 'type' => 'text', 'label' => 'A']]]), []),
   ['abu', 0]);

eq('saat hijau, pertanyaan bersyarat tidak ditanyakan',
   array_column(modul_fields_for($spec, ['grid' => ['WAG' => ['open' => 1]]]), 'id'),
   ['grid', 'catatan']);
eq('saat merah, barulah ditanyakan',
   array_column(modul_fields_for($spec, ['grid' => ['WAG' => ['gt' => 1]]]), 'id'),
   ['grid', 'detail', 'catatan']);

/* -------------------------------------------------------------- memeriksa */

eq('hijau tanpa isian lain tetap lolos',
   modul_validate($spec, ['grid' => ['WAG' => ['open' => 1]]]), null);
eq('merah tanpa penjelasan ditolak dengan kalimat adminnya',
   modul_validate($spec, ['grid' => ['WAG' => ['gt' => 1]]]), 'Tulis dulu yang mana.');
eq('merah dengan penjelasan lolos',
   modul_validate($spec, ['grid' => ['WAG' => ['gt' => 1]], 'detail' => 'WAG Klien A']), null);
ok('angka minus ditolak',
   str_contains((string) modul_validate($spec, ['grid' => ['WAG' => ['gt' => -1]]]), 'minus'));

$pilih = modul_normalize(['fields' => [
    ['id' => 'p', 'type' => 'choice', 'label' => 'Status', 'required' => true,
     'options' => ['Jalan', 'Berhenti'], 'error' => 'Pilih statusnya.'],
]]);
eq('pilihan di luar daftar ditolak', modul_validate($pilih, ['p' => 'Entah']), 'Pilih statusnya.');
eq('pilihan yang ada lolos', modul_validate($pilih, ['p' => 'Jalan']), null);

$wajibCentang = modul_normalize(['declaration' => 'Saya menyatakan benar.',
    'fields' => [['id' => 'a', 'type' => 'text', 'label' => 'A']]]);
ok('pernyataan yang belum dicentang ditolak',
   modul_validate($wajibCentang, []) !== null);
eq('setelah dicentang lolos', modul_validate($wajibCentang, ['declared' => true]), null);

/* ----------------------------------------------------------------- janji */

$rencana = modul_normalize(['fields' => [
    ['id' => 'plans', 'type' => 'plans', 'label' => 'Mau diapakan?', 'required' => true,
     'error' => 'Tulis rencananya.', 'due_error' => 'Pilih tanggalnya.', 'max' => 3],
]]);
eq('baris kosong tidak dihitung sebagai rencana',
   modul_plans($rencana, ['plans' => [['action' => '', 'due' => '']]]), []);
eq('baris berisi dirapikan',
   modul_plans($rencana, ['plans' => [['action' => ' Telepon  Klien ', 'due' => '2026-10-01']]]),
   [['action' => 'Telepon Klien', 'due' => '2026-10-01']]);
eq('tanggal yang bentuknya rusak dibuang',
   modul_plans($rencana, ['plans' => [['action' => 'A', 'due' => 'besok']]])[0]['due'], '');
eq('rencana tanpa tanggal ditolak',
   modul_validate($rencana, ['plans' => [['action' => 'A', 'due' => '']]]), 'Pilih tanggalnya.');
eq('lebih dari batasnya dipotong',
   count(modul_plans($rencana, ['plans' => array_fill(0, 9,
       ['action' => 'A', 'due' => '2026-10-01'])])), 3);

/* --------------------------------------------------------------- laporan */

$laporan = modul_report($spec,
    ['day' => '2026-09-30', 'answers' => [
        'grid' => ['WAG' => ['open' => 9, 'gt' => 2], 'TGG' => ['open' => 3]],
        'detail' => 'WAG Klien C', 'catatan' => '']],
    'Nicho', 'OASYS');
ok('laporan menyebut nama modulnya', str_contains($laporan, 'UJI Report'));
ok('dan tanggalnya', str_contains($laporan, '2026-09-30'));
ok('dan timnya', str_contains($laporan, 'Tim: OASYS'));
ok('dan pelapornya', str_contains($laporan, 'Dilaporkan oleh: Nicho'));
ok('lampunya ikut tercetak', str_contains($laporan, '[MERAH] Ada 2'));
ok('kolom angkanya satu baris per kolom', str_contains($laporan, 'Aktif: 9 WAG, 3 TGG'));
ok('kolom yang kosong tetap disebut nol', str_contains($laporan, 'Gantung: 2 WAG'));
ok('isian yang dikosongkan ditulis "Belum ada"', str_contains($laporan, 'Belum ada'));
// Pertanyaan bersyarat yang tidak ditanyakan tidak boleh muncul di laporan.
$hijau = modul_report($spec, ['day' => '2026-09-30',
    'answers' => ['grid' => ['WAG' => ['open' => 1]]]], 'Nicho');
ok('saat hijau, pertanyaan bersyarat tidak ikut dicetak', !str_contains($hijau, 'Yang mana?'));
ok('dan lampunya hijau', str_contains($hijau, '[HIJAU]'));

/* ------------------------------------------------- MESSI sebagai dokumen */

// Ujian sesungguhnya: modul yang sudah dipakai sehari-hari harus muat tanpa pengecualian.
$cfg = messi_config_default();
$messi = modul_messi($cfg);
eq('MESSI muat sebagai dokumen', $messi['key'], 'MESSI');
eq('dengan lima pertanyaan', count($messi['fields']), 5);
eq('yang pertama angkanya', $messi['fields'][0]['type'], 'grid');
eq('tiga channel bawaan jadi tiga baris', count($messi['fields'][0]['rows']), 3);
eq('empat kolom angkanya', array_column($messi['fields'][0]['cols'], 'key'),
   ['open', 'gt3', 'lt3', 'reply']);
eq('kolom gantung yang menyalakan lampunya', $messi['fields'][0]['cols'][1]['flag'], 'merah');
eq('ambangnya ikut tercetak di judul kolom', $messi['fields'][0]['cols'][1]['label'],
   'Gantung >3 hari');
eq('penjelasan dan rencana cuma muncul kalau merah',
   [$messi['fields'][1]['when'], $messi['fields'][2]['when']], ['merah', 'merah']);
eq('minta bantuan diumumkan ke chat', $messi['fields'][3]['announce'], true);
eq('batas rencananya ikut', $messi['fields'][2]['max'], 5);
eq('pernyataannya ikut', $messi['declaration'], $cfg['declaration']);

// Dan perilakunya harus sama dengan MESSI yang ditulis tangan.
$jawaban = ['grid' => ['WAG' => ['open' => 9, 'gt3' => 2, 'lt3' => 1], 'TGG' => ['open' => 3]]];
eq('gantung >3 hari menyalakan merah, persis seperti aslinya',
   modul_lamp($messi, $jawaban)[0], 'merah');
eq('tanpa gantung, hijau',
   modul_lamp($messi, ['grid' => ['WAG' => ['open' => 9, 'lt3' => 2]]])[0], 'hijau');
ok('merah tanpa penjelasan ditolak dengan kalimat MESSI',
   modul_validate($messi, $jawaban) === $cfg['questions']['detail']['error']);

// Ambang yang diubah admin ikut terbawa ke judul kolomnya.
$satuHari = messi_config_normalize(['threshold_days' => 1] + messi_config_default());
eq('ambang 1 hari ikut ke judul kolom',
   modul_messi($satuHari)['fields'][0]['cols'][1]['label'], 'Gantung >1 hari');

// Pertanyaan yang dimatikan admin memang hilang dari modulnya.
$tanpaPrista = messi_config_normalize(array_replace_recursive(messi_config_default(),
    ['questions' => ['prista' => ['show' => false]]]));
eq('pertanyaan yang dimatikan tidak ikut jadi pertanyaan modul',
   count(modul_messi($tanpaPrista)['fields']), 4);

/* -------------------------------------------- dibaca ulang tetap sama */

// Dokumen di database selalu bisa lebih tua daripada kode yang membacanya, jadi
// merapikan hasil rapi harus menghasilkan yang sama persis — kalau tidak, membuka
// halaman setelan dua kali diam-diam mengubah modulnya.
eq('merapikan dua kali tidak mengubah apa pun', modul_normalize($messi), $messi);
eq('begitu juga lewat JSON, seperti yang benar-benar terjadi',
   modul_normalize(json_decode((string) json_encode($messi), true)), $messi);

/* --------------------------------------------------------------- hasilnya */

echo $passed . ' checks passed, ' . count($failed) . " failed\n";
foreach ($failed as $f) {
    echo "  FAIL  " . $f . "\n";
}
exit($failed ? 1 : 0);
