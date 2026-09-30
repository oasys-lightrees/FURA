<?php
/**
 * Apakah hosting ini sanggup menjalankan MESSI, dan apa yang masih kurang.
 *
 * Built because guessing whether a shared host can run this is slower than asking it.
 * Three things vary between hosts and all three fail quietly: the PHP version, whether
 * outbound HTTPS is allowed (posting to Google Chat needs it), and whether base_url matches
 * the address people actually type.
 *
 * Open it right after uploading, before anything else. Once an admin account exists it
 * asks for an admin login, so it cannot be used to read the server's shape afterwards.
 */

declare(strict_types=1);

require __DIR__ . '/lib/require-php8.php';

$rows = [];
$fatal = 0;
$warn = 0;

/** @param string $state 'ok' | 'warn' | 'bad' */
function row(string $state, string $what, string $found, string $fix = ''): void
{
    global $rows, $fatal, $warn;
    $rows[] = compact('state', 'what', 'found', 'fix');
    if ($state === 'bad') { $fatal++; }
    if ($state === 'warn') { $warn++; }
}

/* ------------------------------------------------------------------ PHP */

row('ok', 'Versi PHP', PHP_VERSION);   // require-php8.php sudah menghentikan yang < 8

foreach (['pdo_mysql' => 'wajib — untuk bicara ke MySQL',
          'json'      => 'wajib — untuk menyimpan jawaban',
          'mbstring'  => 'wajib — untuk nama dan teks Indonesia',
          'curl'      => 'hanya untuk pesan ke Google Chat'] as $ext => $why) {
    $have = extension_loaded($ext);
    $need = $ext !== 'curl';
    row($have ? 'ok' : ($need ? 'bad' : 'warn'), 'Ekstensi ' . $ext,
        $have ? 'ada' : 'tidak ada',
        $have ? '' : ($need
            ? 'Nyalakan di cPanel &rarr; <em>Select PHP Version</em> &rarr; tab <em>Extensions</em>.'
            : 'Tanpa ini aplikasinya tetap jalan, tapi pesan ke Google Chat tidak bisa dikirim.'));
}

/* --------------------------------------------------------------- config */

$configPath = getenv('MESSI_CONFIG_FILE') ?: __DIR__ . '/config.php';
$hasConfig = is_file($configPath);
row($hasConfig ? 'ok' : 'bad', 'config.php', $hasConfig ? 'ada' : 'belum ada',
    $hasConfig ? '' : 'Ganti nama <code>config.example.php</code> jadi <code>config.php</code>, lalu isi.');

$db = null;
$installed = false;
$tables = [];

if ($hasConfig) {
    require_once __DIR__ . '/lib/bootstrap.php';
    try {
        $db = db();
        row('ok', 'Koneksi database', 'berhasil');
        $want = ['users', 'sessions', 'login_tokens', 'cycles', 'commitments', 'job_log'];
        $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff($want, $tables);
        row($missing ? 'bad' : 'ok', 'Tabel', $missing
            ? 'kurang: ' . implode(', ', $missing) : 'lengkap, enam tabel',
            $missing ? 'Import <code>install.sql</code> lewat phpMyAdmin.' : '');
        $installed = !$missing;
    } catch (Throwable $e) {
        // messi_stop() already handles this on the real pages; here we want to keep going
        // and show everything else that is wrong in one pass.
        row('bad', 'Koneksi database', $e->getMessage(),
            'Cek <code>name</code>, <code>user</code>, <code>pass</code> di config.php. '
            . 'Di cPanel ketiganya berawalan nama akun.');
    }
}

/* ------------------------------------------------ siapa yang boleh lihat */

// Before the first account exists there is nothing to protect. After it, this page
// describes the server, so it belongs behind an admin login.
$open = !$installed;
if ($installed) {
    require_once __DIR__ . '/lib/auth.php';
    $people = (int) (q1('SELECT COUNT(*) n FROM users')['n'] ?? 0);
    if ($people === 0) {
        $open = true;
    } else {
        $me = auth_user();
        if (!$me || $me['role'] !== 'admin') {
            http_response_code(403);
            exit('Halaman ini untuk admin. <a href="login.php">Masuk dulu</a>.');
        }
        $open = true;
    }
}

/* ---------------------------------------------------------------- HTTPS */

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
row($https ? 'ok' : 'warn', 'HTTPS', $https ? 'aktif' : 'belum',
    $https ? '' : 'Nyalakan <em>SSL/TLS Status</em> &rarr; <em>Run AutoSSL</em> di cPanel. '
      . 'Tanpa HTTPS, cookie sesi tidak ditandai aman dan lewat terbuka di jaringan.');

