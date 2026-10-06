<?php
/**
 * A week of the machine running, hour by hour, with the clock frozen and the bot's
 * messages caught instead of sent.
 *
 * This is the part nobody can watch in a browser: what happens at 09:00 when the office
 * is empty, what happens to a day nobody answered, and whether running the same hour
 * twice sends the same person two messages.
 *
 * See tests/bootstrap_test.php for how to point it at a database.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test.php';
$root = test_db('messi_cron_test');
require_once __DIR__ . '/../lib/tick.php';

/* What would be posted to Google Chat, caught in a bucket instead. */
$sent = [];
Chat::$send = function (string $text) use (&$sent): bool {
    $sent[] = ['text' => $text];
    return true;
};

/** Runs the job at one Jakarta hour and returns only what it sent in that run. */
function tick_at(string $utc): array
{
    global $sent;
    $sent = [];
    Clock::freeze($utc);
    messi_tick();
    return $sent;
}

function said(array $messages, string $needle): bool
{
    foreach ($messages as $m) {
        if (str_contains($m['text'], $needle)) {
            return true;
        }
    }
    return false;
}

/* --------------------------------------------------------------- the squad */

Clock::freeze('2026-09-28T01:00:00Z');
$nicho = make_user('nicho@example.test', 'Nicho', 'player', '2026-09-28');
$chief = make_user('chief@example.test', 'Chief', 'leader', '2026-09-28');
$dita  = make_user('dita@example.test',  'Dita',  'player', '2026-09-28');
$rio   = make_user('rio@example.test',   'Rio',   'player', '2026-09-30');   // masuk Rabu
$budi  = make_user('budi@example.test',  'Budi',  'player', '2026-09-28');
q('UPDATE users SET active = 0 WHERE id = ?', [$budi['id']]);

$clean = ['grid' => ['WAG' => ['open' => 6, 'reply' => 6]], 'declared' => true];
function hanging(string $due): array
{
    return ['grid' => ['WAG' => ['open' => 9, 'gt3' => 1, 'lt3' => 1, 'reply' => 4]],
            'detail' => 'WAG Klien A', 'plan' => 'Telepon Klien A', 'due' => $due,
            'declared' => true];
}

/* =============================================== Senin 28 Sep — hari pertama */

$m = tick_at('2026-09-28T01:00:00Z');                       // 08:00, kantor belum buka
eq('sebelum jam 09:00 belum ada apa-apa', count($m), 0);
eq('dan belum ada laporan yang dibuka',
   (int) q1('SELECT COUNT(*) n FROM cycles')['n'], 0);

$m = tick_at('2026-09-28T02:00:00Z');                       // 09:00
eq('jam 09:00 laporan dibuka untuk yang sudah mulai',
   (int) q1('SELECT COUNT(*) n FROM cycles WHERE day = ?', ['2026-09-28'])['n'], 3);
eq('Rio belum bergabung, jadi belum punya laporan',
   (int) q1('SELECT COUNT(*) n FROM cycles WHERE user_id = ?', [$rio['id']])['n'], 0);
eq('Budi nonaktif, jadi tidak ikut dibuka',
   (int) q1('SELECT COUNT(*) n FROM cycles WHERE user_id = ?', [$budi['id']])['n'], 0);
eq('satu pesan ke space, bukan satu per orang', count($m), 1);
ok('pesannya menyebut tanggal hari ini', said($m, 'Sen 28 Sep'));
ok('dan membawa link ke aplikasinya',
   said($m, '<https://example.test/messi|Isi laporan>'));
// The one rule a shared space imposes: never post something that logs somebody in.
ok('TIDAK pernah menempel link masuk sekali pakai di space bersama',
   !said($m, 'login.php?t='));
ok('hari pertama tidak menagih janji yang belum ada', !said($m, 'jatuh tempo'));

$m = tick_at('2026-09-28T02:00:00Z');                       // 09:00 lagi
eq('cron yang jalan dua kali tidak memposting dua kali', count($m), 0);
eq('dan tidak membuat laporan kedua',
   (int) q1('SELECT COUNT(*) n FROM cycles WHERE day = ?', ['2026-09-28'])['n'], 3);

