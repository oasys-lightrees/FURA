<?php
/** Everything the page needs on load, in one request. */

declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require dirname(__DIR__) . '/lib/require-php8.php';

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/repo.php';

// Berkas aplikasi bisa lebih baru daripada databasenya; jawabannya satu kalimat yang
// bisa ditindaklanjuti, bukan 500 kosong yang terbaca sebagai "laporan saya hilang".
messi_require_current();

$user = require_login_json();

// Only a leader has a screen that shows other people's reports.
$mine = is_leader($user) ? null : (int) $user['id'];
// Leader membaca timnya sendiri; admin dan owner membaca semuanya. Ini bukan penghematan
// permintaan — nama dan laporan orang di tim lain bukan milik seorang leader untuk dibaca.
$team = is_manager($user) ? null : repo_team_of($user);
$myTeam = repo_team_of($user);

json_out([
    'ok' => true,
    'config' => messi_config_public(repo_config($myTeam)),
    'teams'  => array_values(array_filter(
        is_manager($user) ? repo_teams() : [$myTeam => (repo_teams()[$myTeam] ?? null)])),
    'me' => [
        'id'       => uid((int) $user['id']),
        'name'     => $user['name'],
        'isLeader' => is_leader($user),
        'team'     => $myTeam,
        'joined'   => $user['joined_on'],
    ],
    'today'       => Clock::today(),
    'cycles'      => repo_cycles(90, $mine, $team),
    'commitments' => repo_commitments(90, $mine, $team),
    'roster'      => repo_roster($team),
]);
