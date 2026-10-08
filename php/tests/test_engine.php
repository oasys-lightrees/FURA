<?php
/**
 * Checks php/lib/engine.php against the cases tests/test_core.py pins down, plus the
 * MESSI-specific rules the browser version enforces. Run: php tests/test_engine.php
 *
 * No framework on purpose — cPanel shared hosting has no composer, and a test that needs
 * installing is a test nobody runs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/engine.php';

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

/* --------------------------------------------------------------- the clock */

Clock::freeze('2026-09-30T01:30:00Z');       // 08:30 Jakarta
eq('Jakarta day is the calendar day, not UTC', Clock::today(), '2026-09-30');
eq('Jakarta hour, not UTC hour', Clock::hour(), 8);

// The case that makes the timezone worth carrying: late evening in Jakarta is still the
// same working day, while UTC has not caught up.
Clock::freeze('2026-09-30T13:00:00Z');       // 20:00 Jakarta
eq('an evening report belongs to the day just worked', Clock::today(), '2026-09-30');
eq('20:00 is past the deadline', messi_submit_status(Clock::hour()), 'late');

// And the mirror case: early morning in Jakarta is still yesterday in UTC.
Clock::freeze('2026-09-29T22:00:00Z');       // 05:00 Jakarta on the 30th
eq('early morning is already the next Jakarta day', Clock::today(), '2026-09-30');
Clock::unfreeze();

/* ------------------------------------------------------------ calendar days */

ok('Monday is a workday', messi_is_workday('2026-09-28'));
ok('Friday is a workday', messi_is_workday('2026-10-02'));
ok('Saturday is not', !messi_is_workday('2026-10-03'));
ok('Sunday is not', !messi_is_workday('2026-10-04'));

eq('adding days crosses a month end', messi_add_days('2026-09-30', 1), '2026-10-01');
eq('subtracting days crosses it back', messi_add_days('2026-10-01', -1), '2026-09-30');

eq('past workdays skip the weekend',
   messi_past_workdays('2026-10-05', 3, '2026-01-01'),
   ['2026-10-05', '2026-10-02', '2026-10-01']);

eq('past workdays never run before the floor',
   messi_past_workdays('2026-10-05', 5, '2026-10-02'),
   ['2026-10-05', '2026-10-02']);

eq('Indonesian short dates', messi_fmt_day('2026-09-30'), 'Rab 30 Sep');
eq('month names are Indonesian', messi_fmt_day('2026-08-03'), 'Sen 3 Agu');

/* ------------------------------------------------------------- the figures */

$CH = ['WAG', 'TGG', 'GCG'];
$CFG = messi_config_default();   // pertanyaan bawaan, yang dipakai kalau admin belum mengubah apa pun

$grid = [
    'WAG' => ['open' => 8, 'gt3' => 1, 'lt3' => 2, 'reply' => 6],
    'TGG' => ['open' => 5, 'gt3' => 0, 'lt3' => 1, 'reply' => 4],
    'GCG' => ['open' => 3, 'gt3' => 0, 'lt3' => 0, 'reply' => 3],
];
$t = messi_totals($grid, $CH);
eq('open is summed across channels',  $t['open'], 16);
eq('hanging is gt3 + lt3',            $t['hanging'], 4);
eq('clear is what is left',           $t['clear'], 12);
eq('replies are summed',              $t['reply'], 13);

// Blank cells are zero, not an error: a channel someone does not use stays empty.
eq('blank cells count as zero',
   messi_totals(['WAG' => ['open' => '7', 'gt3' => '', 'lt3' => null]], $CH)['open'], 7);
eq('a missing channel is zero, not a crash',
   messi_totals(['WAG' => ['open' => 2]], $CH)['hanging'], 0);

eq('nothing hanging is green',  messi_lamp(messi_totals(['WAG' => ['open' => 9]], $CH))['level'], 'green');
eq('under three days is amber', messi_lamp(messi_totals(['WAG' => ['open' => 9, 'lt3' => 2]], $CH))['level'], 'amber');
eq('over three days is red',    messi_lamp(messi_totals(['WAG' => ['open' => 9, 'gt3' => 1]], $CH))['level'], 'red');
eq('red wins over amber',
   messi_lamp(messi_totals(['WAG' => ['open' => 9, 'gt3' => 1, 'lt3' => 4]], $CH))['level'], 'red');

