<?php
/** Everything the page needs on load, in one request. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/repo.php';

$user = require_login_json();

json_out([
    'ok' => true,
    'me' => [
        'id'       => uid((int) $user['id']),
        'name'     => $user['name'],
        'isLeader' => is_leader($user),
        'joined'   => $user['joined_on'],
    ],
    'today'       => Clock::today(),
    'cycles'      => repo_cycles(),
    'commitments' => repo_commitments(),
    'roster'      => repo_roster(),
]);