/* -------------------------------------------------------------- base_url */

if ($hasConfig) {
    $here = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '')
          . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    $set = rtrim((string) cfg('base_url'), '/');
    $same = $set !== '' && rtrim($here, '/') === $set;
    row($same ? 'ok' : 'warn', 'base_url', $same ? $set : ($set ?: '(kosong)') . ' — sedang dibuka dari ' . $here,
        $same ? '' : 'Samakan <code>base_url</code> di config.php dengan <code>' . htmlspecialchars($here)
          . '</code>. Kalau beda, link yang dikirim bot tiap pagi mengarah ke tempat yang salah.');
}

/* -------------------------------------------------------- keluar jaringan */

if (extension_loaded('curl')) {
    $ch = curl_init('https://chat.googleapis.com/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
                            CURLOPT_NOBODY => true]);
    curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $reach = $code > 0;
    row($reach ? 'ok' : 'warn', 'Bisa menghubungi chat.googleapis.com',
        $reach ? 'bisa' : ('tidak — ' . ($err ?: 'tidak ada jawaban')),
        $reach ? '' : 'Sebagian hosting memblokir koneksi keluar. Tanpa ini pesan otomatis '
          . 'ke Google Chat tidak terkirim; aplikasinya sendiri tetap jalan. Minta hosting '
          . 'membuka akses keluar ke <code>chat.googleapis.com</code> port 443.');
}

if ($hasConfig) {
    $hook = trim((string) cfg('chat_webhook'));
    if ($hook === '') {
        row('warn', 'Webhook Google Chat', 'belum diisi',
            'Buka space squad &rarr; klik nama space &rarr; <em>Apps &amp; integrations</em> '
            . '&rarr; <em>Webhooks</em> &rarr; <em>Add webhooks</em> &rarr; salin URL-nya ke '
            . '<code>chat_webhook</code> di config.php. Tanpa ini aplikasinya tetap jalan, '
            . 'cuma tidak ada pesan pagi, pengingat, atau rekap.');
    } elseif (!str_starts_with($hook, 'https://chat.googleapis.com/')) {
        row('bad', 'Webhook Google Chat', 'bukan alamat Google Chat',
            'URL-nya harus diawali <code>https://chat.googleapis.com/v1/spaces/</code>. '
            . 'Salin ulang dari space-nya.');
    } else {
        row('ok', 'Webhook Google Chat', 'terisi',
            'Mau pastikan sampai? Klik tombol di bawah — satu pesan uji dikirim ke space.');
    }
}

/* ------------------------------------------------------------------ cron */

if ($installed) {
    $last = q1('SELECT ran_at, kind FROM job_log ORDER BY id DESC LIMIT 1');
    if ($last) {
        $ago = (int) ((time() - strtotime($last['ran_at'] . ' UTC')) / 60);
        row($ago < 130 ? 'ok' : 'warn', 'Cron terakhir jalan',
            $last['ran_at'] . ' UTC (' . $ago . ' menit lalu, "' . $last['kind'] . '")',
            $ago < 130 ? '' : 'Sudah lebih dari dua jam. Cek <em>Cron Jobs</em> di cPanel.');
    } else {
        row('warn', 'Cron', 'belum pernah jalan',
            'Belum dipasang, atau baru dipasang dan belum sampai menit ke-0. '
            . 'Mau langsung coba? Buka <code>cron/tick.php?key=</code> diikuti cron_key kamu.');
    }
}

/* ------------------------------------------------------------ uji kirim */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['uji']) && $hasConfig) {
    require_once __DIR__ . '/lib/chat.php';
    $sent = chat_send('Tes dari MESSI. Kalau pesan ini kelihatan, webhook-nya sudah benar.');
    array_unshift($rows, [
        'state' => $sent ? 'ok' : 'bad',
        'what'  => 'Pesan uji',
        'found' => $sent ? 'terkirim — cek space squad' : 'gagal terkirim',
        'fix'   => $sent ? '' : 'Salin ulang URL webhook-nya dari space. Kalau tetap gagal, '
                   . 'lihat tabel <code>job_log</code> baris <code>chat_error</code>.',
    ]);
    if (!$sent) { $fatal++; }
}

/* --------------------------------------------------------------- tampilan */

$phps = array_values(array_filter([
    '/usr/local/bin/php', '/usr/bin/php',
    '/opt/cpanel/ea-php82/root/usr/bin/php', '/opt/cpanel/ea-php81/root/usr/bin/php',
], 'is_file'));

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$e = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cek hosting · MESSI</title>
<style>
* { box-sizing:border-box; }
body { margin:0; background:#f5f3ef; color:#1a1c1f; padding:2.5rem 1.25rem 4rem;
       font:400 15px/1.6 system-ui,-apple-system,sans-serif; }
