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

// Kunci uniknya sekarang (user_id, day, module_id), dan MySQL menganggap dua NULL
// sebagai dua nilai berbeda — laporan tanpa modul membuat ON DUPLICATE KEY tidak pernah
// menyala, dan satu orang bisa punya dua laporan untuk hari yang sama tanpa satu pun
// pesan kesalahan.
eq('tiap laporan menunjuk modul yang dilaporkannya',
   (int) q1('SELECT COUNT(*) n FROM cycles WHERE day = ? AND module_id IS NULL',
            ['2026-09-30'])['n'], 0);
eq('membuat laporan hari yang sama dua kali tetap satu baris',
   repo_ensure_cycle((int) $nicho['id'], '2026-09-30'),
   repo_ensure_cycle((int) $nicho['id'], '2026-09-30'));

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
$tim = repo_default_team();

eq('sebelum diubah, yang berlaku adalah bawaannya',
   repo_config(), messi_config_default());
throws('pemain tidak bisa mengubah pertanyaan',
       fn() => repo_save_config($nicho, $tim, ['threshold_days' => 1]), 'untuk admin');
throws('leader pun tidak', fn() => repo_save_config($lead, $tim, ['threshold_days' => 1]), 'untuk admin');

repo_save_config($admin, $tim, [
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

repo_save_config($admin, $tim, messi_config_default());
eq('dikembalikan ke bawaan berarti benar-benar bawaan', repo_config(), messi_config_default());

/* --------------------------------- satu kebenaran: Pertanyaan dan modul MESSI */

// Dua tempat yang menyimpan satu hal yang sama akan berbeda, dan yang berbeda tidak
// kelihatan berbeda: halaman Pertanyaan berkata ambangnya 1 hari sementara modulnya tetap
// berbunyi "Gantung >3 hari". Tidak ada pesan kesalahan — cuma dua jawaban untuk satu
// pertanyaan, dan tidak ada cara tahu yang mana yang dipercaya.
require_once __DIR__ . '/../lib/katalog.php';
repo_module_id($tim);                                   // memastikan MESSI sudah disemai
$kolom = fn() => array_column(katalog_by_code($tim, MODUL_MESSI)['spec']['fields'][0]['cols'],
                              'label');
eq('bawaannya, modul MESSI berbunyi seperti ambang bawaannya',
   in_array('Gantung >3 hari', $kolom(), true), true);

repo_save_config($admin, $tim, ['threshold_days' => 1, 'due_hour' => 16]);
eq('ambang yang diubah di Pertanyaan langsung terbaca di modulnya',
   in_array('Gantung >1 hari', $kolom(), true), true);
eq('dan yang lama tidak tertinggal di sana',
   in_array('Gantung >3 hari', $kolom(), true), false);
eq('jamnya ikut', katalog_by_code($tim, MODUL_MESSI)['spec']['due_hour'], 16);

// Kalau bentuknya bisa diubah di dua tempat, perbedaannya kembali besok.
throws('modul MESSI tidak bisa disusun dari halaman Modul',
       fn() => katalog_save($admin, $tim, (int) katalog_by_code($tim, MODUL_MESSI)['id'],
                            modul_default('MESSI', 'Bukan MESSI')),
       'halaman Pertanyaan');

// Tanpa MESSI, repo_module_id() mengembalikan null, laporan tersimpan tanpa modul, dan
// kunci unik (user_id, day, module_id) berhenti menahan apa pun karena MySQL menganggap
// dua NULL sebagai dua nilai berbeda.
$idMessi = (int) katalog_by_code($tim, MODUL_MESSI)['id'];
throws('MESSI tidak bisa dihapus', fn() => katalog_delete($admin, $tim, $idMessi),
       'tidak bisa dimatikan atau dihapus');
throws('dan tidak bisa dimatikan',
       fn() => katalog_set_active($admin, $tim, $idMessi, false),
       'tidak bisa dimatikan atau dihapus');
eq('sehingga tiap laporan harian selalu punya modulnya', repo_module_id($tim), $idMessi);

repo_save_config($admin, $tim, messi_config_default());

/* ------------------------------------------------------------------ dua tim */

$timHr = repo_add_team($admin, 'HR');
$ani = make_user('ani@example.test', 'Ani', 'player', '2026-09-28', $timHr);
$bosHr = make_user('boshr@example.test', 'Bos HR', 'leader', '2026-09-28', $timHr);
repo_save_cycle($ani, uid((int) $ani['id']) . '__2026-09-30',
    ['grid' => ['WAG' => ['open' => 3, 'reply' => 3]], 'declared' => true]);

// Inti dari tim: laporan tim lain bukan milik seorang leader untuk dibaca.
$punyaHr = repo_cycles(90, null, $timHr);
eq('leader HR cuma menerima laporan timnya',
   array_values(array_unique(array_map(fn($c) => $c['owner'], $punyaHr))),
   [uid((int) $ani['id'])]);
ok('dan tidak satu pun laporan tim lain ikut terkirim',
   !array_filter($punyaHr, fn($c) => $c['owner'] === uid((int) $nicho['id'])));
ok('owner menerima dua-duanya, karena memang layarnya',
   count(repo_cycles(90, null, null)) > count($punyaHr));

eq('daftar orang pun dibatasi ke timnya',
   array_keys(repo_roster($timHr)),
   [uid((int) $ani['id']), uid((int) $bosHr['id'])]);
ok('janji tim lain juga tidak ikut',
   !array_filter(repo_commitments(90, null, $timHr),
                 fn($c) => $c['owner'] === uid((int) $nicho['id'])));

// Pindah tim tidak boleh menulis ulang rekap kemarin.
q('UPDATE users SET team_id = ? WHERE id = ?', [$timSales ?? repo_default_team(), $ani['id']]);
eq('laporan yang sudah dikirim tetap tercatat di tim lamanya',
   count(repo_cycles(90, null, $timHr)), 1);
eq('dan tidak ikut muncul di tim barunya',
   count(array_filter(repo_cycles(90, null, repo_default_team()),
                      fn($c) => $c['owner'] === uid((int) $ani['id']))), 0);

// Setelan milik tim, bukan milik perusahaan.
repo_save_config($admin, $timHr, ['threshold_days' => 7]);
eq('ambang tim HR berlaku untuk HR', repo_config($timHr)['threshold_days'], 7);
eq('dan tidak merembet ke tim lain',
   repo_config(repo_default_team())['threshold_days'], MESSI_THRESHOLD);

/* ------------------------------------------------------- undangan dan percobaan */

// Akun yang baru diundang belum punya password, jadi belum bisa dipakai siapa pun —
// termasuk yang menebak dengan password kosong.
q('INSERT INTO users (email, name, password_hash, role, team_id, joined_on, created_at)
   VALUES (?,?,?,?,?,?,?)',
  ['baru@example.test', 'Baru', '', 'player', repo_default_team(), '2026-09-28',
   Clock::nowUtcSql()]);
$baruId = (int) db()->lastInsertId();
ok('yang diundang belum bisa masuk dengan password apa pun',
   auth_login('baru@example.test', '') === null && auth_login('baru@example.test', 'apa saja') === null);
ok('dan belum dihitung sebagai orang yang harus lapor',
   !in_array(uid($baruId), array_keys(repo_roster()), true));

$undangan = auth_make_invite($baruId, 72);
$tokenBaru = substr((string) parse_url($undangan, PHP_URL_QUERY), 2);
eq('undangannya menunjuk halaman pembuatan password',
   str_contains($undangan, 'undang.php?t='), true);
eq('dan sebelum ditebus, orangnya sudah bisa dikenali',
   (auth_peek_invite($tokenBaru) ?? [])['email'], 'baru@example.test');
$_COOKIE = [];
Auth::$looked = false;
ok('menebusnya memberi password dan langsung memasukkan orangnya',
   (auth_accept_invite($tokenBaru, 'password-yang-panjang') ?? [])['id'] === $baruId);
ok('sekali pakai: tidak bisa ditebus lagi',
   auth_accept_invite($tokenBaru, 'password-lain-lagi') === null);
ok('sesudahnya dia bisa masuk dengan passwordnya sendiri',
   auth_login('baru@example.test', 'password-yang-panjang') !== null);
ok('dan sekarang ikut terhitung di rekap',
   in_array(uid($baruId), array_keys(repo_roster()), true));

// Undangan yang kedaluwarsa tidak lebih baik daripada undangan palsu.
$basi = random_token();
q('INSERT INTO login_tokens (token, user_id, kind, expires_at) VALUES (?,?,?,?)',
  [$basi, $baruId, 'invite', '2026-09-29 00:00:00']);
ok('undangan kedaluwarsa ditolak', auth_accept_invite($basi, 'password-yang-panjang') === null);
// Token undangan bukan token link masuk, dan sebaliknya: dua pintu yang berbeda.
$masuk = auth_make_login_link($baruId, 60);
$tokenMasuk = substr((string) parse_url($masuk, PHP_URL_QUERY), 2);
ok('token link masuk tidak bisa dipakai sebagai undangan',
   auth_accept_invite($tokenMasuk, 'password-yang-panjang') === null);

/* --------------------------------------------------- percobaan masuk yang gagal */

$ipA = '203.0.113.9';
$ipB = '198.51.100.4';
q('DELETE FROM login_attempts');
ok('awalnya tidak ada yang ditahan', !auth_throttled('nicho@example.test', $ipA));
for ($i = 0; $i < MESSI_TRY_PAIR; $i++) {
    auth_note_failure('nicho@example.test', $ipA);
}
ok('setelah sekian kali gagal, percobaan dari alamat itu ditahan',
   auth_throttled('nicho@example.test', $ipA));
// Dihitung per pasangan (email, IP). Kalau per email saja, siapa pun yang tahu alamat
// email seseorang bisa mengunci orang itu di luar dengan sengaja salah berkali-kali.
ok('orang yang sama dari perangkat lain tidak ikut terkunci',
   !auth_throttled('nicho@example.test', $ipB));
ok('dan email lain dari perangkat yang sama masih boleh mencoba',
   !auth_throttled('dita@example.test', $ipA));

for ($i = 0; $i < MESSI_TRY_IP; $i++) {
    auth_note_failure('acak' . $i . '@example.test', $ipB);
}
ok('tapi satu alamat yang mencoba banyak email tetap ditahan',
   auth_throttled('siapa-saja@example.test', $ipB));

// Yang lama tidak boleh ikut menahan selamanya.
q('UPDATE login_attempts SET at = ?', ['2026-09-29 00:00:00']);
ok('percobaan di luar jendelanya tidak lagi menahan',
   !auth_throttled('nicho@example.test', $ipA));
ok('dan disapu oleh cron', auth_sweep() > 0);

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

/* ------------------------------------------------- ganti password sendiri */

$_COOKIE = [];
Auth::$looked = false;
$ganti = auth_login('dita2@example.test', TEST_PASSWORD);
ok('dia masuk dulu', $ganti !== null);
// Sesi di perangkat lain, dibuat sebelum passwordnya berganti.
$lamaToken = random_token();
q('INSERT INTO sessions (token, user_id, expires_at, created_at) VALUES (?,?,?,?)',
  [$lamaToken, $ganti['id'], '2026-12-31 00:00:00', Clock::nowUtcSql()]);

eq('password sekarang yang salah ditolak',
   auth_change_password($ganti, 'bukan-ini', 'rahasia-baru-sekali', 'rahasia-baru-sekali'),
   'Password sekarang salah.');
eq('password baru yang kependekan ditolak',
   auth_change_password($ganti, TEST_PASSWORD, 'pendek', 'pendek'),
   'Password baru minimal 10 karakter.');
eq('dua kotak yang berbeda ditolak',
   auth_change_password($ganti, TEST_PASSWORD, 'rahasia-baru-sekali', 'rahasia-baru-dua'),
   'Dua kotak password baru belum sama.');
eq('dan password yang itu-itu juga ditolak',
   auth_change_password($ganti, TEST_PASSWORD, TEST_PASSWORD, TEST_PASSWORD),
   'Password barunya sama dengan yang sekarang.');
eq('sampai sini password lamanya masih berlaku',
   auth_login('dita2@example.test', TEST_PASSWORD) !== null, true);

eq('yang benar diterima',
   auth_change_password(auth_user() ?? $ganti, TEST_PASSWORD,
                        'rahasia-baru-sekali', 'rahasia-baru-sekali'), null);
ok('password lamanya mati', auth_login('dita2@example.test', TEST_PASSWORD) === null);
$_COOKIE = [];
Auth::$looked = false;
ok('password barunya hidup',
   auth_login('dita2@example.test', 'rahasia-baru-sekali') !== null);
// Password baru yang tidak menutup pintu lama bukan password baru: sesi berumur 30 hari
// dan tidak peduli password-nya sudah bukan yang itu lagi.
eq('dan sesi di perangkat lain ikut mati',
   (int) q1('SELECT COUNT(*) AS n FROM sessions WHERE token = ?', [$lamaToken])['n'], 0);

/* ------------------------------------------ janji yang jatuh di hari libur */

// Sab 3 Okt: hari yang tidak punya jam kerja sama sekali. Dulu janji seperti ini ditandai
// tidak ditepati Minggu pagi — sebelum orangnya pernah punya satu hari kerja untuk
// menyelesaikannya, dan tanpa satu pun cara membantahnya.
$janji = make_user('janji@example.test', 'Janji', 'player', '2026-09-01');
q('INSERT INTO commitments (user_id, action_text, due_date, status, created_at)
   VALUES (?,?,?,?,?)',
  [$janji['id'], 'Kirim berkas', '2026-10-03', 'open', Clock::nowUtcSql()]);
$status = fn() => q1('SELECT status FROM commitments WHERE user_id = ?',
                     [$janji['id']])['status'];

// Yang diperiksa statusnya, bukan jumlah yang dikembalikan: suite ini sudah punya janji
// lain yang memang sudah lewat, dan angka yang ikut menghitung mereka tidak mengatakan
// apa pun tentang janji yang satu ini.
repo_break_overdue('2026-10-04');                  // Minggu
eq('hari Minggu, janji Sabtu belum ingkar', $status(), 'open');
repo_break_overdue('2026-10-05');                  // Senin
eq('hari Senin pun belum — hari kerjanya baru hari itu', $status(), 'open');
repo_break_overdue('2026-10-06');                  // Selasa
eq('hari Selasa baru ditandai tidak ditepati', $status(), 'broken');

/* ------------------------------------------------------------------- izin */

$_COOKIE = [];
Auth::$looked = false;
$cuti = make_user('cuti@example.test', 'Cuti', 'player', '2026-09-01');

throws('pemain tidak bisa menandai dirinya sendiri izin',
       fn() => repo_set_excused($nicho, (int) $cuti['id'], '2026-09-29', '2026-09-29', true),
       'admin dan owner');
// Untuk sekarang leader pun tidak: satu pintu yang jelas lebih mudah dipercaya daripada
// dua yang batasnya kabur, dan izin yang bisa diberikan atasan langsung paling cepat
// berubah jadi "tolong hapus merah saya".
throws('dan leader pun belum bisa',
       fn() => repo_set_excused($lead, (int) $cuti['id'], '2026-09-29', '2026-09-29', true),
       'admin dan owner');
throws('tanggal yang tidak ada ditolak',
       fn() => repo_set_excused($admin, (int) $cuti['id'], '2026-02-31', '2026-02-31', true),
       'Tanggalnya belum lengkap');
throws('rentang terbalik ditolak',
       fn() => repo_set_excused($admin, (int) $cuti['id'], '2026-09-30', '2026-09-28', true),
       'sebelum tanggal mulai');
throws('rentang yang kelewat panjang ditolak',
       fn() => repo_set_excused($admin, (int) $cuti['id'], '2026-09-01', '2027-09-01', true),
       'Rentangnya lebih dari');

// Sen 28 Sep sampai Jum 2 Okt: lima hari kerja, dua hari akhir pekan di luarnya.
eq('seminggu cuti jadi lima hari kerja, bukan tujuh',
   repo_set_excused($admin, (int) $cuti['id'], '2026-09-28', '2026-10-04', true, 'cuti tahunan'),
   5);
eq('akhir pekan tidak ikut ditandai',
   (int) q1("SELECT COUNT(*) AS n FROM cycles WHERE user_id = ? AND status = 'excused'
              AND day IN ('2026-10-03','2026-10-04')", [$cuti['id']])['n'], 0);
eq('menandainya lagi tidak menandai apa pun dua kali',
   repo_set_excused($admin, (int) $cuti['id'], '2026-09-28', '2026-10-02', true), 0);

// Inilah seluruh gunanya: hari yang lewat tanpa laporan tidak lagi jadi tuduhan.
repo_reap('2026-09-30');
eq('hari izin tidak ikut tersapu jadi tidak lapor',
   q1("SELECT status FROM cycles WHERE user_id = ? AND day = '2026-09-28'",
      [$cuti['id']])['status'], 'excused');
$lihat = repo_cycles(90, (int) $cuti['id']);
eq('dan halamannya menerimanya sebagai izin, bukan sebagai laporan kosong',
   $lihat[uid((int) $cuti['id']) . '__2026-09-28']['excused'] ?? null, true);
eq('lengkap dengan keterangannya',
   $lihat[uid((int) $cuti['id']) . '__2026-09-28']['izin']['note'] ?? null, 'cuti tahunan');

$daftar = repo_excused(null, 365, 365);
eq('daftarnya menyebut lima harinya', count(array_filter($daftar,
   fn($r) => $r['user_id'] === (int) $cuti['id'])), 5);
eq('beserta siapa yang menandainya',
   $daftar[0]['by'] ?? 0, (int) $admin['id']);

// Laporan yang sungguhan selalu menang: menimpanya dengan izin berarti laporan itu
// hilang dari rekap, dan tidak ada yang akan tahu kenapa.
eq('hari yang laporannya sudah masuk tidak ikut ditandai izin',
   repo_set_excused($admin, (int) $nicho['id'], '2026-09-30', '2026-09-30', true), 0);
eq('dan statusnya tidak tersentuh',
   q1("SELECT status FROM cycles WHERE user_id = ? AND day = '2026-09-30'",
      [$nicho['id']])['status'], 'submitted');

// Dibatalkan: hari yang sudah lewat kembali jadi tidak lapor, bukan "belum lapor
// selamanya" — penyapu cuma menyentuh hari sebelum hari ini, dan hari itu sudah lewat.
eq('dibatalkan mengembalikan tiga harinya',
   repo_set_excused($admin, (int) $cuti['id'], '2026-09-28', '2026-09-30', false), 3);
eq('hari yang sudah lewat kembali tercatat tidak lapor',
   q1("SELECT status FROM cycles WHERE user_id = ? AND day = '2026-09-28'",
      [$cuti['id']])['status'], 'missed');
eq('hari ini kembali menunggu diisi',
   q1("SELECT status FROM cycles WHERE user_id = ? AND day = '2026-09-30'",
      [$cuti['id']])['status'], 'pending');
eq('dan yang di luar rentang pembatalan tetap izin',
   q1("SELECT status FROM cycles WHERE user_id = ? AND day = '2026-10-01'",
      [$cuti['id']])['status'], 'excused');

// Admin menandai tim mana pun, termasuk tim yang bukan timnya sendiri.
$timLain = repo_add_team($admin, 'Tim Lain');
q('UPDATE users SET team_id = ? WHERE id = ?', [$timLain, $cuti['id']]);
eq('admin bisa menandai orang di tim mana pun',
   repo_set_excused($admin, (int) $cuti['id'], '2026-10-05', '2026-10-05', true), 1);
throws('orang yang tidak ada ditolak, bukan diam-diam tidak mengerjakan apa-apa',
       fn() => repo_set_excused($admin, 999999, '2026-10-05', '2026-10-05', true),
       'tidak ada');

/* ------------------------------------------------------------------- email */

require_once __DIR__ . '/../lib/mail.php';

// Pemasangan tanpa mail_from tidak boleh menjanjikan apa pun: email yang dijanjikan lalu
// tidak datang lebih buruk daripada mengatakan terus terang bahwa yang menolong admin.
ok('tanpa mail_from, pemasangan ini tidak mengirim email', !mail_enabled());
eq('dan tidak ada yang berangkat', mail_send('a@example.test', 'A', 'Judul', 'Isi'), false);

// Baris baru di dalam header adalah cara menyelipkan header lain — satu "\nBcc: ..."
// di nama pengirim sudah cukup untuk mengirim salinan tiap undangan ke mana pun.
eq('baris baru di nama pengirim tidak bisa menyelipkan header',
   mail_header_text("FURA\nBcc: diam@example.test"), 'FURA Bcc: diam@example.test');
ok('dan nama ber-aksen dibungkus, bukan dikirim mentah',
   str_starts_with(mail_header_text('Tim Café'), '=?UTF-8?B?'));

$kotak = [];
Mail::$send = function (string $to, string $name, string $subject, string $body) use (&$kotak): bool {
    $kotak[] = compact('to', 'name', 'subject', 'body');
    return true;
};
ok('dengan celahnya terpasang, email bisa dikirim', mail_enabled());

$undangUrl = 'https://example.test/messi/undang.php?t=' . str_repeat('a', 64);
ok('undangan terkirim', mail_invite($cuti, $undangUrl, true));
eq('ke alamat orangnya', $kotak[0]['to'], 'cuti@example.test');
ok('menyebut namanya', str_contains($kotak[0]['body'], 'Cuti'));
ok('membawa linknya utuh', str_contains($kotak[0]['body'], $undangUrl));
ok('dan mengatakan link itu sekali pakai', str_contains($kotak[0]['body'], 'sekali pakai'));

$kotak = [];
mail_invite($cuti, $undangUrl, false);
ok('yang sudah lama memakainya tidak dikirimi paragraf perkenalan',
   !str_contains($kotak[0]['body'], 'didaftarkan di FURA'));
ok('dan judulnya tentang password, bukan tentang undangan',
   str_contains($kotak[0]['subject'], 'Password baru'));

// Yang dikembalikan sengaja tidak membedakan "tidak terdaftar" dari "gagal terkirim":
// halaman yang menjawab berbeda untuk email yang terdaftar dan yang tidak adalah daftar
// nama siapa saja yang bekerja di sini.
$kotak = [];
ok('alamat yang tidak terdaftar tidak mengirim apa pun ke siapa pun',
   auth_mail_reset('hantu@example.test') === false && $kotak === []);

$belum = make_user('belum@example.test', 'Belum', 'player', '2026-09-28');
q('UPDATE users SET accepted_at = NULL WHERE id = ?', [$belum['id']]);
$kotak = [];
ok('yang belum pernah membuat password tidak dikirimi link masuk — dia menunggu undangan',
   auth_mail_reset('belum@example.test') === false && $kotak === []);

$kotak = [];
ok('yang terdaftar menerima link masuknya', auth_mail_reset('nicho@example.test'));
eq('ke alamatnya sendiri', $kotak[0]['to'], 'nicho@example.test');
ok('berisi link sekali pakai ke halaman masuk',
   str_contains($kotak[0]['body'], 'login.php?t='));
ok('dan menyebutkan berapa lama berlakunya', str_contains($kotak[0]['body'], '60 menit'));

// Link yang dikirim benar-benar bisa dipakai, bukan sekadar teks yang bentuknya mirip.
preg_match('~login\.php\?t=([a-f0-9]{64})~', $kotak[0]['body'], $cocok);
$_COOKIE = [];
Auth::$looked = false;
eq('dan link itu memang memasukkan orang yang tepat',
   (auth_consume_login_link($cocok[1] ?? '') ?? [])['email'], 'nicho@example.test');

// Dia sudah masuk, jadi "saya lupa passwordnya" sudah selesai — siapa pun yang
// menyelesaikannya. Dulu yang menghapusnya cuma admin yang menekan tombol.
auth_ask_reset('nicho@example.test');
ok('permintaan lupa password tercatat', q1('SELECT reset_asked_at FROM users WHERE id = ?',
   [$nicho['id']])['reset_asked_at'] !== null);
$_COOKIE = [];
Auth::$looked = false;
auth_login('nicho@example.test', TEST_PASSWORD);
eq('dan hilang sendiri begitu dia berhasil masuk',
   q1('SELECT reset_asked_at FROM users WHERE id = ?', [$nicho['id']])['reset_asked_at'], null);

Mail::$send = null;

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
$root->exec('DROP TABLE IF EXISTS commitments, cycles, modules, login_tokens, sessions, job_log, settings, users');
$out = shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && MESSI_TEST_DB='
    . escapeshellarg($GLOBALS['MESSI_TEST_DBNAME'])
    . ' php tests/probe_unready.php 2>&1');
ok('install.sql yang belum di-import mengatakannya sendiri',
   str_contains((string) $out, 'Tabelnya belum dibuat'));

Clock::unfreeze();
$root->exec('DROP DATABASE `' . $GLOBALS['MESSI_TEST_DBNAME'] . '`');
done();
