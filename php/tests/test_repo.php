<?php
/**
 * The rules again, this time against a real MySQL — because a schema only tells the
 * truth when a database has agreed to it.
 *
 * See tests/bootstrap_test.php for how to point it at a database.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_test.php';
$root = test_db('messi_test');
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/repo.php';

/* ------------------------------------------------------------------- setup */

Clock::freeze('2026-09-30T03:00:00Z');       // Rab 30 Sep, 10:00 Jakarta
$nicho = make_user('nicho@example.test', 'Nicho', 'player', '2026-09-28');
$rio   = make_user('rio@example.test',   'Rio',   'player', '2026-09-30');   // baru hari ini
$lead = make_user('lead@example.test', 'Lia', 'leader', '2026-09-28');

$goodGrid = ['WAG' => ['open' => 8, 'gt3' => 1, 'lt3' => 2, 'reply' => 6]];
$clean    = ['grid' => ['WAG' => ['open' => 5, 'reply' => 5]], 'declared' => true];
$hanging  = ['grid' => $goodGrid, 'detail' => 'OA003', 'plan' => 'Follow up',
             'due' => '2026-10-02', 'declared' => true];

/* -------------------------------------------------------------- generation */

eq('opening the day creates one cycle per person who has started',
   count(repo_generate('2026-09-30')), 3);
eq('opening it again creates nothing — the unique key holds',
   count(repo_generate('2026-09-30')), 0);
eq('and there is still exactly one row each',
   (int) q1('SELECT COUNT(*) n FROM cycles WHERE day = ?', ['2026-09-30'])['n'], 3);

eq('a weekend opens nothing at all', count(repo_generate('2026-10-03')), 0);

eq('someone who has not started yet gets no cycle',
   (int) q1('SELECT COUNT(*) n FROM cycles c WHERE c.user_id = ? AND c.day = ?',
            [$rio['id'], '2026-09-28'])['n'], 0);

/* ------------------------------------------------------------- submissions */

$res = repo_save_cycle($nicho, uid((int) $nicho['id']) . '__2026-09-30', $hanging);
eq('a report at 10:00 is on time', $res['status'], 'submitted');
eq('and the day still holds one row',
   (int) q1('SELECT COUNT(*) n FROM cycles WHERE user_id = ? AND day = ?',
            [$nicho['id'], '2026-09-30'])['n'], 1);

eq('the promise inside it was recorded',
   (int) q1('SELECT COUNT(*) n FROM commitments WHERE user_id = ? AND status = ?',
            [$nicho['id'], 'open'])['n'], 1);

// Editing the same day must move the promise, not leave a second one behind.
repo_save_cycle($nicho, uid((int) $nicho['id']) . '__2026-09-30',
                ['grid' => $goodGrid, 'detail' => 'OA003', 'plan' => 'Telepon langsung',
                 'due' => '2026-10-01', 'declared' => true]);
eq('editing the report does not leave a duplicate promise',
   (int) q1('SELECT COUNT(*) n FROM commitments WHERE user_id = ? AND status = ?',
            [$nicho['id'], 'open'])['n'], 1);
eq('the promise now says what the edit said',
   q1('SELECT action_text FROM commitments WHERE user_id = ?', [$nicho['id']])['action_text'],
   'Telepon langsung');

throws('nobody reports for somebody else',
       fn() => repo_save_cycle($nicho, uid((int) $lead['id']) . '__2026-09-30', $clean),
       'diri sendiri');

throws('nobody backfills a day they let pass',
       fn() => repo_save_cycle($nicho, uid((int) $nicho['id']) . '__2026-09-29', $clean),
       'hari ini');

throws('the server checks the arithmetic too, whatever the page allowed',
       fn() => repo_save_cycle($lead, uid((int) $lead['id']) . '__2026-09-30',
                               ['grid' => ['WAG' => ['open' => 1, 'gt3' => 4]], 'declared' => true]),
       'lebih banyak');

throws('a promise dated in the past is refused',
       fn() => repo_save_cycle($lead, uid((int) $lead['id']) . '__2026-09-30',
                               ['grid' => $goodGrid, 'detail' => 'x', 'plan' => 'y',
                                'due' => '2026-09-01', 'declared' => true]),
       'sudah lewat');