/* ------------------------------------------------------------- the lifecycle */

// Mirrors test_submitting_before_due_is_on_time_after_is_late.
eq('before 18:00 is on time', messi_submit_status(17), 'submitted');
eq('18:00 exactly is late',   messi_submit_status(18), 'late');
eq('after 18:00 is late',     messi_submit_status(21), 'late');

// Mirrors the reaper: a day nobody reported is a recorded fact, not an absent row.
eq('today with no row is still pending', messi_cycle_status('2026-09-30', null, '2026-09-30'), 'pending');
eq('yesterday with no row is missed',    messi_cycle_status('2026-09-29', null, '2026-09-30'), 'missed');
eq('a submitted row stays submitted',
   messi_cycle_status('2026-09-29', ['submitted_at' => '2026-09-29 10:00:00', 'status' => 'submitted'], '2026-09-30'),
   'submitted');
eq('a late row stays late, and is not laundered into missed',
   messi_cycle_status('2026-09-29', ['submitted_at' => '2026-09-29 14:00:00', 'status' => 'late'], '2026-09-30'),
   'late');
eq('a generated but unanswered row is missed, not pending, once its day has passed',
   messi_cycle_status('2026-09-29', ['submitted_at' => null, 'status' => 'pending'], '2026-09-30'),
   'missed');

// Mirrors test_a_promise_met_late_was_not_met.
eq('a promise met on its date was kept',   messi_commitment_outcome('2026-10-02', '2026-10-02'), 'kept');
eq('a promise met early was kept',         messi_commitment_outcome('2026-10-02', '2026-10-01'), 'kept');
eq('a promise met a day late was broken',  messi_commitment_outcome('2026-10-02', '2026-10-03'), 'broken');

// Janji yang jatuh di hari libur baru bisa dikerjakan hari kerja berikutnya. Menandainya
// ingkar hari Minggu berarti menghukum orang yang belum punya satu pun hari kerja untuk
// menyelesaikannya. 3 Okt 2026 Sabtu, 4 Okt Minggu, 5 Okt Senin.
eq('tenggat hari Sabtu sebenarnya hari Senin',
   messi_workday_on_or_after('2026-10-03'), '2026-10-05');
eq('dan tenggat hari kerja tetap hari itu juga',
   messi_workday_on_or_after('2026-10-02'), '2026-10-02');
eq('janji Sabtu belum ingkar di hari Minggu',
   messi_commitment_outcome('2026-10-03', '2026-10-04'), 'kept');
eq('maupun di hari Senin — hari kerjanya baru hari itu',
   messi_commitment_outcome('2026-10-03', '2026-10-05'), 'kept');
eq('baru hari Selasa dia benar-benar lewat',
   messi_commitment_outcome('2026-10-03', '2026-10-06'), 'broken');

/* ------------------------------------------------------------- missed days */

$done = ['submitted_at' => '2026-09-29 10:00:00', 'status' => 'submitted'];

// FIRST_DAY for the squad is Mon 28 Sep, so the look-back stops there.
eq('two skipped days are both named',
   messi_missed_days([], '2026-09-28', '2026-09-30'),
   ['2026-09-29', '2026-09-28']);

eq('a day that was reported is not named',
   messi_missed_days(['2026-09-29' => $done], '2026-09-28', '2026-09-30'),
   ['2026-09-28']);

// The bug a new joiner found: nobody is absent for the days before they existed.
eq('a new joiner is not blamed for the days before they joined',
   messi_missed_days([], '2026-09-29', '2026-09-30'),
   ['2026-09-29']);
eq('someone who joined today owes nothing yet',
   messi_missed_days([], '2026-09-30', '2026-09-30'),
   []);

