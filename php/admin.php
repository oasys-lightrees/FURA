<?php
/**
 * The squad list: who reports, who leads, and who is connected to the bot.
 *
 * Small on purpose. Adding somebody, resetting a password and handing out a pairing code
 * are the only three things anyone has actually needed to do.
 */

declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require __DIR__ . '/lib/require-php8.php';

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/repo.php';

$me = auth_user();
if (!$me) { header('Location: login.php'); exit; }
if ($me['role'] !== 'admin') { http_response_code(403); exit('Halaman ini untuk admin.'); }

$notice = null;
$error = null;
$pairing = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'add') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $name  = trim((string) ($_POST['name'] ?? ''));
        $role  = in_array($_POST['role'] ?? '', ['player', 'leader', 'admin'], true) ? $_POST['role'] : 'player';
        $pass  = (string) ($_POST['password'] ?? '');
        $joined = (string) ($_POST['joined'] ?? Clock::today());

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
            $error = 'Nama dan email harus diisi dengan benar.';
        } elseif (strlen($pass) < 10) {
            $error = 'Password minimal 10 karakter.';
        } elseif (q1('SELECT id FROM users WHERE email = ?', [$email])) {
            $error = 'Email itu sudah terdaftar.';
        } else {
            q('INSERT INTO users (email, name, password_hash, role, joined_on, created_at)
               VALUES (?,?,?,?,?,?)',
              [$email, $name, password_hash($pass, PASSWORD_DEFAULT), $role, $joined, Clock::nowUtcSql()]);
            // Joining today means nothing before today is counted against them.
            $notice = $name . ' ditambahkan.';
        }

    } elseif ($do === 'password') {
        $pass = (string) ($_POST['password'] ?? '');
        if (strlen($pass) < 10) {
            $error = 'Password minimal 10 karakter.';
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?',
              [password_hash($pass, PASSWORD_DEFAULT), (int) $_POST['id']]);
            // Old sessions are not a courtesy to keep after a reset.
            q('DELETE FROM sessions WHERE user_id = ?', [(int) $_POST['id']]);
            $notice = 'Password diganti.';
        }

    } elseif ($do === 'pair') {
        // The code is the front of a one-time token: short enough to type into Telegram,
        // short-lived enough that a screenshot goes stale.
        $link = auth_make_login_link((int) $_POST['id'], 60);
        $pairing = ['id' => (int) $_POST['id'], 'code' => substr((string) parse_url($link, PHP_URL_QUERY), 2, 8)];
        $notice = 'Kode berlaku 60 menit.';

    } elseif ($do === 'active') {
        q('UPDATE users SET active = ? WHERE id = ? AND id <> ?',
          [(int) $_POST['active'], (int) $_POST['id'], $me['id']]);
        $notice = 'Status diubah.';

    } elseif ($do === 'role') {
        $role = in_array($_POST['role'] ?? '', ['player', 'leader', 'admin'], true) ? $_POST['role'] : 'player';
        q('UPDATE users SET role = ? WHERE id = ? AND id <> ?', [$role, (int) $_POST['id'], $me['id']]);
        $notice = 'Peran diubah.';
    }
}

