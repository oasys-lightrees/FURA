<?php
/**
 * First run only: creates the first admin account.
 *
 * It refuses once anyone exists, so leaving it on the server does not leave a door open.
 * Deleting it afterwards is still the tidier habit.
 */

declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require __DIR__ . '/lib/require-php8.php';

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

// Says what is missing on the first screen, not on the first click.
messi_require_ready();

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
        // Akun pertama adalah owner: harus ada satu yang bisa mengangkat admin. Dan satu
        // tim, karena orang tanpa tim tidak muncul di rekap mana pun.
        require_once __DIR__ . '/lib/repo.php';
        $team = repo_default_team();
        $now = Clock::nowUtcSql();
        q('INSERT INTO users (email, name, password_hash, role, team_id, joined_on,
                              accepted_at, created_at)
           VALUES (?,?,?,?,?,?,?,?)',
          [$email, $name, password_hash($pass, PASSWORD_DEFAULT), 'owner', $team,
           (string) (cfg('first_day') ?: Clock::today()), $now, $now]);
        // Langsung masuk. Orangnya baru saja membuktikan dia memegang pemasangan yang
        // masih kosong ini, dan menyuruhnya mengetik ulang password yang baru dibuatnya
        // tiga detik lalu cuma menambah satu pintu tanpa menambah satu pun penjagaan.
        auth_start_session((int) db()->lastInsertId());
        header('Location: kelola.php');
        exit;
    }
}

page_head('Mulai', ['center' => true]);
?>
<div class="card">
  <h1>FURA</h1>
  <p class="sub">Follow Up Report Automation</p>
  <p class="why">Pemasangan ini masih kosong. Buat akun pemilik — akun yang bisa
     mengundang orang, mengangkat admin, dan mengubah pertanyaannya.</p>
  <?php if ($error): ?><p class="note bad"><?= h($error) ?></p><?php endif; ?>
  <form method="post">
    <label for="name">Nama</label>
    <input id="name" name="name" type="text" required autofocus
           value="<?= h((string) ($_POST['name'] ?? '')) ?>">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" required
           value="<?= h((string) ($_POST['email'] ?? '')) ?>">
    <label for="password">Password — minimal 10 karakter</label>
    <input id="password" name="password" type="password" required minlength="10"
           autocomplete="new-password">
    <p style="margin:1.25rem 0 0"><button type="submit">Buat akun
       pemilik</button></p>
  </form>
  <p class="why" style="margin:1.25rem 0 0">Setelah ini kamu langsung masuk, dan
     halaman berikutnya menuntun tiga langkah penyiapan.</p>
</div>
<?php
page_foot();
