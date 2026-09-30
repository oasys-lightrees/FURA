<?php
/**
 * Says so plainly when the hosting is running an old PHP.
 *
 * Written in deliberately ancient syntax and included before anything else, because a
 * file that needs PHP 8 to be read cannot be the one that explains that PHP 8 is
 * needed. Without this, a cPanel account still set to PHP 7 shows a blank white page
 * and nothing in the browser says why.
 */

if (PHP_VERSION_ID < 80000) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8">'
       . '<title>Versi PHP terlalu lama</title>'
       . '<div style="max-width:32rem;margin:4rem auto;padding:0 1.5rem;'
       . 'font:400 16px/1.6 system-ui,sans-serif;color:#1a1c1f">'
       . '<h1 style="font-size:1.25rem">Versi PHP-nya terlalu lama</h1>'
       . '<p>MESSI butuh <strong>PHP 8.0</strong> ke atas. Hosting ini sedang memakai '
       . '<strong>' . htmlspecialchars(PHP_VERSION) . '</strong>.</p>'
       . '<p>Cara gantinya di cPanel: <em>Software</em> &rarr; <em>Select PHP Version</em> '
       . '(atau <em>MultiPHP Manager</em>) &rarr; pilih 8.1 atau 8.2 &rarr; <em>Set as current</em>. '
       . 'Perubahannya langsung berlaku, tidak perlu upload ulang apa pun.</p>'
       . '</div>';
    exit(1);
}