$people = q('SELECT id, name, email, role, active, joined_on, telegram_chat_id
               FROM users ORDER BY active DESC, name')->fetchAll();
$csrf = csrf_token();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Squad · MESSI</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap">
<style>
* { box-sizing:border-box; }
body { margin:0; background:#f5f3ef; color:#1a1c1f; padding:2rem 1.25rem 4rem;
       font:400 0.9375rem/1.55 "Public Sans", system-ui, sans-serif; }
main { max-width:52rem; margin:0 auto; }
h1 { font-size:1.375rem; margin:0 0 0.25rem; letter-spacing:-0.01em; }
p.sub { margin:0 0 1.75rem; color:#6b6d73; font-size:0.875rem; }
h2 { font-size:1rem; margin:2rem 0 0.75rem; }
.card { background:#fff; border:1px solid #e4e1db; border-radius:0.75rem; padding:1.25rem; }
table { width:100%; border-collapse:collapse; }
th { text-align:left; font-size:0.75rem; font-weight:600; color:#6b6d73; text-transform:uppercase;
     letter-spacing:0.04em; padding:0 0.5rem 0.5rem 0; }
td { padding:0.625rem 0.5rem 0.625rem 0; border-top:1px solid #efece7; vertical-align:middle; }
td.who strong { display:block; }
td.who span { color:#6b6d73; font-size:0.8125rem; }
input, select { padding:0.4375rem 0.5rem; font:inherit; font-size:0.875rem; background:#faf9f6;
                border:1px solid #d9d5ce; border-radius:0.375rem; }
button { padding:0.4375rem 0.75rem; font:inherit; font-size:0.875rem; font-weight:500; cursor:pointer;
         background:#1a1c1f; color:#fff; border:0; border-radius:0.375rem; }
button.quiet { background:#faf9f6; color:#1a1c1f; border:1px solid #d9d5ce; }
form.row { display:inline-flex; gap:0.375rem; align-items:center; margin:0 0.25rem 0.25rem 0; }
.grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(11rem, 1fr)); gap:0.75rem; }
.grid label { display:block; font-size:0.75rem; font-weight:500; margin:0 0 0.25rem; }
.grid input, .grid select { width:100%; }
.note { padding:0.625rem 0.75rem; border-radius:0.5rem; margin:0 0 1rem; font-size:0.875rem; }
.ok { background:#e8f0eb; color:#2f6248; }
.bad { background:#f7e7e4; color:#97322a; }
.tag { font-size:0.75rem; padding:0.125rem 0.4375rem; border-radius:0.25rem; background:#f0ede8; color:#6b6d73; }
code { font:500 0.9375rem "IBM Plex Mono", ui-monospace, monospace; background:#f0ede8;
       padding:0.125rem 0.375rem; border-radius:0.25rem; }
a { color:#1a1c1f; }
.off td { opacity:0.5; }
</style>
</head>
<body>
<main>
  <h1>Squad OASYS</h1>
  <p class="sub"><a href="index.php">← kembali ke laporan</a></p>

  <?php if ($notice): ?><p class="note ok"><?= h($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="note bad"><?= h($error) ?></p><?php endif; ?>

  <?php if ($pairing): ?>
    <p class="note ok">Kode Telegram: <code><?= h($pairing['code']) ?></code> — suruh orangnya
       kirim <code>/mulai <?= h($pairing['code']) ?></code> ke bot.</p>
  <?php endif; ?>

  <div class="card">
  <table>
    <tr><th>Orang</th><th>Peran</th><th>Mulai</th><th>Telegram</th><th></th></tr>
    <?php foreach ($people as $p): ?>
    <tr class="<?= $p['active'] ? '' : 'off' ?>">
      <td class="who"><strong><?= h($p['name']) ?></strong><span><?= h($p['email']) ?></span></td>
      <td>
        <?php if ((int) $p['id'] === (int) $me['id']): ?>
          <span class="tag"><?= h($p['role']) ?> (kamu)</span>
        <?php else: ?>
        <form class="row" method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="do" value="role">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <select name="role" onchange="this.form.submit()">
            <?php foreach (['player' => 'Player', 'leader' => 'Leader', 'admin' => 'Admin'] as $k => $v): ?>
              <option value="<?= $k ?>" <?= $p['role'] === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
      </td>
      <td><?= h(messi_fmt_day($p['joined_on'])) ?></td>
      <td><?= $p['telegram_chat_id'] ? 'tersambung' : '<span class="tag">belum</span>' ?></td>
      <td>
        <form class="row" method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="do" value="pair">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button class="quiet" type="submit">Kode Telegram</button>
        </form>
        <form class="row" method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="do" value="password">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <input type="password" name="password" placeholder="password baru" minlength="10" required>
          <button class="quiet" type="submit">Ganti</button>
        </form>
        <?php if ((int) $p['id'] !== (int) $me['id']): ?>
        <form class="row" method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="do" value="active">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <input type="hidden" name="active" value="<?= $p['active'] ? 0 : 1 ?>">
          <button class="quiet" type="submit"><?= $p['active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>

  <h2>Tambah orang</h2>
  <div class="card">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="do" value="add">
      <div class="grid">
        <div><label for="n">Nama</label><input id="n" name="name" required></div>
        <div><label for="e">Email</label><input id="e" name="email" type="email" required></div>
        <div><label for="p">Password awal</label><input id="p" name="password" type="password" minlength="10" required></div>
        <div><label for="r">Peran</label><select id="r" name="role">
          <option value="player">Player</option><option value="leader">Leader</option>
          <option value="admin">Admin</option></select></div>
        <div><label for="j">Mulai lapor</label>
          <input id="j" name="joined" type="date" value="<?= h(Clock::today()) ?>"></div>
      </div>
      <p style="margin:1rem 0 0"><button type="submit">Tambah</button></p>
    </form>
  </div>
</main>
</body>
</html>