// Monday looks back over the weekend, not at it.
eq('Monday does not count Saturday and Sunday as missed',
   messi_missed_days([], '2026-09-01', '2026-10-05'),
   ['2026-10-02', '2026-10-01', '2026-09-30', '2026-09-29', '2026-09-28']);

/* ----------------------------------------------------------------- rencana */

// Laporan dua minggu pertama tersimpan dengan bentuk lama. Dia harus tetap terbaca utuh
// tanpa satu baris pun diubah di database.
eq('bentuk lama (satu plan + due) terbaca sebagai satu baris',
   messi_plans(['plan' => 'Telepon Klien A', 'due' => '2026-10-07']),
   [['action' => 'Telepon Klien A', 'due' => '2026-10-07']]);

eq('bentuk baru terbaca apa adanya',
   messi_plans(['plans' => [['action' => 'A', 'due' => '2026-10-07'],
                            ['action' => 'B', 'due' => '2026-10-09']]]),
   [['action' => 'A', 'due' => '2026-10-07'], ['action' => 'B', 'due' => '2026-10-09']]);

eq('bentuk baru menang kalau dua-duanya ada',
   messi_plans(['plan' => 'lama', 'due' => '2026-10-01',
                'plans' => [['action' => 'baru', 'due' => '2026-10-07']]]),
   [['action' => 'baru', 'due' => '2026-10-07']]);

eq('laporan tanpa rencana sama sekali', messi_plans(['grid' => []]), []);
eq('baris kosong tidak ikut terhitung',
   count(messi_plans(['plans' => [['action' => 'A', 'due' => '2026-10-07'],
                                  ['action' => '', 'due' => '']]])), 1);
eq('lebih dari lima baris dipotong', count(messi_plans(['plans' => array_fill(0, 9,
   ['action' => 'A', 'due' => '2026-10-07'])])), 5);
eq('spasi di ujung dibuang',
   messi_plans(['plans' => [['action' => '  Telepon  ', 'due' => ' 2026-10-07 ']]]),
   [['action' => 'Telepon', 'due' => '2026-10-07']]);

/* -------------------------------------------------------------- validation */

$full = ['grid' => $grid, 'detail' => 'OA003 belum dibalas', 'plan' => 'Follow up pagi',
         'due' => '2026-10-02', 'declared' => true];

eq('a complete report passes', messi_validate($full, $CFG), null);

eq('an untouched grid is refused',
   messi_validate(['declared' => true], $CFG),
   'Isi dulu angkanya. Kalau kosong semua, tulis 0.');

eq('all zeroes is a valid answer, not an empty one',
   messi_validate(['grid' => ['WAG' => ['open' => '0']], 'declared' => true], $CFG), null);

// The order that matters: arithmetic before paperwork. Ticking the declaration must never
// be what stands between someone and being told their numbers do not add up.
eq('impossible arithmetic is caught before the declaration is asked for',
   messi_validate(['grid' => ['WAG' => ['open' => 2, 'gt3' => 5]], 'declared' => false], $CFG),
   'Yang gantung lebih banyak daripada yang masih aktif. Cek lagi angkanya.');

eq('hanging with no detail is refused',
   messi_validate(['grid' => $grid, 'declared' => true], $CFG),
   'Tulis dulu yang mana saja yang gantung.');
eq('hanging with no plan is refused',
   messi_validate(['grid' => $grid, 'detail' => 'OA003', 'declared' => true], $CFG),
   'Tulis rencananya.');
eq('a plan with no date is refused, because nothing can chase it',
   messi_validate(['grid' => $grid, 'detail' => 'OA003', 'plan' => 'besok', 'declared' => true], $CFG),
   'Pilih tanggalnya. Tanpa tanggal, tidak ada yang bisa mengingatkan.');

eq('satu baris lengkap sudah cukup',
   messi_validate(['grid' => $grid, 'detail' => 'OA003', 'declared' => true,
                   'plans' => [['action' => 'Telepon', 'due' => '2026-10-07']]], $CFG), null);
