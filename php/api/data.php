<?php
/** Everything the page needs on load, in one request. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/repo.php';

$user = require_login_json();

// Only a leader has a screen that shows other people's reports.
$mine = is_leader($user) ? null : (int) $user['id'];

json_out([
    'ok' => true,
    'me' => [
        'id'       => uid((int) $user['id']),
        'name'     => $user['name'],
        'isLeader' => is_leader($user),
        'joined'   => $user['joined_on'],
    ],
    'today'       => Clock::today(),
    'cycles'      => repo_cycles(90, $mine),
    'commitments' => repo_commitments(90, $mine),
    'roster'      => repo_roster(),
]);
