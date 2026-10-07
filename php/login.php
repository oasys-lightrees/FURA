<?php
/**
 * The door. A password, or a one-time link an admin hands out privately.
 */

declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require __DIR__ . '/lib/require-php8.php';

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

// Says what is missing on the first screen, not on the first click.
messi_require_ready();

$error = null;

// Arriving on a one-time link: spend the token and go straight in.
if (isset($_GET['t'])) {
    if (auth_consume_login_link((string) $_GET['t'])) {
        header('Location: index.php');
        exit;
    }
    $error = 'Link-nya sudah dipakai atau kedaluwarsa. Masuk dengan password, '
           . 'atau minta link baru ke admin.';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = (string) ($_POST['email'] ?? '');
    if (auth_throttled($email)) {
        // Kalimatnya boleh berbeda: yang diberitahukan adalah tentang alamat IP ini,
        // bukan tentang ada atau tidaknya akun dengan email itu.
        $error = 'Terlalu banyak percobaan dari perangkat ini. Coba lagi beberapa menit lagi.';
    } elseif (auth_login($email, (string) ($_POST['password'] ?? ''))) {
        header('Location: index.php');
        exit;
    } else {
        // One message for both cases, so this page cannot be used to find out who has an
        // account here.
        $error = 'Email atau password-nya salah.';
    }
} elseif (!$error && auth_user()) {
    header('Location: index.php');
    exit;
}

page_head('Masuk', ['center' => true]);
?>
<div class="card">
  <h1>FURA</h1>
  <p class="sub">Follow Up Report Automation</p>
  <?php if ($error): ?><p class="note bad"><?= h($error) ?></p><?php endif; ?>
  <form method="post" autocomplete="on">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" required autofocus autocomplete="username"
           value="<?= h((string) ($_POST['email'] ?? '')) ?>">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required autocomplete="current-password">
    <p style="margin:1.25rem 0 0"><button type="submit">Masuk</button></p>
  </form>
  <p class="why" style="margin:1.25rem 0 0">Sekali masuk, kamu tetap masuk selama 30 hari —
     jadi password ini jarang dipakai.
     <a href="lupa.php">Lupa password?</a></p>
</div>
<?php
page_foot();
