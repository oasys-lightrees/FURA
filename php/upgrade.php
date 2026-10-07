<?php
/**
 * Menyusulkan database ke bentuk yang dibutuhkan versi ini.
 *
 * Ada supaya memperbarui aplikasinya tidak berarti menyuruh orang membuka phpMyAdmin dan
 * menempelkan SQL. Yang dikerjakan disebutkan dulu dalam kalimat biasa, baru dijalankan
 * setelah ditekan — dan menjalankannya dua kali tidak melakukan apa-apa pada kali kedua.
 */

declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require __DIR__ . '/lib/require-php8.php';

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/layout.php';

messi_require_ready();

$me = auth_user();
if (!$me) { header('Location: login.php'); exit; }
if (!in_array($me['role'], ['admin', 'owner'], true)) {
    http_response_code(403);
    exit('Halaman ini untuk admin.');
}

$did = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $did = schema_upgrade();
}

$pending = schema_pending();
$csrf = csrf_token();

page_head('Pemutakhiran database', ['me' => $me, 'css' => <<<'CSS'
ul { margin:0; padding-left:1.25rem }
li { margin:0 0 0.625rem }
li b { display:block; font-weight:600; font-size:0.8125rem; font-family:var(--mono) }
CSS]);
?>
  <h1>Pemutakhiran database</h1>
  <p class="sub"><a href="kelola.php">‹ Kelola</a> · <a href="cek.php">Cek sistem</a></p>

  <?php if ($did !== null): ?>
    <p class="note ok"><?= $did
      ? 'Selesai: ' . h(implode(', ', $did)) . '.'
      : 'Tidak ada yang perlu dikerjakan.' ?></p>
  <?php endif; ?>

  <?php if (!$pending): ?>
    <div class="card">
      <p style="margin:0">Databasenya sudah sesuai dengan versi aplikasi yang terpasang.
         Tidak ada yang perlu dijalankan.</p>
    </div>
  <?php else: ?>
    <p class="note warn">Ada <?= count($pending) ?> hal yang perlu dikerjakan di database.
       Laporan harian tetap jalan selama ini belum dijalankan, tapi fitur yang baru belum
       bisa dipakai.</p>
    <div class="card">
      <ul>
        <?php foreach ($pending as $p): ?>
          <li><b><?= h($p['id']) ?></b><?= h($p['why']) ?></li>
        <?php endforeach; ?>
      </ul>
      <form method="post" style="margin:1.25rem 0 0">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <button type="submit">Jalankan sekarang</button>
      </form>
      <p style="margin:0.75rem 0 0;color:var(--muted);font-size:0.8125rem">
        Data yang sudah ada tidak dihapus. Aman diulang: menjalankannya dua kali tidak
        mengerjakan apa pun pada kali kedua. Kalau ragu, buat dulu cadangan lewat
        <em>phpMyAdmin &rarr; Export</em>.</p>
    </div>
  <?php endif; ?>
<?php
page_foot();