$m = tick_at('2026-09-28T04:00:00Z');                       // 11:00, jam-jam biasa
eq('di jam biasa mesinnya diam', count($m), 0);

Clock::freeze('2026-09-28T04:00:00Z');
repo_save_cycle($nicho, uid((int) $nicho['id']) . '__2026-09-28', hanging('2026-09-29'));

$m = tick_at('2026-09-28T10:00:00Z');                       // 17:00
eq('pengingatnya satu pesan', count($m), 1);
ok('yang disebut hanya yang belum lapor', said($m, 'Chief') && said($m, 'Dita'));
ok('Nicho yang sudah lapor tidak ikut disebut', !said($m, 'Nicho'));
ok('bunyinya soal tenggat', said($m, '18:00'));

$m = tick_at('2026-09-28T10:00:00Z');
eq('pengingat tidak diulang di jam yang sama', count($m), 0);

Clock::freeze('2026-09-28T10:30:00Z');
repo_save_cycle($chief, uid((int) $chief['id']) . '__2026-09-28', $clean);

$m = tick_at('2026-09-28T11:00:00Z');                       // 18:00
// Rekapnya tidak lagi dikirim ke mana-mana: atasan membacanya di layar Squad, yang bisa
// membuka hari lain dan laporan utuh tiap orang — dua hal yang tidak bisa dilakukan
// sebuah pesan chat.
eq('jam 18:00 tidak ada rekap yang dikirim ke chat', count($m), 0);

/* ================================================== Selasa 29 Sep — konsekuensi */

$m = tick_at('2026-09-29T02:00:00Z');                       // 09:00
eq('hari Senin yang tidak diisi Dita tercatat tidak lapor',
   q1('SELECT status FROM cycles WHERE user_id = ? AND day = ?',
      [$dita['id'], '2026-09-28'])['status'], 'missed');
eq('tapi yang sudah dilaporkan tidak ikut tersapu',
   q1('SELECT status FROM cycles WHERE user_id = ? AND day = ?',
      [$nicho['id'], '2026-09-28'])['status'], 'submitted');

ok('janji yang jatuh tempo hari ini disebut di pesan pagi',
   said($m, 'Telepon Klien A'));
ok('lengkap dengan nama yang menjanjikan', said($m, 'Nicho — Telepon Klien A'));

Clock::freeze('2026-09-29T04:00:00Z');
$promise = q1('SELECT * FROM commitments WHERE user_id = ?', [$nicho['id']]);
eq('janji Nicho ditepati pada harinya',
   repo_resolve_commitment($nicho, cid((int) $promise['id']), ['status' => 'kept'])['status'],
   'kept');
repo_save_cycle($nicho, uid((int) $nicho['id']) . '__2026-09-29', $clean);

// Chief membuat janji yang tidak akan dia tepati.
repo_save_cycle($chief, uid((int) $chief['id']) . '__2026-09-29', hanging('2026-09-30'));

$m = tick_at('2026-09-29T11:00:00Z');                       // 18:00
eq('tetap tidak ada rekap yang diposting', count($m), 0);

/* ================================================ Rabu 30 Sep — orang baru */

$m = tick_at('2026-09-30T02:00:00Z');                       // 09:00
eq('Rio yang baru masuk hari ini langsung punya laporan',
   (int) q1('SELECT COUNT(*) n FROM cycles WHERE user_id = ? AND day = ?',
            [$rio['id'], '2026-09-30'])['n'], 1);
eq('tetap satu pesan, berapa pun orangnya', count($m), 1);

// Sore hari: Rio yang baru masuk ikut disebut kalau memang belum lapor.
$m = tick_at('2026-09-30T10:00:00Z');                       // 17:00
ok('orang baru ikut diingatkan pada hari pertamanya', said($m, 'Rio'));

/* ================================== Kamis 1 Okt — janji yang tidak ditepati */

$m = tick_at('2026-10-01T02:00:00Z');                       // 09:00
eq('janji Chief yang lewat tanggal patah dengan sendirinya',
   q1('SELECT status FROM commitments WHERE user_id = ? ORDER BY id DESC LIMIT 1',
      [$chief['id']])['status'], 'broken');