// After six, the same report is late — and the server's clock is the one that says so.
Clock::freeze('2026-09-30T11:30:00Z');       // 18:30 Jakarta
eq('after 18:00 the same report is late',
   repo_save_cycle($lead, uid((int) $lead['id']) . '__2026-09-30', $clean)['status'], 'late');
Clock::freeze('2026-09-30T03:00:00Z');

/* ------------------------------------------------------------- commitments */

$promise = q1('SELECT * FROM commitments WHERE user_id = ?', [$nicho['id']]);
$pid = cid((int) $promise['id']);

throws('a promise that is not yours is not yours to close',
       fn() => repo_resolve_commitment($lead, $pid, ['status' => 'kept']),
       'bukan punyamu');

// Due 1 Oct, resolved on 30 Sep: early, so kept.
eq('closing a promise before its date is kept',
   repo_resolve_commitment($nicho, $pid, ['status' => 'kept'])['status'], 'kept');
eq('closing it twice changes nothing',
   repo_resolve_commitment($nicho, $pid, ['status' => 'kept'])['status'], 'kept');

// A second promise, closed after its date. The page may say "done"; the server decides.
q('INSERT INTO commitments (user_id, action_text, due_date, status, created_at) VALUES (?,?,?,?,?)',
  [$nicho['id'], 'Kirim rekap', '2026-09-28', 'open', Clock::nowUtcSql()]);
$late = cid((int) db()->lastInsertId());
eq('a promise closed after its date is broken, whatever the page claims',
   repo_resolve_commitment($nicho, $late, ['status' => 'kept'])['status'], 'broken');

eq('an id the page invented is ignored rather than trusted',
   repo_resolve_commitment($nicho, 'c-u_1-xyz', ['status' => 'kept']),
   ['ignored' => true]);

/* ------------------------------------------------------------- the reaper */

// Nobody reported on the 29th. Running the reaper on the 30th records that.
repo_generate('2026-09-29');
eq('the 29th is marked missed once the 30th has come',
   repo_reap('2026-09-30') >= 1, true);
eq('and a second sweep has nothing left to do', repo_reap('2026-09-30'), 0);
eq("today's own cycle is never reaped",
   q1('SELECT status FROM cycles WHERE user_id = ? AND day = ?', [$nicho['id'], '2026-09-30'])['status'],
   'submitted');

q('INSERT INTO commitments (user_id, action_text, due_date, status, created_at) VALUES (?,?,?,?,?)',
  [$lead['id'], 'Tinjau OA001', '2026-09-29', 'open', Clock::nowUtcSql()]);
eq('an overdue promise breaks on its own', repo_break_overdue('2026-09-30'), 1);
eq('and stays broken without being counted twice', repo_break_overdue('2026-09-30'), 0);

/* --------------------------------------------------------- what the page sees */

$cycles = repo_cycles();
ok('the page gets its own document id back',
   isset($cycles[uid((int) $nicho['id']) . '__2026-09-30']));
eq('with the answers as it wrote them',
   $cycles[uid((int) $nicho['id']) . '__2026-09-30']['detail'], 'OA003');

eq('a player is handed only their own reports',
   array_keys(repo_cycles(90, (int) $nicho['id'])),
   [uid((int) $nicho['id']) . '__2026-09-29', uid((int) $nicho['id']) . '__2026-09-30']);
ok('and none of anybody else\'s promises',
   array_reduce(repo_commitments(90, (int) $nicho['id']),
                fn($all, $c) => $all && $c['owner'] === uid((int) $nicho['id']), true));
ok('a leader is handed the squad, which is the screen they have',
   count(repo_cycles()) > count(repo_cycles(90, (int) $nicho['id'])));

$roster = repo_roster();
eq('the roster carries joining dates, which is what keeps new people innocent',
   $roster[uid((int) $rio['id'])]['joined'], '2026-09-30');

// The join-date rule, end to end: Rio started today and owes nothing for last week.
$rows = [];
foreach (q('SELECT day, status, submitted_at FROM cycles WHERE user_id = ?', [$rio['id']]) as $r) {
    $rows[$r['day']] = $r;
}
eq('on their first day a new joiner owes nothing yet',
   messi_missed_days($rows, $roster[uid((int) $rio['id'])]['joined'], '2026-09-30'), []);