main { max-width:44rem; margin:0 auto; }
h1 { font-size:1.375rem; margin:0 0 .25rem; letter-spacing:-.01em; }
p.sub { margin:0 0 1.75rem; color:#6b6d73; font-size:14px; }
.verdict { padding:1rem 1.25rem; border-radius:.75rem; margin:0 0 1.5rem; font-weight:600; }
.verdict.ok { background:#e8f0eb; color:#2f6248; }
.verdict.warn { background:#f7eedd; color:#9a6410; }
.verdict.bad { background:#f7e7e4; color:#97322a; }
.card { background:#fff; border:1px solid #e4e1db; border-radius:.75rem; overflow:hidden; }
.item { display:flex; gap:.875rem; padding:.875rem 1.125rem; border-top:1px solid #efece7; }
.item:first-child { border-top:0; }
.dot { flex:0 0 auto; width:1.375rem; height:1.375rem; border-radius:50%; margin-top:.125rem;
       display:grid; place-items:center; font-size:13px; font-weight:700; color:#fff; }
.ok .dot { background:#2f6248; } .warn .dot { background:#9a6410; } .bad .dot { background:#97322a; }
.what { font-weight:600; }
.found { color:#6b6d73; font-size:14px; word-break:break-word; }
.fix { font-size:14px; margin:.375rem 0 0; }
code { font:500 13.5px ui-monospace,monospace; background:#f0ede8; padding:.1rem .35rem; border-radius:.25rem; }
h2 { font-size:1rem; margin:2rem 0 .75rem; }
pre { background:#1a1c1f; color:#f5f3ef; padding:1rem 1.125rem; border-radius:.75rem;
      overflow-x:auto; font:400 13px/1.6 ui-monospace,monospace; }
a { color:#1a1c1f; }
</style>
</head>
<body>
<main>
  <h1>Cek hosting</h1>
  <p class="sub">Apakah tempat ini sanggup menjalankan MESSI, dan apa yang masih kurang.</p>

  <?php if ($fatal): ?>
    <p class="verdict bad">Belum bisa jalan — ada <?= $fatal ?> hal yang harus dibereskan dulu.</p>
  <?php elseif ($warn): ?>
    <p class="verdict warn">Bisa jalan. <?= $warn ?> hal sebaiknya dibereskan, tapi tidak menghalangi.</p>
  <?php else: ?>
    <p class="verdict ok">Semua beres. Hosting ini sanggup menjalankan MESSI.</p>
  <?php endif; ?>

  <div class="card">
  <?php foreach ($rows as $r): ?>
    <div class="item <?= $r['state'] ?>">
      <span class="dot"><?= $r['state'] === 'ok' ? '&check;' : ($r['state'] === 'warn' ? '!' : '&times;') ?></span>
      <div>
        <div class="what"><?= $e($r['what']) ?></div>
        <div class="found"><?= $e($r['found']) ?></div>
        <?php if ($r['fix']): ?><p class="fix"><?= $r['fix'] ?></p><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

  <?php if ($hasConfig && trim((string) cfg('chat_webhook')) !== ''): ?>
  <h2>Uji kirim ke Google Chat</h2>
  <form method="post" style="margin:0 0 .5rem">
    <button type="submit" name="uji" value="1" style="padding:.625rem 1rem;font:inherit;
      font-weight:600;color:#fff;background:#1a1c1f;border:0;border-radius:.5rem;cursor:pointer">
      Kirim pesan uji</button>
  </form>
  <p class="sub" style="margin:0 0 1rem">Satu pesan pendek akan muncul di space squad.</p>
  <?php endif; ?>

  <h2>Perintah cron untuk hosting ini</h2>
  <p class="sub" style="margin:0 0 .75rem">Salin ke cPanel &rarr; <em>Cron Jobs</em> &rarr;
     <em>Once Per Hour</em>. Kalau perintah pertama tidak jalan, coba yang di bawahnya.</p>
  <pre><?php foreach ($phps ?: ['/usr/local/bin/php'] as $bin): ?>
<?= $e($bin) ?> <?= $e(__DIR__) ?>/cron/tick.php >/dev/null 2>&1
<?php endforeach; ?></pre>

  <p class="sub">Halaman ini aman dihapus setelah pemasangan selesai. Kalau dibiarkan, dia
     minta login admin begitu akun pertama sudah dibuat.</p>
  <p><a href="index.php">&larr; ke aplikasi</a></p>
</main>
</body>
</html>