eq('janji Nicho yang ditepati tetap ditepati',
   q1('SELECT status FROM commitments WHERE user_id = ? ORDER BY id LIMIT 1',
      [$nicho['id']])['status'], 'kept');
ok('janji yang sudah patah tidak lagi ditagih di pesan pagi',
   !said($m, 'Chief — Telepon Klien A'));

/* ============================================== Sabtu 3 Okt — akhir pekan */

$before = (int) q1('SELECT COUNT(*) n FROM cycles')['n'];
$m = tick_at('2026-10-03T02:00:00Z');
eq('akhir pekan tidak membuka laporan',
   (int) q1('SELECT COUNT(*) n FROM cycles')['n'], $before);
eq('dan tidak ada yang diganggu', count($m), 0);

$m = tick_at('2026-10-05T02:00:00Z');                       // Senin lagi
ok('Senin berikutnya jalan seperti biasa',
   (int) q1('SELECT COUNT(*) n FROM cycles WHERE day = ?', ['2026-10-05'])['n'] > 0);
ok('dan pesannya menyebut Senin, bukan akhir pekan', said($m, 'Sen 5 Okt'));

/* ------------------------------------------- kalau webhook-nya belum diisi */

Chat::$send = null;                       // seperti config tanpa chat_webhook
$m2 = [];
q('DELETE FROM job_log WHERE kind = ?', ['notify_open']);
Clock::freeze('2026-10-06T02:00:00Z');
$did = messi_tick();
ok('tanpa webhook, hari tetap dibuka — pesannya saja yang tidak ada',
   ($did['opened'] ?? 0) > 0 && ($did['asked'] ?? 0) === 0);
Chat::$send = function (string $text) use (&$sent): bool {
    $sent[] = ['text' => $text];
    return true;
};

/* ------------------------------------- janji yang ditulis beberapa baris */

// Kolom rencananya textarea. Orang menekan Enter, dan baris keduanya dulu lepas dari
// bullet dan dari namanya — terbaca seperti janji milik entah siapa.
$panjang = chat_morning('2026-10-06', [
    ['name' => 'Nicho', 'action_text' => "Balas setelah Onboarding selesai\nBalas setelah project selesai"],
    ['name' => 'Rio',   'action_text' => 'Telepon Klien A'],
]);
$baris = array_values(array_filter(explode("\n", $panjang), fn($l) => str_starts_with($l, '- ')));
eq('dua janji tetap dua baris, berapa pun Enter-nya', count($baris), 2);
ok('baris kedua janji pertama ikut ke barisnya sendiri',
   str_contains($baris[0], 'Onboarding') && str_contains($baris[0], 'project selesai'));
ok('dan tetap membawa nama pemiliknya', str_starts_with($baris[0], '- Nicho — '));
ok('janji orang kedua tidak tercampur', $baris[1] === '- Rio — Telepon Klien A');

/* ------------------------------------------------ bentuk pesan minta bantuan */

$pesan = chat_escalation("Rio D'Souza", 'Butuh Chief approve harga Klien A');
ok('menyebut siapa yang minta', str_contains($pesan, "Rio D'Souza"));
ok('apostrof tetap utuh, bukan entitas HTML', !str_contains($pesan, '&#039;'));
ok('membawa isi permintaannya', str_contains($pesan, 'Butuh Chief approve harga Klien A'));
ok('memakai markup Google Chat, bukan HTML',
   str_contains($pesan, '*') && !str_contains($pesan, '<b>'));
ok('dan membawa link ke aplikasinya', str_contains($pesan, 'https://example.test/messi|'));

/* ------------------------------------------------------- catatan yang tertinggal */

$kinds = q('SELECT DISTINCT kind FROM job_log ORDER BY kind')->fetchAll(PDO::FETCH_COLUMN);
eq('setiap pekerjaan meninggalkan catatan',
   $kinds, ['break_overdue', 'generate', 'notify_due', 'notify_open', 'reap']);

Clock::unfreeze();
Chat::$send = null;
$root->exec('DROP DATABASE `' . $GLOBALS['MESSI_TEST_DBNAME'] . '`');
done();
