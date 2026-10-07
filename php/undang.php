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
require_once __DIR__ . '/lib/layout.php';

messi_require_current();

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

page_head('Buat password', ['center' => true]);
?>
<div class="card">
<?php if (!$who): ?>
  <h1>Link-nya tidak berlaku</h1>
  <p class="sub">Undangan berlaku sekali pakai dan punya batas waktu. Minta admin
     mengirimkan yang baru.</p>
  <p class="why"><a href="login.php">Sudah punya password? Masuk di sini.</a></p>
<?php else: ?>
  <h1>Halo, <?= h($who['name']) ?></h1>
  <p class="sub">Buat password untuk akunmu. Yang mengundang tidak akan tahu passwordnya —
     cuma kamu.</p>
  <?php if ($error): ?><p class="note bad"><?= h($error) ?></p><?php endif; ?>
  <form method="post" autocomplete="on">
    <input type="hidden" name="t" value="<?= h($token) ?>">
    <label for="email">Email</label>
    <input id="email" type="email" value="<?= h($who['email']) ?>" disabled
           autocomplete="username">
    <label for="password">Password baru — minimal 10 karakter</label>
    <input id="password" name="password" type="password" required minlength="10" autofocus
           autocomplete="new-password">
    <label for="password2">Ulangi password</label>
    <input id="password2" name="password2" type="password" required minlength="10"
           autocomplete="new-password">
    <p style="margin:1.25rem 0 0"><button type="submit">Simpan dan masuk</button></p>
  </form>
  <p class="why" style="margin:1.25rem 0 0">Sekali masuk, kamu tetap masuk selama 30 hari
     di perangkat ini.</p>
<?php endif; ?>
</div>
<?php
page_foot();