eq('beberapa baris lengkap juga boleh',
   messi_validate(['grid' => $grid, 'detail' => 'OA003', 'declared' => true,
                   'plans' => [['action' => 'A', 'due' => '2026-10-07'],
                               ['action' => 'B', 'due' => '2026-10-09']]], $CFG), null);
// Satu baris setengah terisi adalah janji yang tidak bisa ditagih — ditolak, bukan dibuang
// diam-diam, karena membuangnya berarti menghapus sesuatu yang orang sengaja ketik.
eq('baris dengan tanggal tapi tanpa rencana ditolak',
   messi_validate(['grid' => $grid, 'detail' => 'OA003', 'declared' => true,
                   'plans' => [['action' => 'A', 'due' => '2026-10-07'],
                               ['action' => '', 'due' => '2026-10-09']]], $CFG),
   'Ada tanggal tanpa rencana. Tulis rencananya, atau hapus barisnya.');
eq('baris dengan rencana tapi tanpa tanggal ditolak',
   messi_validate(['grid' => $grid, 'detail' => 'OA003', 'declared' => true,
                   'plans' => [['action' => 'A', 'due' => '']]], $CFG),
   'Pilih tanggalnya. Tanpa tanggal, tidak ada yang bisa mengingatkan.');
eq('whitespace is not an answer',
   messi_validate(['grid' => $grid, 'detail' => '   ', 'declared' => true], $CFG),
   'Tulis dulu yang mana saja yang gantung.');

eq('a clean day needs no detail, plan or date',
   messi_validate(['grid' => ['WAG' => ['open' => 9, 'reply' => 9]], 'declared' => true], $CFG), null);

eq('the declaration is still required',
   messi_validate(['grid' => ['WAG' => ['open' => 9]], 'declared' => false], $CFG),
   'Centang pernyataannya dulu.');

/* ------------------------------------------------------------- the report */


$doc = ['day' => '2026-09-30'] + $full + ['escalation' => '', 'prista' => 'PT Sinar Jaya'];

$want = implode("\n", [
    'MESSI Report',
    'Date: Rab 30 Sep (2026-09-30)',
    'Report by: Nicho',
    '',
    '[MERAH] Ada yang gantung lebih dari 3 hari',
    '',
    'a. Channel aktif/open: 8 WAG, 5 TGG, 3 GCG',
    'b. Gantung >3 hari: 1',
    'c. Gantung <3 hari: 3',
    'd. Tidak gantung: 12',
    'e. Sudah dibalas hari ini: 13',
    'f. Yang masih gantung: OA003 belum dibalas',
    'g. Rencana: Follow up pagi (target Jum 2 Okt)',
    'h. Eskalasi: Belum ada',
    'i. Calon project baru: PT Sinar Jaya',
    '',
    'Deklarasi: ' . MESSI_DECLARATION,
]);
eq('the report reads exactly as the squad already writes it',
   messi_build_report($doc, 'Nicho', $CFG), $want);

// Lebih dari satu rencana jadi daftar; satu rencana tetap satu baris seperti di atas.
$banyak = messi_build_report(
    ['day' => '2026-09-30', 'grid' => ['WAG' => ['open' => 9, 'gt3' => 1]],
     'detail' => 'tiga channel', 'declared' => true,
     'plans' => [['action' => 'Telepon Klien A', 'due' => '2026-10-02'],
                 ['action' => 'Kirim revisi Vendor B', 'due' => '2026-10-05']]],
    'Nicho', $CFG);
ok('dua rencana jadi dua baris berbutir',
   str_contains($banyak, "g. Rencana:\n- Telepon Klien A (target Jum 2 Okt)\n- Kirim revisi Vendor B (target Sen 5 Okt)"));

$clean = messi_build_report(
    ['day' => '2026-09-30', 'grid' => ['WAG' => ['open' => 9, 'reply' => 9]]], 'Rio', $CFG);
ok('a clean day is green', str_contains($clean, '[HIJAU] Tidak ada yang gantung'));
ok('empty fields read as Belum ada, not as blanks',
   str_contains($clean, 'f. Yang masih gantung: Belum ada'));
