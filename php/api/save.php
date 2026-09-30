<?php
/**
 * One document at a time, the shape the page already sends.
 *
 * The page posts whole documents; nothing here trusts them. Which collection, whose row
 * and which day are all decided on this side.
 */

declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require dirname(__DIR__) . '/lib/require-php8.php';

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/repo.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_err('POST saja.', 405);
}
// A cross-site form cannot set this header, and the session cookie is SameSite=Lax, so
// between them a request from someone else's page cannot write anything here.
if (($_SERVER['HTTP_X_MESSI'] ?? '') !== '1') {
    json_err('Permintaan tidak dikenal.', 403);
}

$user = require_login_json();
$in = json_in();
$collection = (string) ($in['collection'] ?? '');
$id  = (string) ($in['id'] ?? '');
$doc = is_array($in['doc'] ?? null) ? $in['doc'] : [];

try {
    switch ($collection) {
        case 'cycles':
            $result = repo_save_cycle($user, $id, $doc);
            break;
        case 'commitments':
            $result = repo_resolve_commitment($user, $id, $doc);
            break;
        case 'roster':
            $result = repo_save_roster($user, $id, $doc);
            break;
        default:
            json_err('Koleksi tidak dikenal.', 400);
    }
} catch (RepoError $e) {
    json_err($e->getMessage(), 422);
}

json_out(['ok' => true, 'result' => $result]);
