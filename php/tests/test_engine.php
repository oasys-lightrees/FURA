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

/* -------------------------------------------------------------- validation */

$full = ['grid' => $grid, 'detail' => 'OA003 belum dibalas', 'plan' => 'Follow up pagi',
         'due' => '2026-10-02', 'declared' => true];

eq('a complete report passes', messi_validate($full, $CH), null);

eq('an untouched grid is refused',
   messi_validate(['declared' => true], $CH),
   'Isi dulu angkanya. Kalau kosong semua, tulis 0.');

eq('all zeroes is a valid answer, not an empty one',
   messi_validate(['grid' => ['WAG' => ['open' => '0']], 'declared' => true], $CH), null);

// The order that matters: arithmetic before paperwork. Ticking the declaration must never
// be what stands between someone and being told their numbers do not add up.
eq('impossible arithmetic is caught before the declaration is asked for',
   messi_validate(['grid' => ['WAG' => ['open' => 2, 'gt3' => 5]], 'declared' => false], $CH),
   'Yang gantung lebih banyak daripada yang masih aktif. Cek lagi angkanya.');

eq('hanging with no detail is refused',
   messi_validate(['grid' => $grid, 'declared' => true], $CH),
   'Tulis dulu yang mana saja yang gantung.');
eq('hanging with no plan is refused',
   messi_validate(['grid' => $grid, 'detail' => 'OA003', 'declared' => true], $CH),
   'Tulis rencananya.');
eq('a plan with no date is refused, because nothing can chase it',
   messi_validate(['grid' => $grid, 'detail' => 'OA003', 'plan' => 'besok', 'declared' => true], $CH),
   'Pilih tanggalnya. Tanpa tanggal, tidak ada yang bisa mengingatkan.');
eq('whitespace is not an answer',
   messi_validate(['grid' => $grid, 'detail' => '   ', 'declared' => true], $CH),
   'Tulis dulu yang mana saja yang gantung.');

eq('a clean day needs no detail, plan or date',
   messi_validate(['grid' => ['WAG' => ['open' => 9, 'reply' => 9]], 'declared' => true], $CH), null);

eq('the declaration is still required',
   messi_validate(['grid' => ['WAG' => ['open' => 9]], 'declared' => false], $CH),
   'Centang pernyataannya dulu.');

/* ------------------------------------------------------------- the report */

$defs = ['WAG' => 'WAG', 'TGG' => 'TGG', 'GCG' => 'GCG'];
$doc = ['day' => '2026-09-30'] + $full + ['escalation' => '', 'prista' => 'PT Sinar Jaya'];

$want = implode("\n", [
    'MESSI Report',
    'Date: Rab 30 Sep (2026-09-30)',
    'Squad: OASYS   Report by: Nicho',
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
    'i. Calon PRISTA baru: PT Sinar Jaya',
    '',
    'Deklarasi: ' . MESSI_DECLARATION,
]);
eq('the report reads exactly as the squad already writes it',
   messi_build_report($doc, 'Nicho', $defs), $want);

$clean = messi_build_report(
    ['day' => '2026-09-30', 'grid' => ['WAG' => ['open' => 9, 'reply' => 9]]], 'Rio', $defs);
ok('a clean day is green', str_contains($clean, '[HIJAU] Tidak ada yang gantung'));
ok('empty fields read as Belum ada, not as blanks',
   str_contains($clean, 'f. Yang masih gantung: Belum ada'));
ok('a plan with no date carries no target',
   !str_contains(messi_build_report(
       ['day' => '2026-09-30', 'grid' => ['WAG' => ['open' => 1]], 'plan' => 'besok'], 'Rio', $defs),
     'target'));

/* -------------------------------------------------------------------- done */

echo $passed . ' checks passed';
if ($failed) {
    echo ', ' . count($failed) . " FAILED\n\n";
    foreach ($failed as $f) { echo "  FAIL  " . $f . "\n"; }
    exit(1);
}
echo ", 0 failed\n";