eq('the day after, only their own first day counts against them',
   messi_missed_days($rows, $roster[uid((int) $rio['id'])]['joined'], '2026-10-01'), ['2026-09-30']);
eq('but someone who was here is',
   messi_missed_days([], '2026-09-28', '2026-09-30'), ['2026-09-29', '2026-09-28']);

/* --------------------------------------------------- beberapa janji sekaligus */

// Hari pertama pemakaian nyata: tiga hal gantung, tiga rencana, tanggal berbeda-beda.
$tiga = ['grid' => $goodGrid, 'detail' => 'WAG Klien A, TGG Vendor B, GCG PT Sinar',
         'declared' => true, 'plans' => [
             ['action' => 'Balas setelah Onboarding selesai', 'due' => '2026-10-01'],
             ['action' => 'Balas setelah project selesai',    'due' => '2026-10-02'],
             ['action' => 'Balas setelah pemasangan selesai', 'due' => '2026-10-05'],
         ]];
$dita = make_user('dita@example.test', 'Dita', 'player', '2026-09-28');
repo_save_cycle($dita, uid((int) $dita['id']) . '__2026-09-30', $tiga);

$janji = fn() => q('SELECT action_text, due_date, status FROM commitments
                     WHERE user_id = ? ORDER BY due_date', [$dita['id']])->fetchAll();

eq('tiga rencana jadi tiga janji', count($janji()), 3);
eq('masing-masing membawa tanggalnya sendiri',
   array_column($janji(), 'due_date'), ['2026-10-01', '2026-10-02', '2026-10-05']);

// Memperbaiki laporan: satu tanggal digeser, satu baris dibuang, satu baris ditambah.
repo_save_cycle($dita, uid((int) $dita['id']) . '__2026-09-30',
    ['grid' => $goodGrid, 'detail' => 'dua saja', 'declared' => true, 'plans' => [
        ['action' => 'Balas setelah Onboarding selesai', 'due' => '2026-10-06'],  // digeser
        ['action' => 'Balas setelah pemasangan selesai', 'due' => '2026-10-05'],  // tetap
        ['action' => 'Susul Vendor C',                   'due' => '2026-10-07'],  // baru
    ]]);
$now = $janji();
eq('tanggal yang digeser ikut berubah, bukan bikin janji baru',
   count(array_filter($now, fn($c) => $c['action_text'] === 'Balas setelah Onboarding selesai')), 1);
eq('dan tanggalnya yang baru',
   array_values(array_filter($now, fn($c) => $c['action_text'] === 'Balas setelah Onboarding selesai'))[0]['due_date'],
   '2026-10-06');
eq('baris yang dibuang dibatalkan, bukan dihapus diam-diam',
   array_values(array_filter($now, fn($c) => $c['action_text'] === 'Balas setelah project selesai'))[0]['status'],
   'cancelled');
eq('baris baru jadi janji baru',
   count(array_filter($now, fn($c) => $c['action_text'] === 'Susul Vendor C')), 1);
eq('yang tidak disentuh tetap terbuka',
   array_values(array_filter($now, fn($c) => $c['action_text'] === 'Balas setelah pemasangan selesai'))[0]['status'],
   'open');

// Janji yang sudah ditutup bukan milik laporan untuk diubah lagi.
$sudah = array_values(array_filter($janji(), fn($c) => $c['action_text'] === 'Susul Vendor C'));
$sudahId = (int) q1('SELECT id FROM commitments WHERE action_text = ?', ['Susul Vendor C'])['id'];
repo_resolve_commitment($dita, cid($sudahId), ['status' => 'kept']);
repo_save_cycle($dita, uid((int) $dita['id']) . '__2026-09-30',
    ['grid' => $goodGrid, 'detail' => 'tinggal satu', 'declared' => true, 'plans' => [
        ['action' => 'Balas setelah pemasangan selesai', 'due' => '2026-10-05'],
    ]]);
eq('janji yang sudah ditepati tidak ikut dibatalkan walau barisnya dibuang',
   q1('SELECT status FROM commitments WHERE id = ?', [$sudahId])['status'], 'kept');

// Bentuk lama tetap jalan — laporan dua minggu pertama memakainya.
$lama = make_user('lama@example.test', 'Lama', 'player', '2026-09-28');
repo_save_cycle($lama, uid((int) $lama['id']) . '__2026-09-30',
    ['grid' => $goodGrid, 'detail' => 'satu', 'plan' => 'Telepon Klien A',
     'due' => '2026-10-02', 'declared' => true]);
eq('laporan bentuk lama tetap menghasilkan satu janji',
   (int) q1('SELECT COUNT(*) n FROM commitments WHERE user_id = ?', [$lama['id']])['n'], 1);

/* ------------------------------------------------- permintaan bantuan */

// Yang diam dikejar; yang bicara harus didengar. Permintaan bantuan diumumkan saat
// ditulis, bukan ditunggu sampai ada yang kebetulan membuka layar Squad.
$minta = ['grid' => ['WAG' => ['open' => 5, 'reply' => 5]], 'declared' => true,
          'escalation' => 'Butuh approve harga Klien A'];

eq('permintaan bantuan baru diumumkan',
   repo_save_cycle($rio, uid((int) $rio['id']) . '__2026-09-30', $minta)['announce'],
   'Butuh approve harga Klien A');

eq('memperbaiki angka tidak mengumumkannya lagi',
   repo_save_cycle($rio, uid((int) $rio['id']) . '__2026-09-30',
                   ['grid' => ['WAG' => ['open' => 9, 'reply' => 9]], 'declared' => true,
                    'escalation' => 'Butuh approve harga Klien A'])['announce'],
   null);

eq('tapi mengganti isinya diumumkan lagi',
   repo_save_cycle($rio, uid((int) $rio['id']) . '__2026-09-30',
                   ['grid' => ['WAG' => ['open' => 9, 'reply' => 9]], 'declared' => true,
                    'escalation' => 'Klien A sudah oke, sekarang Vendor B yang stuck'])['announce'],
   'Klien A sudah oke, sekarang Vendor B yang stuck');

eq('laporan tanpa permintaan bantuan tidak mengumumkan apa pun',
   repo_save_cycle($nicho, uid((int) $nicho['id']) . '__2026-09-30',
                   ['grid' => $goodGrid, 'detail' => 'OA003', 'plan' => 'Telepon langsung',
                    'due' => '2026-10-01', 'declared' => true])['announce'],
   null);

ok('penandanya ikut tersimpan, tanpa perlu kolom baru',
   str_contains((string) q1('SELECT answers FROM cycles WHERE user_id = ? AND day = ?',
                            [$rio['id'], '2026-09-30'])['answers'], 'escalation_announced'));

/* ----------------------------------------------- setelan yang diubah admin */

$admin = make_user('admin@example.test', 'Ami', 'admin', '2026-09-28');

eq('sebelum diubah, yang berlaku adalah bawaannya',
   repo_config(), messi_config_default());
throws('pemain tidak bisa mengubah pertanyaan',
       fn() => repo_save_config($nicho, ['threshold_days' => 1]), 'untuk admin');
throws('leader pun tidak', fn() => repo_save_config($lead, ['threshold_days' => 1]), 'untuk admin');

repo_save_config($admin, [
    'team_name' => 'Tim Dukungan',
    'threshold_days' => 1,
    'due_hour' => 16,
    'channels' => [['key' => 'IG', 'label' => 'IG', 'full' => 'Instagram']],
    'questions' => ['detail' => ['error' => 'Tulis nomor tiketnya dulu.'],
                    'prista' => ['show' => false]],
]);
eq('yang disimpan admin langsung berlaku', repo_config()['threshold_days'], 1);
eq('tanpa perlu memuat ulang apa pun', repo_config()['team_name'], 'Tim Dukungan');
eq('dan tersimpan sebagai satu baris, bukan satu baris per kunci',
   (int) q1('SELECT COUNT(*) n FROM settings')['n'], 1);
ok('tercatat siapa yang terakhir mengubahnya',
   (int) q1('SELECT updated_by FROM settings WHERE name = ?', ['messi'])['updated_by']
     === (int) $admin['id']);

// Pertanyaan yang sudah diubah harus dipakai server, bukan cuma ditampilkan halaman.
$dita2 = make_user('dita2@example.test', 'Dita2', 'player', '2026-09-28');
throws('server memakai kalimat kesalahan yang ditulis admin',
       fn() => repo_save_cycle($dita2, uid((int) $dita2['id']) . '__2026-09-30',
           ['grid' => ['IG' => ['open' => 5, 'gt3' => 1]], 'declared' => true]),
       'Tulis nomor tiketnya dulu.');
throws('angka untuk channel yang sudah dihapus tidak dihitung sama sekali',
       fn() => repo_save_cycle($dita2, uid((int) $dita2['id']) . '__2026-09-30',
           ['grid' => ['WAG' => ['open' => 5]], 'declared' => true]),
       'Isi dulu angkanya');

// Jam tutup yang digeser menggeser arti telat, dan itu dihitung dari jam server.
Clock::freeze('2026-09-30T09:30:00Z');       // 16:30 Jakarta, lewat jam 16 yang baru
// Ditangkap, bukan dibiarkan meledak: kalau setelannya tidak berlaku, yang terlihat harus
// berupa satu kegagalan yang terbaca — bukan fatal error yang menelan sisa suite-nya.
try {
    $telat = repo_save_cycle($dita2, uid((int) $dita2['id']) . '__2026-09-30',
        ['grid' => ['IG' => ['open' => 5, 'reply' => 5]], 'declared' => true])['status'];
} catch (RepoError $e) {
    $telat = 'DITOLAK: ' . $e->getMessage();
}
eq('telat dihitung dari jam tutup yang berlaku', $telat, 'late');
Clock::freeze('2026-09-30T03:00:00Z');

// Cuplikan setelan ikut tersimpan, supaya laporan ini tetap terbaca seperti hari ini.
$simpan = json_decode((string) q1('SELECT answers FROM cycles WHERE user_id = ? AND day = ?',
                                  [$dita2['id'], '2026-09-30'])['answers'], true);
$snap = is_array($simpan['cfg'] ?? null) ? $simpan['cfg'] : [];
eq('laporan membawa channel yang berlaku saat dikirim',
   array_column($snap['channels'] ?? [], 'key'), ['IG']);
eq('dan ambangnya', $snap['threshold_days'] ?? null, 1);
eq('sehingga laporannya tetap terbaca dengan ambang itu walau setelannya berubah lagi',
   str_contains(messi_build_report(($simpan ?: []) + ['day' => '2026-09-30'], 'Dita2',
       messi_config_normalize(['threshold_days' => 30])), 'b. Gantung >1 hari:'), true);

// Laporan yang dikirim sebelum ada halaman setelan tidak punya cuplikan sama sekali.
eq('laporan lama tanpa cuplikan tetap terbaca',
   str_contains(messi_build_report(['day' => '2026-09-30', 'grid' => ['IG' => ['open' => 2]]],
       'Nicho', repo_config()), 'a. Channel aktif/open: 2 IG'), true);

repo_save_config($admin, messi_config_default());
eq('dikembalikan ke bawaan berarti benar-benar bawaan', repo_config(), messi_config_default());

/* -------------------------------------------------------------------- auth */

$_COOKIE = [];
ok('nobody is signed in to begin with', auth_user() === null);

ok('a wrong password is refused', auth_login('nicho@example.test', 'salah') === null);
ok('an unknown address is refused', auth_login('hantu@example.test', TEST_PASSWORD) === null);

ok('the right password works', auth_login('nicho@example.test', TEST_PASSWORD) !== null);
eq('and the session names the right person straight away',
   (auth_user() ?? [])['email'], 'nicho@example.test');

$token = $_COOKIE[MESSI_COOKIE];
auth_logout();
ok('signing out ends it', auth_user() === null);
$_COOKIE[MESSI_COOKIE] = $token;
Auth::$looked = false;
ok('and the old cookie is dead, not merely forgotten', auth_user() === null);

$link = auth_make_login_link((int) $rio['id'], 60);
$oneTime = substr((string) parse_url($link, PHP_URL_QUERY), 2);
$_COOKIE = [];
eq('the morning link signs the right person in',
   (auth_consume_login_link($oneTime) ?? [])['email'], 'rio@example.test');
ok('and it cannot be used a second time', auth_consume_login_link($oneTime) === null);
ok('a made-up token is refused', auth_consume_login_link(str_repeat('a', 64)) === null);

// A link that has aged out is no better than a wrong one.
$stale = random_token();
q('INSERT INTO login_tokens (token, user_id, expires_at) VALUES (?,?,?)',
  [$stale, $rio['id'], '2026-09-29 00:00:00']);
ok('an expired link is refused', auth_consume_login_link($stale) === null);

ok('a leader is a leader', is_leader($lead));
ok('a player is not', !is_leader($nicho));

/* -------------------------------------------------------------------- done */

/* ------------------------------------------- pemasangan yang belum selesai */

// The three ways a first install goes wrong must each name themselves. A blank 500 is
// the one failure the person on the other end cannot act on.
$out = shell_exec('cd ' . escapeshellarg(dirname(__DIR__))
    . ' && MESSI_CONFIG_FILE=/tidak/ada/config.php php login.php 2>&1');
ok('config.php yang belum dibuat mengatakannya sendiri',
   str_contains((string) $out, 'config.php belum dibuat'));

$bad = sys_get_temp_dir() . '/messi-bad-config.php';
file_put_contents($bad, '<?php return ' . var_export([
    'db' => ['host' => '127.0.0.1', 'socket' => '', 'name' => 'tidak_ada_database_ini',
             'user' => 'bukan_user', 'pass' => 'bukan_password'],
    'base_url' => 'https://example.test', 'chat_webhook' => '', 'cron_key' => 'x',
    'first_day' => '2026-09-28', 'session_days' => 30,
], true) . ';');
$out = shell_exec('cd ' . escapeshellarg(dirname(__DIR__))
    . ' && MESSI_CONFIG_FILE=' . escapeshellarg($bad) . ' php login.php 2>&1');
ok('database yang tidak bisa dihubungi mengatakannya sendiri',
   str_contains((string) $out, 'Database tidak bisa dihubungi'));
ok('dan menyebutkan kata MySQL-nya, supaya bisa dibedakan penyebabnya',
   str_contains((string) $out, 'SQLSTATE'));
ok('tapi tidak pernah membocorkan passwordnya',
   !str_contains((string) $out, 'bukan_password'));
unlink($bad);

// Satu tabel yang kurang. Ini pemasangan lama yang belum meng-import versi barunya —
// dan dulu ia lolos pemeriksaan kesiapan lalu mati dengan 500 kosong beberapa baris
// setelahnya, yang justru kegagalan yang halaman ini ada untuk mencegahnya.
Cfg::forget();
$root->exec('DROP TABLE settings');
try {
    $tanpaTabel = repo_config();
} catch (Throwable $e) {
    $tanpaTabel = 'MELEDAK: ' . $e->getMessage();
}
eq('tabel setelan yang hilang tidak mematikan aplikasinya, cuma kembali ke bawaannya',
   $tanpaTabel, messi_config_default());
$out = shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && MESSI_TEST_DB='
    . escapeshellarg($GLOBALS['MESSI_TEST_DBNAME'])
    . ' php tests/probe_unready.php 2>&1');
ok('dan tidak menghentikan siapa pun dari melapor',
   !str_contains((string) $out, 'Tabelnya belum dibuat'));
eq('yang kurang tetap bisa disebutkan namanya, untuk halaman yang memang perlu',
   messi_missing_tables(), ['settings']);
ok('jalan keluarnya satu kalimat yang sama di mana pun',
   str_contains(messi_import_again(), 'install.sql')
     && str_contains(messi_import_again(), 'Aman diulang'));

// Tables missing: the database is reachable but install.sql was never imported.
$root->exec('DROP TABLE IF EXISTS commitments, cycles, login_tokens, sessions, job_log, settings, users');
$out = shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && MESSI_TEST_DB='
    . escapeshellarg($GLOBALS['MESSI_TEST_DBNAME'])
    . ' php tests/probe_unready.php 2>&1');
ok('install.sql yang belum di-import mengatakannya sendiri',
   str_contains((string) $out, 'Tabelnya belum dibuat'));

Clock::unfreeze();
$root->exec('DROP DATABASE `' . $GLOBALS['MESSI_TEST_DBNAME'] . '`');
done();