ok('a plan with no date carries no target',
   !str_contains(messi_build_report(
       ['day' => '2026-09-30', 'grid' => ['WAG' => ['open' => 1]], 'plan' => 'besok'], 'Rio', $CFG),
     'target'));

/* ------------------------------------------------- setelan yang bisa diubah */

// Dokumen kosong, dokumen sampah, dan dokumen setengah jadi harus semuanya menghasilkan
// setelan yang bisa dipakai. Halaman setelan yang salah tidak boleh mematikan aplikasinya.
eq('tanpa setelan tersimpan, yang berlaku adalah bawaannya',
   messi_config_normalize(null), messi_config_default());
eq('dokumen sampah pun tetap menghasilkan setelan utuh',
   messi_config_normalize('bukan array'), messi_config_default());
eq('kunci yang hilang diisi dari bawaannya',
   messi_config_normalize(['threshold_days' => 1])['questions']['detail']['label'],
   'Yang mana saja?');

eq('ambang yang diubah memang berlaku',
   messi_config_normalize(['threshold_days' => 1])['threshold_days'], 1);
eq('ambang di luar akal kembali ke bawaannya',
   messi_config_normalize(['threshold_days' => 0])['threshold_days'], MESSI_THRESHOLD);
eq('begitu juga ambang yang bukan angka',
   messi_config_normalize(['threshold_days' => 'tiga'])['threshold_days'], MESSI_THRESHOLD);
// Jam tutup sebelum jam buka berarti setiap laporan telat sejak detik pertama.
eq('jam tutup tidak boleh mendahului jam buka',
   messi_config_normalize(['open_hour' => 20, 'due_hour' => 8])['due_hour'], 24);
eq('jam yang masuk akal diterima apa adanya',
   messi_config_normalize(['open_hour' => 7, 'due_hour' => 16])['due_hour'], 16);

$ch = messi_config_normalize(['channels' => [
    ['key' => 'ig', 'label' => 'Instagram DM', 'full' => 'Instagram Direct'],
    ['key' => 'ig', 'label' => 'Kembar'],                       // kunci ganda
    ['key' => 'spasi kosong', 'label' => 'Tidak sah'],           // kunci tidak sah
    ['key' => 'EMAIL'],                                          // tanpa nama
]])['channels'];
eq('channel bisa diganti seluruhnya', array_column($ch, 'key'), ['IG', 'EMAIL']);
eq('kodenya dibakukan jadi huruf besar', $ch[0]['key'], 'IG');
eq('channel tanpa nama memakai kodenya sendiri', $ch[1]['label'], 'EMAIL');
eq('nol channel bukan setelan, jadi kembali ke bawaannya',
   count(messi_config_normalize(['channels' => []])['channels']), 3);

eq('pertanyaan bisa ditulis ulang',
   messi_config_normalize(['questions' => ['detail' => ['label' => 'Nomor tiket mana?']]])
     ['questions']['detail']['label'], 'Nomor tiket mana?');
// Admin menulis teks yang dibaca pemain, jadi tidak boleh ada HTML yang lolos ke layar.
eq('pertanyaan yang kosong kembali ke bawaannya',
   messi_config_normalize(['questions' => ['detail' => ['label' => '   ']]])
     ['questions']['detail']['label'], 'Yang mana saja?');
eq('baris baru di pertanyaan dirapikan jadi satu baris',
   messi_config_normalize(['questions' => ['detail' => ['label' => "Satu\n\nDua"]]])
     ['questions']['detail']['label'], 'Satu Dua');

// Validasi harus memakai kalimat yang ditulis admin, kalau tidak halaman setelannya bohong.
$cfgTiket = messi_config_normalize(['questions' => [
    'detail' => ['error' => 'Tulis nomor tiketnya dulu.'],
]]);
$gantung = ['grid' => ['WAG' => ['open' => 5, 'gt3' => 1]], 'declared' => true];
eq('pesan kesalahan ikut yang ditulis admin',
   messi_validate($gantung, $cfgTiket), 'Tulis nomor tiketnya dulu.');

