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
require_once __DIR__ . '/lib/schema.php';

// Says what is missing on the first screen, not on the first click.
messi_require_ready();

$me = auth_user();
if (!$me) { header('Location: login.php'); exit; }
if (!is_manager($me)) { http_response_code(403); exit('Halaman ini untuk admin.'); }

$teams = repo_teams();
if (!$teams) {                       // pemasangan baru: selalu ada satu tim
    repo_default_team();
    $teams = repo_teams();
}
$notice = null;

$error = null;
$oneTime = null;
$oneTimeLabel = '';

/**
 * Boleh tidak orang ini menyentuh baris itu?
 *
 * Owner bisa menyentuh siapa saja kecuali dirinya sendiri. Admin tidak bisa menyentuh
 * owner maupun sesama admin — kalau bisa, satu admin bisa menurunkan yang lain lalu
 * mengambil alih, dan "admin" berhenti berarti apa pun.
 */
function admin_may_touch(array $me, ?array $target): bool
{
    if (!$target || (int) $target['id'] === (int) $me['id']) {
        return false;
    }
    if (is_owner($me)) {
        return true;
    }
    return !in_array($target['role'], ['admin', 'owner'], true);
}

// Peran yang boleh diberikan orang ini: hanya owner yang bisa mengangkat admin atau
// owner. Satu tempat, dipakai formulirnya maupun pemeriksaan kirimannya — dua tempat
// berarti suatu hari formulirnya menyembunyikan apa yang masih diterima server.
$canGive = is_owner($me) ? ['player', 'leader', 'admin', 'owner'] : ['player', 'leader'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $do = (string) ($_POST['do'] ?? '');
    $targetId = (int) ($_POST['id'] ?? 0);
    $target = $targetId ? q1('SELECT * FROM users WHERE id = ?', [$targetId]) : null;

    if ($do === 'add') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $name  = trim((string) ($_POST['name'] ?? ''));
        $role  = in_array($_POST['role'] ?? '', $canGive, true) ? (string) $_POST['role'] : 'player';
        $team  = (int) ($_POST['team'] ?? 0);
        $joined = (string) ($_POST['joined'] ?? Clock::today());

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
            $error = 'Nama dan email harus diisi dengan benar.';
        } elseif (!isset($teams[$team])) {
            $error = 'Pilih timnya.';
        } elseif (q1('SELECT id FROM users WHERE email = ?', [$email])) {
            $error = 'Email itu sudah terdaftar.';
        } else {
            // Password dikosongkan: yang membuatnya adalah orangnya sendiri lewat undangan.
            // Joining today means nothing before today is counted against them.
            q('INSERT INTO users (email, name, password_hash, role, team_id, joined_on, created_at)
               VALUES (?,?,?,?,?,?,?)',
              [$email, $name, '', $role, $team, $joined, Clock::nowUtcSql()]);
            $oneTime = auth_make_invite((int) db()->lastInsertId());
            $oneTimeLabel = 'Undangan untuk ' . $name . ' — berlaku 72 jam, sekali pakai.';
            $notice = $name . ' ditambahkan. Kirim link di bawah ini japri kepadanya.';
        }

    } elseif ($do === 'invite') {
        if (!admin_may_touch($me, $target)) {
            $error = 'Tidak bisa mengubah baris itu.';
        } else {
            $oneTime = auth_make_invite($targetId);
            $oneTimeLabel = ($target['accepted_at'] === null ? 'Undangan baru untuk ' : 'Link buat password baru untuk ')
                          . $target['name'] . ' — berlaku 72 jam, sekali pakai.';
            $notice = 'Kirim link di bawah ini japri, jangan ke space.';
        }

    } elseif ($do === 'link') {
        // One use, 60 minutes. Hand it over privately — in a Google Chat space it would
        // be a login link for everyone who can read that space.
        if (!admin_may_touch($me, $target)) {
            $error = 'Tidak bisa mengubah baris itu.';
        } else {
            $oneTime = auth_make_login_link($targetId, 60);
            $oneTimeLabel = 'Link masuk untuk ' . $target['name'] . ' — berlaku 60 menit, sekali pakai.';
            $notice = 'Kirim japri, jangan ke space.';
        }

    } elseif ($do === 'active') {
        if (!admin_may_touch($me, $target)) {
            $error = 'Tidak bisa mengubah baris itu.';
        } else {
            q('UPDATE users SET active = ? WHERE id = ?', [(int) $_POST['active'], $targetId]);
            $notice = 'Status diubah.';
        }

    } elseif ($do === 'team') {
        $team = (int) ($_POST['team'] ?? 0);
        if (!admin_may_touch($me, $target)) {
            $error = 'Tidak bisa mengubah baris itu.';
        } elseif (!isset($teams[$team])) {
            $error = 'Tim itu tidak ada.';
        } else {
            // Laporan yang sudah dikirim membawa timnya sendiri, jadi pindah tim hari ini
            // tidak menyentuh rekap kemarin.
            q('UPDATE users SET team_id = ? WHERE id = ?', [$team, $targetId]);
            $notice = $target['name'] . ' dipindah ke ' . $teams[$team]['name'] . '.';
        }

    } elseif ($do === 'role') {
        $role = in_array($_POST['role'] ?? '', $canGive, true) ? (string) $_POST['role'] : null;
        // Owner lain yang masih aktif — selain yang sedang diubah. Menghitung semuanya
        // termasuk yang diubah akan menolak penurunan owner yang sudah nonaktif padahal
        // masih ada owner lain yang memegang kendali.
        $lainnya = (int) q1("SELECT COUNT(*) AS n FROM users
                              WHERE role = 'owner' AND active = 1 AND id <> ?",
                            [$targetId])['n'];
        if (!admin_may_touch($me, $target)) {
            $error = 'Tidak bisa mengubah baris itu.';
        } elseif ($role === null) {
            $error = 'Peran itu di luar wewenangmu.';
        } elseif ($target['role'] === 'owner' && $role !== 'owner' && $lainnya === 0) {
            // Penjaga, bukan jalan: lewat halaman ini keadaan itu tidak bisa dicapai,
            // karena peran sendiri tidak bisa diubah sendiri. Ada supaya tetap benar
            // kalau suatu hari ada jalan lain menuju ke sini.
            $error = 'Harus selalu ada satu owner. Angkat owner lain dulu.';
        } else {
            q('UPDATE users SET role = ? WHERE id = ?', [$role, $targetId]);
            $notice = 'Peran diubah.';
        }

    } elseif ($do === 'addteam') {
        try {
            repo_add_team($me, (string) ($_POST['name'] ?? ''));
            $notice = 'Tim ditambahkan.';
            $teams = repo_teams();
        } catch (RepoError $e) {
            $error = $e->getMessage();
        }

    } elseif ($do === 'renteam') {
        $team = (int) ($_POST['team'] ?? 0);
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')));
        if (!isset($teams[$team]) || $name === '') {
            $error = 'Nama timnya belum benar.';
        } elseif (q1('SELECT id FROM teams WHERE name = ? AND id <> ?', [$name, $team])) {
            $error = 'Sudah ada tim dengan nama itu.';
        } else {
            q('UPDATE teams SET name = ? WHERE id = ?', [mb_substr($name, 0, 60), $team]);
            $notice = 'Nama tim diubah.';
            $teams = repo_teams();
        }
    }
}

