<?php
/**
 * Akun sendiri: nama, dan password.
 *
 * Sebelum halaman ini ada, satu-satunya cara mengganti password adalah minta admin
 * mengeluarkan link masuk, lalu — tidak ada lalu, karena link masuk tidak menanyakan
 * password baru. Jadi password yang sudah dipakai bertahun tidak bisa diganti sama sekali,
 * dan yang curiga akunnya dipakai orang lain tidak punya satu pun tombol untuk ditekan.
 *
 * Sengaja tidak ada yang lain di sini. Peran, tim dan tanggal mulai memang tentang orang
 * ini, tapi ketiganya yang memutuskan dia muncul di rekap siapa — jadi yang mengubahnya
 * admin, bukan dirinya sendiri. Ditampilkan, supaya dia tahu, tapi tidak bisa diketik.
 */

declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require __DIR__ . '/lib/require-php8.php';

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/repo.php';
require_once __DIR__ . '/lib/layout.php';

messi_require_current();

$me = auth_user();
if (!$me) { header('Location: login.php'); exit; }

$notice = null;
$error  = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'nama') {
        $nama = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')));
        if ($nama === '') {
            $error = 'Namanya belum diisi.';
        } else {
            repo_save_roster($me, uid((int) $me['id']), ['name' => $nama]);
            auth_forget();                     // header dan menunya ikut berganti nama
            $me = auth_user() ?? $me;
            $notice = 'Nama diubah.';
        }

    } elseif ($do === 'sandi') {
        // Dibatasi seperti halaman masuk. Sesi berumur 30 hari, jadi laptop yang
        // ditinggal terbuka adalah tempat paling nyaman untuk menebak password lama
        // berkali-kali — dan di sini setiap tebakan yang benar berarti akunnya berpindah
        // tangan untuk selamanya.
        if (auth_throttled((string) $me['email'])) {
            $error = 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.';
        } else {
            $error = auth_change_password($me,
                                          (string) ($_POST['current'] ?? ''),
                                          (string) ($_POST['baru'] ?? ''),
                                          (string) ($_POST['baru2'] ?? ''));
            if ($error === null) {
                auth_clear_failures((string) $me['email']);
                auth_forget();
                $me = auth_user() ?? $me;
                $notice = 'Password diganti. Perangkat lain yang masih masuk sudah '
                        . 'dikeluarkan — di sini kamu tetap masuk.';
            } else {
                auth_note_failure((string) $me['email']);
            }
        }

    } elseif ($do === 'sesi') {
        $n = auth_end_other_sessions((int) $me['id']);
        $notice = $n === 0
            ? 'Tidak ada perangkat lain yang sedang masuk.'
            : ($n === 1 ? 'Satu perangkat lain dikeluarkan.'
                        : $n . ' perangkat lain dikeluarkan.');
    }
}

$teams = repo_teams(true);
$timNama = $me['team_id'] === null ? 'belum ada tim'
         : ($teams[(int) $me['team_id']]['name'] ?? 'tim yang sudah dihapus');
$peran = ['player' => 'Pemain — mengisi laporan hariannya sendiri',
          'leader' => 'Leader — ikut membaca rekap timnya',
          'admin'  => 'Admin — mengurus orang, tim dan pertanyaan',
          'owner'  => 'Owner — sama seperti admin, plus mengangkat admin'];

// Berapa perangkat lain yang masih memegang sesi. Angka, bukan daftar: alamat IP dan nama
// browser lebih sering menyesatkan daripada menolong — satu HP yang berpindah jaringan
// terbaca seperti dua orang asing.
$lain = (int) q1('SELECT COUNT(*) AS n FROM sessions
                   WHERE user_id = ? AND token <> ? AND expires_at > ?',
                 [(int) $me['id'], (string) ($_COOKIE[MESSI_COOKIE] ?? ''),
                  Clock::nowUtcSql()])['n'];

$csrf = csrf_token();

page_head('Akun', ['me' => $me, 'css' => <<<'CSS'
dl.fakta { margin:0; display:grid; grid-template-columns:8rem 1fr; gap:0.375rem 1rem }
dl.fakta dt { color:var(--muted); font-size:0.8125rem }
dl.fakta dd { margin:0; font-size:0.875rem }
@media (max-width: 30rem) {
  dl.fakta { grid-template-columns:1fr; gap:0 }
  dl.fakta dd { margin:0 0 0.625rem }
}
CSS]);
?>
  <h1>Akun</h1>
  <p class="sub"><a href="index.php">‹ Laporan</a></p>

  <?php if ($notice): ?><p class="note ok"><?= h($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="note bad"><?= h($error) ?></p><?php endif; ?>

  <h2>Namamu</h2>
  <p class="why">Yang terbaca di rekap dan di pengingat chat.</p>
  <div class="card">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="do" value="nama">
      <label for="nm">Nama</label>
      <input id="nm" name="name" type="text" maxlength="120" required
             value="<?= h((string) $me['name']) ?>">
      <p style="margin:1rem 0 0"><button class="quiet" type="submit">Simpan nama</button></p>
    </form>
  </div>

  <h2>Password</h2>
  <p class="why">Minimal 10 karakter. Begitu diganti, perangkat lain yang masih masuk
     dikeluarkan — yang ini tetap masuk.</p>
  <div class="card">
    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="do" value="sandi">
      <label for="pc">Password sekarang</label>
      <input id="pc" name="current" type="password" required autocomplete="current-password">
      <p style="margin:0.875rem 0 0"><label for="p1">Password baru</label>
        <input id="p1" name="baru" type="password" required minlength="10"
               autocomplete="new-password"></p>
      <p style="margin:0.875rem 0 0"><label for="p2">Ulangi password baru</label>
        <input id="p2" name="baru2" type="password" required minlength="10"
               autocomplete="new-password"></p>
      <p style="margin:1rem 0 0"><button type="submit">Ganti password</button></p>
    </form>
  </div>

  <h2>Perangkat lain</h2>
  <p class="why"><?= $lain === 0
     ? 'Cuma perangkat ini yang sedang masuk.'
     : 'Ada ' . $lain . ' perangkat lain yang masih masuk ke akun ini. Sesi berlaku 30 '
       . 'hari, jadi HP atau laptop yang sudah tidak kamu pakai bisa masih terbuka.' ?></p>
  <div class="card">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="do" value="sesi">
      <button class="quiet" type="submit"<?= $lain === 0 ? ' disabled' : '' ?>>Keluarkan
        perangkat lain</button>
    </form>
  </div>

  <h2>Yang diatur admin</h2>
  <p class="why">Ketiganya menentukan kamu muncul di rekap siapa, jadi yang mengubahnya
     admin. Kalau ada yang salah, bilang ke dia.</p>
  <div class="card">
    <dl class="fakta">
      <dt>Email</dt><dd><?= h((string) $me['email']) ?></dd>
      <dt>Tim</dt><dd><?= h($timNama) ?></dd>
      <dt>Peran</dt><dd><?= h($peran[(string) $me['role']] ?? (string) $me['role']) ?></dd>
      <dt>Mulai lapor</dt><dd><?= h(messi_fmt_day((string) $me['joined_on'])) ?></dd>
    </dl>
  </div>
<?php
page_foot();
