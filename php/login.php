<?php
/**
 * The door. A password, or the one-time link the bot sends at 09:00.
 */

declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require __DIR__ . '/lib/require-php8.php';

require_once __DIR__ . '/lib/auth.php';

// Says what is missing on the first screen, not on the first click.
messi_require_ready();

$error = null;

// Arriving on a one-time link: spend the token and go straight in.
if (isset($_GET['t'])) {
    if (auth_consume_login_link((string) $_GET['t'])) {
        header('Location: index.php');
        exit;
    }
    $error = 'Link-nya sudah dipakai atau kedaluwarsa. Masuk dengan password, atau minta link baru ke bot.';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (auth_login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        header('Location: index.php');
        exit;
    }
    // One message for both cases, so this page cannot be used to find out who has an
    // account here.
    $error = 'Email atau password-nya salah.';
} elseif (!$error && auth_user()) {
    header('Location: index.php');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#f5f3ef">
<title>Masuk · FURA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap">
<style>
:root { color-scheme: light; }
* { box-sizing: border-box; }
body { margin:0; min-height:100vh; display:grid; place-items:center; padding:1.5rem;
       background:#f5f3ef; color:#1a1c1f;
       font:400 1rem/1.55 "Public Sans", system-ui, -apple-system, sans-serif; }
.card { width:min(23rem, 100%); background:#fff; border:1px solid #e4e1db; border-radius:0.75rem;
        padding:2rem 1.75rem; }
h1 { margin:0 0 0.25rem; font-size:1.25rem; letter-spacing:-0.01em; }
p.sub { margin:0 0 1.5rem; color:#6b6d73; font-size:0.875rem; }
label { display:block; margin:0 0 0.375rem; font-size:0.8125rem; font-weight:500; }
input { width:100%; padding:0.625rem 0.75rem; margin:0 0 1rem; font:inherit; color:inherit;
        background:#faf9f6; border:1px solid #d9d5ce; border-radius:0.5rem; }
input:focus { outline:2px solid #1a1c1f; outline-offset:1px; }
button { width:100%; padding:0.6875rem; font:inherit; font-weight:600; color:#fff;
         background:#1a1c1f; border:0; border-radius:0.5rem; cursor:pointer; }
.err { margin:0 0 1rem; padding:0.625rem 0.75rem; border-radius:0.5rem;
       background:#f7e7e4; color:#97322a; font-size:0.875rem; }
.hint { margin:1.25rem 0 0; color:#6b6d73; font-size:0.8125rem; }
</style>
</head>
<body>
<main class="card">
  <h1>FURA</h1>
  <p class="sub">Follow Up Report Automation</p>
  <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
  <form method="post" autocomplete="on">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" required autofocus autocomplete="username"
           value="<?= h((string) ($_POST['email'] ?? '')) ?>">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required autocomplete="current-password">
    <button type="submit">Masuk</button>
  </form>
  <p class="hint">Sekali masuk, kamu tetap masuk selama 30 hari — jadi password ini
     jarang dipakai. Lupa? Minta admin kirim link masuk.</p>
</main>
</body>
</html>
