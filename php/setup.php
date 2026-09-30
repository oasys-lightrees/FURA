<?php
/**
 * First run only: creates the first admin account.
 *
 * It refuses once anyone exists, so leaving it on the server does not leave a door open.
 * Deleting it afterwards is still the tidier habit.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

$count = (int) (q1('SELECT COUNT(*) AS n FROM users')['n'] ?? 0);
if ($count > 0) {
    http_response_code(403);
    exit('Sudah ada akun. Hapus file setup.php ini.');
}

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $name  = trim((string) ($_POST['name'] ?? ''));
    $pass  = (string) ($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email-nya belum benar.';
    } elseif ($name === '') {
        $error = 'Isi namanya.';
    } elseif (strlen($pass) < 10) {
        $error = 'Password minimal 10 karakter.';
    } else {
        q('INSERT INTO users (email, name, password_hash, role, joined_on, created_at)
           VALUES (?,?,?,?,?,?)',
          [$email, $name, password_hash($pass, PASSWORD_DEFAULT), 'admin',
           (string) (cfg('first_day') ?: Clock::today()), Clock::nowUtcSql()]);
        header('Location: login.php');
        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup · MESSI</title>
<style>
body { margin:0; min-height:100vh; display:grid; place-items:center; background:#f5f3ef;
       color:#1a1c1f; font:400 1rem/1.55 system-ui, sans-serif; padding:1.5rem; }
.card { width:min(23rem,100%); background:#fff; border:1px solid #e4e1db; border-radius:0.75rem; padding:2rem 1.75rem; }
h1 { margin:0 0 1.25rem; font-size:1.25rem; }
label { display:block; margin:0 0 0.375rem; font-size:0.8125rem; font-weight:500; }
input { width:100%; box-sizing:border-box; padding:0.625rem 0.75rem; margin:0 0 1rem; font:inherit;
        background:#faf9f6; border:1px solid #d9d5ce; border-radius:0.5rem; }
button { width:100%; padding:0.6875rem; font:inherit; font-weight:600; color:#fff; background:#1a1c1f;
         border:0; border-radius:0.5rem; cursor:pointer; }
.err { margin:0 0 1rem; padding:0.625rem 0.75rem; border-radius:0.5rem; background:#f7e7e4; color:#97322a; font-size:0.875rem; }
</style>
</head>
<body>
<main class="card">
  <h1>Akun admin pertama</h1>
  <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
  <form method="post">
    <label for="name">Nama</label>
    <input id="name" name="name" required value="<?= h((string) ($_POST['name'] ?? '')) ?>">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" required value="<?= h((string) ($_POST['email'] ?? '')) ?>">
    <label for="password">Password (minimal 10 karakter)</label>
    <input id="password" name="password" type="password" required minlength="10">
    <button type="submit">Buat akun</button>
  </form>
</main>
</body>
</html>