// Ambang hanya mengubah kalimatnya, bukan hitungannya: gt3 tetap kolom yang merah.
eq('lampunya menyebut ambang yang berlaku',
   messi_lamp(['gt3' => 1, 'hanging' => 1, 'open' => 5], 1)['label'],
   'Ada yang gantung lebih dari 1 hari');
eq('jam tutup yang diubah mengubah arti telat',
   messi_submit_status(16, 16), 'late');
eq('dan yang sebelum itu tetap tepat waktu',
   messi_submit_status(15, 16), 'submitted');

/* -------------------------------------- laporan mengikuti setelan yang ada */

$cfgLain = messi_config_normalize([
    'team_name' => 'Tim Dukungan',
    'threshold_days' => 1,
    'channels' => [['key' => 'IG', 'label' => 'IG', 'full' => 'Instagram']],
    'questions' => ['prista' => ['show' => false]],
]);
$lap = messi_build_report(
    ['day' => '2026-09-30', 'grid' => ['IG' => ['open' => 4, 'gt3' => 1]],
     'detail' => 'DM @budi', 'plans' => [['action' => 'Balas', 'due' => '2026-10-01']],
     'prista' => 'tidak akan tercetak'],
    'Rio', $cfgLain);
ok('nama tim ikut tercetak', str_contains($lap, 'Tim: Tim Dukungan   Report by: Rio'));
ok('kolomnya menyebut ambang yang berlaku', str_contains($lap, 'b. Gantung >1 hari: 1'));
ok('lampunya juga', str_contains($lap, 'lebih dari 1 hari'));
ok('channel yang berlaku saja yang dihitung', str_contains($lap, 'a. Channel aktif/open: 4 IG'));
// Pertanyaan yang dimatikan tidak muncul sebagai "Belum ada" — itu bukan jawaban kosong,
// pertanyaannya memang tidak pernah diajukan.
ok('pertanyaan yang dimatikan hilang dari laporan', !str_contains($lap, 'Calon project'));
ok('dan jawabannya tidak bocor lewat jalan lain',
   !str_contains($lap, 'tidak akan tercetak'));
ok('yang masih hidup tetap ada', str_contains($lap, 'h. Eskalasi:'));
ok('nama tim yang kosong tidak mencetak label kosong',
   str_contains(messi_build_report(['day' => '2026-09-30', 'grid' => []], 'Rio', $CFG),
                "\nReport by: Rio"));

/* ------------------------------- laporan lama dibaca dengan setelan saat itu */

// Mengganti setelan hari ini tidak boleh menulis ulang arti laporan bulan lalu.
$lama = [
    'day' => '2026-09-30',
    'grid' => ['WAG' => ['open' => 6, 'gt3' => 1], 'IG' => ['open' => 2]],
    'cfg' => ['channels' => [['key' => 'WAG', 'label' => 'WAG', 'full' => 'WhatsApp Group']],
              'threshold_days' => 3],
];
$cetak = messi_build_report($lama, 'Nicho', $cfgLain);
ok('laporan lama memakai channel yang berlaku saat dikirim',
   str_contains($cetak, 'a. Channel aktif/open: 6 WAG'));
ok('dan tidak kejatuhan channel yang baru ditambahkan', !str_contains($cetak, 'IG'));
ok('ambang saat itu yang dipakai, bukan ambang hari ini',
   str_contains($cetak, 'b. Gantung >3 hari: 1'));
eq('cuplikan setelan berisi yang perlu saja, bukan seluruh dokumen',
   array_keys(messi_config_snapshot($CFG)), ['channels', 'threshold_days']);
eq('laporan tanpa cuplikan dibaca dengan setelan yang berlaku sekarang',
   messi_doc_config(['day' => '2026-09-30'], $cfgLain)['threshold_days'], 1);

/* -------------------------------------------------------------------- done */

echo $passed . ' checks passed';
if ($failed) {
    echo ', ' . count($failed) . " FAILED\n\n";
    foreach ($failed as $f) { echo "  FAIL  " . $f . "\n"; }
    exit(1);
}
echo ", 0 failed\n";
