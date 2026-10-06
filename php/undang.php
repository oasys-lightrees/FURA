<?php
/**
 * Menerima undangan: orangnya memilih passwordnya sendiri.
 *
 * Dulu admin yang mengetikkan password lalu mengirimkannya — yang berarti atasan tahu
 * password bawahannya, dan password itu pernah lewat chat. Halaman ini menghapus
 * dua-duanya: admin cuma memegang link, dan link itu hangus begitu dipakai.
 */

declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require __DIR__ . '/lib/require-php8.php';

require_once __DIR__ . '/lib/auth.php';

messi_require_ready();

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$who = auth_peek_invite($token);
$error = null;

if ($who && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $pass = (string) ($_POST['password'] ?? '');
    $again = (string) ($_POST['password2'] ?? '');
    if (strlen($pass) < 10) {
        $error = 'Password minimal 10 karakter.';
    } elseif ($pass !== $again) {
        $error = 'Dua kotaknya belum sama.';
    } elseif (auth_accept_invite($token, $pass)) {
        header('Location: index.php');
        exit;
    } else {
        // Antara memuat formulir dan mengirimkannya, tokennya bisa saja ditebus di tempat
        // lain atau kedaluwarsa.
        $who = null;
    }
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
<title>Buat password · FURA</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap">
<style>
* { box-sizing:border-box; }
body { margin:0; background:#f5f3ef; color:#1a1c1f; min-height:100vh; display:grid;
       place-items:center; padding:1.5rem;
       font:400 0.9375rem/1.55 "Public Sans", system-ui, sans-serif; }
.card { background:#fff; border:1px solid #e4e1db; border-radius:0.75rem; padding:1.75rem;
        width:100%; max-width:24rem; }
h1 { font-size:1.375rem; margin:0 0 0.25rem; letter-spacing:-0.01em; }
p.sub { margin:0 0 1.5rem; color:#6b6d73; font-size:0.875rem; }
label { display:block; font-size:0.75rem; font-weight:500; margin:0 0 0.25rem; color:#6b6d73; }
input { width:100%; padding:0.5rem 0.625rem; font:inherit; background:#faf9f6;
        border:1px solid #d9d5ce; border-radius:0.375rem; margin:0 0 1rem; }
button { width:100%; padding:0.625rem; font:inherit; font-weight:500; cursor:pointer;
         background:#1a1c1f; color:#fff; border:0; border-radius:0.375rem; }
.err { margin:0 0 1rem; padding:0.625rem 0.75rem; border-radius:0.5rem;
       background:#f7e7e4; color:#97322a; font-size:0.875rem; }
.hint { margin:1.25rem 0 0; color:#6b6d73; font-size:0.8125rem; }
a { color:#1a1c1f; }
</style>
</head>
<body>
<main class="card">
<?php if (!$who): ?>
  <h1>Link-nya tidak berlaku</h1>
  <p class="sub">Undangan berlaku sekali pakai dan punya batas waktu. Minta admin
     mengirimkan yang baru.</p>
  <p class="hint"><a href="login.php">Sudah punya password? Masuk di sini.</a></p>
<?php else: ?>
  <h1>Halo, <?= h($who['name']) ?></h1>
  <p class="sub">Buat password untuk akunmu. Yang mengundang tidak akan tahu passwordnya —
     cuma kamu.</p>
  <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
  <form method="post" autocomplete="on">
    <input type="hidden" name="t" value="<?= h($token) ?>">
    <label for="email">Email</label>
    <input id="email" type="email" value="<?= h($who['email']) ?>" disabled
           autocomplete="username">
    <label for="password">Password baru <span style="font-weight:400">— minimal 10 karakter</span></label>
    <input id="password" name="password" type="password" required minlength="10" autofocus
           autocomplete="new-password">
    <label for="password2">Ulangi password</label>
    <input id="password2" name="password2" type="password" required minlength="10"
           autocomplete="new-password">
    <button type="submit">Simpan dan masuk</button>
  </form>
  <p class="hint">Sekali masuk, kamu tetap masuk selama 30 hari di perangkat ini.</p>
<?php endif; ?>
</main>
</body>
</html>
