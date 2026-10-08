<?php
/**
 * Apakah hosting ini sanggup menjalankan FURA, dan apa yang masih kurang.
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
        $want = MESSI_TABLES;
        $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff($want, $tables);
        row($missing ? 'bad' : 'ok', 'Tabel', $missing
            ? 'kurang: ' . implode(', ', $missing)
            : 'lengkap, ' . count($want) . ' tabel',
            $missing ? 'Buka <a href="upgrade.php">Pemutakhiran database</a>, atau import '
                     . '<code>install.sql</code> lewat phpMyAdmin.' : '');
        $installed = !$missing;

        // Tabel lengkap belum tentu bentuknya sudah sesuai: kolom dan nilai enum yang
        // baru tidak pernah sampai lewat CREATE TABLE IF NOT EXISTS.
        require_once __DIR__ . '/lib/schema.php';
        $pending = schema_pending();
        row($pending ? 'bad' : 'ok', 'Bentuk database',
            $pending ? count($pending) . ' hal belum dikerjakan: '
                     . implode(', ', array_column($pending, 'id'))
                     : 'sesuai dengan versi yang terpasang',
            $pending ? 'Buka <a href="upgrade.php">Pemutakhiran database</a> lalu tekan '
                     . 'Jalankan. Aman diulang, data yang ada tidak dihapus.' : '');
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
        if (!$me || !is_manager($me)) {
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
            'Buka space tim &rarr; klik nama space &rarr; <em>Apps &amp; integrations</em> '
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

/* ------------------------------------------------------------------ email */

if ($hasConfig) {
    require_once __DIR__ . '/lib/mail.php';
    $dari = mail_from()['email'];
    $bisaKirim = function_exists('mail');
    if ($dari === '') {
        row('warn', 'Email', 'belum diisi',
            'Tanpa ini undangan dan link masuk harus disalin admin lalu dikirim japri — '
            . 'aplikasinya tetap jalan penuh. Mau dikirim sendiri? Buat satu akun email di '
            . 'domain ini lewat cPanel &rarr; <em>Email Accounts</em>, misalnya '
            . '<code>fura@domainmu.com</code>, lalu tulis alamatnya di <code>mail_from</code> '
            . 'di config.php.');
    } elseif (!filter_var($dari, FILTER_VALIDATE_EMAIL)) {
        row('bad', 'Email', 'alamat pengirimnya bukan email',
            'Perbaiki <code>mail_from</code> di config.php.');
    } elseif (!$bisaKirim) {
        // Sebagian hosting mematikan mail() lewat disable_functions.
        row('bad', 'Email', 'fungsi mail() dimatikan hosting ini',
            'Kosongkan <code>mail_from</code> supaya aplikasinya tidak menjanjikan email '
            . 'yang tidak pernah terkirim, lalu minta hosting menyalakan <code>mail</code>.');
    } else {
        row('ok', 'Email', 'dikirim dari ' . $dari,
            'Kalau pesannya masuk spam, tambahkan SPF di DNS domain ini — di cPanel: '
            . '<em>Email Deliverability</em> &rarr; <em>Repair</em>.');
    }
}