$people = q('SELECT id, name, email, role, active, joined_on, team_id, invited_at, accepted_at
               FROM users ORDER BY active DESC, name')->fetchAll();
$csrf = csrf_token();
$pendingSchema = schema_pending();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tim · FURA</title>
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
.warn { background:#f7eedd; color:#9a6410; }
.tag { font-size:0.75rem; padding:0.125rem 0.4375rem; border-radius:0.25rem; background:#f0ede8; color:#6b6d73; }
code { font:500 0.9375rem "IBM Plex Mono", ui-monospace, monospace; background:#f0ede8;
       padding:0.125rem 0.375rem; border-radius:0.25rem; }
a { color:#1a1c1f; }
.off td { opacity:0.5; }
p.why { margin:0 0 0.75rem; color:#6b6d73; font-size:0.8125rem; max-width:42rem; }
</style>
</head>
<body>
<main>
  <h1>Orang &amp; tim</h1>
  <p class="sub"><a href="index.php">← kembali ke laporan</a> ·
     <a href="soal.php">Pertanyaan MESSI</a></p>

  <?php if ($pendingSchema): ?>
    <p class="note warn"><strong>Database belum sesuai versi ini.</strong>
       <?= count($pendingSchema) ?> hal belum dikerjakan, jadi sebagian fitur baru belum
       bisa dipakai. <a href="upgrade.php">Buka pemutakhiran database</a>.</p>
  <?php endif; ?>
  <?php if ($notice): ?><p class="note ok"><?= h($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="note bad"><?= h($error) ?></p><?php endif; ?>

  <?php if ($oneTime): ?>
    <p class="note ok"><?= h($oneTimeLabel) ?><br>
       Kirim <strong>japri</strong>, jangan ke space — siapa pun yang bisa membaca space
       itu bisa memakainya.<br>
       <code><?= h($oneTime) ?></code></p>
  <?php endif; ?>

  <?php
  $roleNames = ['player' => 'Pelapor', 'leader' => 'Leader', 'admin' => 'Admin', 'owner' => 'Owner'];
  ?>

  <div class="card">
  <table>
    <tr><th>Orang</th><th>Tim</th><th>Peran</th><th>Mulai</th><th></th></tr>
    <?php foreach ($people as $p): $mine = (int) $p['id'] === (int) $me['id'];
          $boleh = admin_may_touch($me, $p); ?>
    <tr class="<?= $p['active'] ? '' : 'off' ?>">
      <td class="who"><strong><?= h($p['name']) ?></strong><span><?= h($p['email']) ?></span>
        <?php if ($p['accepted_at'] === null): ?>
          <span class="tag">belum terima undangan</span>
        <?php endif; ?></td>
      <td>
        <?php if (!$boleh): ?>
          <span class="tag"><?= h($teams[(int) $p['team_id']]['name'] ?? '—') ?></span>
        <?php else: ?>
        <form class="row" method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="do" value="team">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <select name="team" onchange="this.form.submit()">
            <?php foreach ($teams as $t): ?>
              <option value="<?= (int) $t['id'] ?>"
                <?= (int) $p['team_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($mine): ?>
          <span class="tag"><?= h($roleNames[$p['role']] ?? $p['role']) ?> (kamu)</span>
        <?php elseif (!$boleh): ?>
          <span class="tag"><?= h($roleNames[$p['role']] ?? $p['role']) ?></span>
        <?php else: ?>
        <form class="row" method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="do" value="role">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <select name="role" onchange="this.form.submit()">
            <?php foreach ($canGive as $k): ?>
              <option value="<?= $k ?>" <?= $p['role'] === $k ? 'selected' : '' ?>><?= h($roleNames[$k]) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
      </td>
      <td><?= h(messi_fmt_day($p['joined_on'])) ?></td>
      <td>
        <?php if ($boleh): ?>
        <form class="row" method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="do" value="invite">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button class="quiet" type="submit"><?= $p['accepted_at'] === null
            ? 'Kirim ulang undangan' : 'Link buat password baru' ?></button>
        </form>
        <?php if ($p['accepted_at'] !== null): ?>
        <form class="row" method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="do" value="link">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button class="quiet" type="submit">Link masuk</button>
        </form>
        <?php endif; ?>
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
  <p class="why">Passwordnya dibuat orangnya sendiri lewat link undangan yang muncul
     setelah ini — jadi tidak ada password yang perlu kamu ketik, kirim, atau ingat.</p>
  <div class="card">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="do" value="add">
      <div class="grid">
        <div><label for="n">Nama</label><input id="n" name="name" required></div>
        <div><label for="e">Email</label><input id="e" name="email" type="email" required></div>
        <div><label for="tm">Tim</label><select id="tm" name="team">
          <?php foreach ($teams as $t): ?>
            <option value="<?= (int) $t['id'] ?>"><?= h($t['name']) ?></option>
          <?php endforeach; ?></select></div>
        <div><label for="r">Peran</label><select id="r" name="role">
          <?php foreach ($canGive as $k): ?>
            <option value="<?= $k ?>"><?= h($roleNames[$k]) ?></option>
          <?php endforeach; ?></select></div>
        <div><label for="j">Mulai lapor</label>
          <input id="j" name="joined" type="date" value="<?= h(Clock::today()) ?>"></div>
      </div>
      <p style="margin:1rem 0 0"><button type="submit">Tambah</button></p>
    </form>
  </div>

  <h2>Tim</h2>
  <p class="why">Tiap tim punya pertanyaan, ambang, jam dan space chat-nya sendiri.
     Satu orang satu tim.</p>
  <div class="card">
    <table>
      <tr><th>Nama</th><th>Orang</th><th></th></tr>
      <?php foreach ($teams as $t): ?>
      <tr>
        <td>
          <form class="row" method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="do" value="renteam">
            <input type="hidden" name="team" value="<?= (int) $t['id'] ?>">
            <input name="name" value="<?= h($t['name']) ?>" maxlength="60" required>
            <button class="quiet" type="submit">Ganti nama</button>
          </form>
        </td>
        <td><?= count(array_filter($people,
              fn($p) => (int) $p['team_id'] === (int) $t['id'] && $p['active'])) ?> orang</td>
        <td><a href="soal.php?team=<?= (int) $t['id'] ?>">Pertanyaan tim ini</a></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <form method="post" style="margin:1.25rem 0 0">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="do" value="addteam">
      <input name="name" placeholder="nama tim baru" maxlength="60" required>
      <button type="submit">Tambah tim</button>
    </form>
  </div>
</main>
</body>
</html>
