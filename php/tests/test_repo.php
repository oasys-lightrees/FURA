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
$chief = make_user('chief@example.test', 'Chief', 'leader', '2026-09-28');

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
       fn() => repo_save_cycle($nicho, uid((int) $chief['id']) . '__2026-09-30', $clean),
       'diri sendiri');

throws('nobody backfills a day they let pass',
       fn() => repo_save_cycle($nicho, uid((int) $nicho['id']) . '__2026-09-29', $clean),
       'hari ini');

throws('the server checks the arithmetic too, whatever the page allowed',
       fn() => repo_save_cycle($chief, uid((int) $chief['id']) . '__2026-09-30',
                               ['grid' => ['WAG' => ['open' => 1, 'gt3' => 4]], 'declared' => true]),
       'lebih banyak');

throws('a promise dated in the past is refused',
       fn() => repo_save_cycle($chief, uid((int) $chief['id']) . '__2026-09-30',
                               ['grid' => $goodGrid, 'detail' => 'x', 'plan' => 'y',
                                'due' => '2026-09-01', 'declared' => true]),
       'sudah lewat');

// After six, the same report is late — and the server's clock is the one that says so.
Clock::freeze('2026-09-30T11:30:00Z');       // 18:30 Jakarta
eq('after 18:00 the same report is late',
   repo_save_cycle($chief, uid((int) $chief['id']) . '__2026-09-30', $clean)['status'], 'late');
Clock::freeze('2026-09-30T03:00:00Z');

/* ------------------------------------------------------------- commitments */

$promise = q1('SELECT * FROM commitments WHERE user_id = ?', [$nicho['id']]);
$pid = cid((int) $promise['id']);

throws('a promise that is not yours is not yours to close',
       fn() => repo_resolve_commitment($chief, $pid, ['status' => 'kept']),
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
  [$chief['id'], 'Tinjau OA001', '2026-09-29', 'open', Clock::nowUtcSql()]);
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

ok('a leader is a leader', is_leader($chief));
ok('a player is not', !is_leader($nicho));

/* -------------------------------------------------------------------- done */

Clock::unfreeze();
$root->exec('DROP DATABASE `' . $GLOBALS['MESSI_TEST_DBNAME'] . '`');
done();