if ($installed) {
    $gagalMail = q1("SELECT ran_at, detail FROM job_log WHERE kind = 'mail_fail'
                      ORDER BY id DESC LIMIT 1");
    if ($gagalMail) {
        $jamMail = (int) ((time() - strtotime($gagalMail['ran_at'] . ' UTC')) / 3600);
        row($jamMail < 48 ? 'bad' : 'warn', 'Email terakhir gagal',
            $gagalMail['ran_at'] . ' UTC — ' . h($gagalMail['detail']),
            'Linknya tetap berlaku dan bisa disalin dari halaman Orang &amp; tim. Kalau ini '
            . 'terus terjadi, kosongkan <code>mail_from</code> sampai hostingnya beres — '
            . 'lebih baik tidak menjanjikan email daripada menjanjikan yang tidak datang.');
    }
}

/* ------------------------------------------------------------------ cron */

if ($installed) {
    // Denyutnya yang ditanya, bukan baris terakhir apa pun. Hari Sabtu cron yang sehat
    // memang tidak mengerjakan apa-apa — dan dulu itu terlihat persis seperti cron yang
    // sudah mati sejak Jumat.
    $last = q1("SELECT ran_at, kind FROM job_log WHERE kind = 'tick' ORDER BY id DESC LIMIT 1")
         ?: q1('SELECT ran_at, kind FROM job_log ORDER BY id DESC LIMIT 1');
    if ($last) {
        $ago = (int) ((time() - strtotime($last['ran_at'] . ' UTC')) / 60);
        row($ago < 130 ? 'ok' : 'warn', 'Cron terakhir jalan',
            $last['ran_at'] . ' UTC (' . $ago . ' menit lalu, "' . $last['kind'] . '")',
            $ago < 130 ? '' : 'Sudah lebih dari dua jam. Cek <em>Cron Jobs</em> di cPanel — '
                . 'atau jalankan sendiri sekarang lewat <code>cron/tick.php?key=</code> '
                . 'diikuti cron_key kamu, lalu muat ulang halaman ini. Kalau setelah itu '
                . 'waktunya berubah, cron-nya memang tidak dipanggil cPanel; kalau tidak '
                . 'berubah, lihat baris kesalahan di bawah.');
    } else {
        row('warn', 'Cron', 'belum pernah jalan',
            'Belum dipasang, atau baru dipasang dan belum sampai menit ke-0. '
            . 'Mau langsung coba? Buka <code>cron/tick.php?key=</code> diikuti cron_key kamu.');
    }

    // Pengingat yang tidak sampai adalah keluhan yang paling sering terdengar dan paling
    // sulit ditelusuri, karena yang terlihat cuma "botnya mati". Jadi dua hal disebutkan
    // di sini: kapan terakhir benar-benar terkirim, dan apa kesalahan terakhirnya.
    $kirim = q1("SELECT ran_at, detail FROM job_log
                  WHERE kind = 'notify_open' AND detail LIKE '%sent=1%'
                  ORDER BY id DESC LIMIT 1");
    row($kirim ? 'ok' : 'warn', 'Pengingat terakhir terkirim',
        $kirim ? $kirim['ran_at'] . ' UTC' : 'belum pernah',
        $kirim ? '' : 'Belum ada pengingat yang benar-benar sampai. Kalau cron sudah jalan, '
                 . 'lihat baris kesalahan di bawah.');

    $salah = q1("SELECT ran_at, kind, detail FROM job_log
                  WHERE kind IN ('chat_error', 'tick_error', 'tick_fatal', 'needs_upgrade')
                  ORDER BY id DESC LIMIT 1");
    if ($salah) {
        $jam = (int) ((time() - strtotime($salah['ran_at'] . ' UTC')) / 3600);
        row($jam < 48 ? 'bad' : 'warn', 'Kesalahan terakhir',
            $salah['ran_at'] . ' UTC — ' . h($salah['kind']) . ': ' . h($salah['detail']),
            $salah['kind'] === 'needs_upgrade'
                ? 'Buka <a href="upgrade.php">Pemutakhiran database</a>.'
                : 'Kalau ini berulang tiap jam, webhook-nya kemungkinan sudah tidak berlaku '
                  . '— buat ulang dari space-nya lalu simpan di halaman Pertanyaan tim itu.');
    }
}

/* ------------------------------------------------------------ uji kirim */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['uji']) && $hasConfig) {
    require_once __DIR__ . '/lib/chat.php';
    $sent = chat_send('Tes dari FURA. Kalau pesan ini kelihatan, webhook-nya sudah benar.');
    array_unshift($rows, [
        'state' => $sent ? 'ok' : 'bad',
        'what'  => 'Pesan uji',
        'found' => $sent ? 'terkirim — cek space tim' : 'gagal terkirim',
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

$e = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
require_once __DIR__ . '/lib/layout.php';
page_head('Cek sistem', ['me' => $me ?? null, 'css' => <<<'CSS'
.verdict { padding:1rem 1.25rem; border-radius:0.75rem; margin:0 0 1.5rem; font-weight:600 }
.verdict.ok { background:var(--kept-bg); color:var(--kept) }
.verdict.warn { background:var(--due-bg); color:var(--due) }
.verdict.bad { background:var(--broken-bg); color:var(--broken) }
.card { padding:0; overflow:hidden }
.item { display:flex; gap:0.875rem; padding:0.875rem 1.125rem; border-top:1px solid var(--line) }
.item:first-child { border-top:0 }
.dot { flex:0 0 auto; width:1.375rem; height:1.375rem; border-radius:50%; margin-top:0.125rem;
       display:grid; place-items:center; font-size:0.8125rem; font-weight:700; color:#fff }
.ok .dot { background:var(--kept) } .warn .dot { background:var(--due) }
.bad .dot { background:var(--broken) }
.what { font-weight:600 }
.found { color:var(--muted); font-size:0.875rem; word-break:break-word }
.fix { font-size:0.875rem; margin:0.375rem 0 0 }
pre { background:var(--ink); color:var(--paper); padding:1rem 1.125rem; border-radius:0.75rem;
      overflow-x:auto; font:400 0.8125rem/1.6 var(--mono) }
CSS]);
?>
  <h1>Cek sistem</h1>
  <p class="sub"><?php if (isset($me) && $me): ?><a href="kelola.php">‹ Kelola</a> · <?php endif; ?>
     Apakah tempat ini sanggup menjalankan FURA, dan apa yang masih kurang.</p>

  <?php if ($fatal): ?>
    <p class="verdict bad">Belum bisa jalan — ada <?= $fatal ?> hal yang harus dibereskan dulu.</p>
  <?php elseif ($warn): ?>
    <p class="verdict warn">Bisa jalan. <?= $warn ?> hal sebaiknya dibereskan, tapi tidak menghalangi.</p>
  <?php else: ?>
    <p class="verdict ok">Semua beres. Hosting ini sanggup menjalankan FURA.</p>
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
    <button type="submit" name="uji" value="1">Kirim pesan uji</button>
  </form>
  <p class="sub" style="margin:0 0 1rem">Satu pesan pendek akan muncul di space tim.</p>
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
<?php
page_foot();
