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

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pemutakhiran database · FURA</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap">
<style>
* { box-sizing:border-box; }
body { margin:0; background:#f5f3ef; color:#1a1c1f; padding:2rem 1.25rem 4rem;
       font:400 0.9375rem/1.55 "Public Sans", system-ui, sans-serif; }
main { max-width:44rem; margin:0 auto; }
h1 { font-size:1.375rem; margin:0 0 0.25rem; letter-spacing:-0.01em; }
p.sub { margin:0 0 1.75rem; color:#6b6d73; font-size:0.875rem; }
.card { background:#fff; border:1px solid #e4e1db; border-radius:0.75rem; padding:1.25rem; }
ul { margin:0; padding-left:1.25rem; }
li { margin:0 0 0.625rem; }
li b { display:block; font-weight:600; font-size:0.8125rem; font-family:ui-monospace,monospace; }
button { padding:0.5rem 0.875rem; font:inherit; font-size:0.875rem; font-weight:500;
         cursor:pointer; background:#1a1c1f; color:#fff; border:0; border-radius:0.375rem; }
.note { padding:0.625rem 0.75rem; border-radius:0.5rem; margin:0 0 1rem; font-size:0.875rem; }
.ok { background:#e8f0eb; color:#2f6248; }
.warn { background:#f7eedd; color:#9a6410; }
a { color:#1a1c1f; }
</style>
</head>
<body>
<main>
  <h1>Pemutakhiran database</h1>
  <p class="sub"><a href="index.php">← kembali ke laporan</a> · <a href="cek.php">Cek hosting</a></p>

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
      <p style="margin:0.75rem 0 0;color:#6b6d73;font-size:0.8125rem">
        Data yang sudah ada tidak dihapus. Aman diulang: menjalankannya dua kali tidak
        mengerjakan apa pun pada kali kedua. Kalau ragu, buat dulu cadangan lewat
        <em>phpMyAdmin &rarr; Export</em>.</p>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
