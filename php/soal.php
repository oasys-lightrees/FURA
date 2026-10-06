<?php
/**
 * Pertanyaan MESSI — yang ditanyakan, ambangnya, dan jamnya.
 *
 * Satu modul yang sama dipakai tim yang berbeda, dan yang berbeda di antara mereka hampir
 * selalu hal yang sama: nama channel-nya, berapa hari sebuah chat boleh menggantung, dan
 * jam berapa harinya dibuka dan ditutup. Halaman ini membuat semua itu bisa diubah tanpa
 * menyentuh kode — dan tidak lebih dari itu, karena setelan yang bisa apa saja adalah
 * setelan yang tidak ada yang berani ubah.
 *
 * Yang ditulis di sini dibaca pemain apa adanya, jadi semuanya teks biasa: tidak ada HTML
 * yang lolos, supaya admin tidak punya jalan menyuntikkan apa pun ke layar rekannya.
 */

declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require __DIR__ . '/lib/require-php8.php';

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/repo.php';

messi_require_ready();

$me = auth_user();
if (!$me) { header('Location: login.php'); exit; }
if (!is_manager($me)) { http_response_code(403); exit('Halaman ini untuk admin.'); }

// Pertanyaan milik tim, jadi halaman ini selalu tentang satu tim tertentu.
$teams = repo_teams();
$teamId = (int) ($_POST['team'] ?? $_GET['team'] ?? 0);
if (!isset($teams[$teamId])) {
    $teamId = repo_team_of($me);
}

$notice = null;
// Setelan disimpan di tabel `settings`. Kalau pemasangannya belum meng-import versi
// install.sql yang membuatnya, aplikasinya tetap jalan dengan pertanyaan bawaan — tapi
// halaman ini tidak bisa menyimpan apa pun, dan harus mengatakannya di depan.
$missing = messi_missing_tables();
$noTable = in_array('settings', $missing, true);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$noTable) {
    csrf_check();

    if (($_POST['do'] ?? '') === 'reset') {
        repo_save_config($me, $teamId, messi_config_default());
        $notice = 'Dikembalikan ke pertanyaan bawaan.';
    } else {
        $q = is_array($_POST['q'] ?? null) ? $_POST['q'] : [];
        // Checkbox yang tidak dicentang tidak ikut terkirim. Di formulir itu berarti
        // "matikan" — berbeda dari dokumen lama yang memang belum punya kuncinya.
        foreach (['escalation', 'prista'] as $name) {
            $q[$name]['show'] = isset($q[$name]['show']);
        }
        // Webhook hanya diganti kalau diisi. Kotaknya selalu tampil kosong, jadi
        // menyimpan tanpa menyentuhnya tidak boleh berarti menghapusnya.
        $webhook = trim((string) ($_POST['chat_webhook'] ?? ''));
        if ($webhook === '' && ($_POST['chat_webhook_keep'] ?? '') === '1') {
            $webhook = repo_config($teamId)['chat_webhook'];
        }
        repo_save_config($me, $teamId, [
            'team_name'      => (string) ($_POST['team_name'] ?? ''),
            'channels'       => array_values(is_array($_POST['ch'] ?? null) ? $_POST['ch'] : []),
            'threshold_days' => $_POST['threshold_days'] ?? null,
            'open_hour'      => $_POST['open_hour'] ?? null,
            'due_hour'       => $_POST['due_hour'] ?? null,
            'max_plans'      => $_POST['max_plans'] ?? null,
            'declaration'    => (string) ($_POST['declaration'] ?? ''),
            'chat_webhook'   => $webhook,
            'questions'      => $q,
        ]);
        // Yang tidak masuk akal dirapikan diam-diam oleh messi_config_normalize(), jadi
        // yang ditampilkan ulang di bawah adalah yang benar-benar berlaku — bukan yang
        // barusan diketik.
        $notice = 'Tersimpan. Yang tampil di bawah adalah yang sekarang berlaku.';
    }
}

$cfg = repo_config($teamId);
$q = $cfg['questions'];
$csrf = csrf_token();
$days = $cfg['threshold_days'];

// Seberapa banyak laporan yang sudah terlanjur memakai setelan lama.
$sent = (int) q1('SELECT COUNT(*) n FROM cycles WHERE submitted_at IS NOT NULL')['n'];

$hours = function (string $name, int $now, int $from, int $to): string {
    $out = '';
    for ($h = $from; $h <= $to; $h++) {
        $out .= '<option value="' . $h . '"' . ($h === $now ? ' selected' : '') . '>'
             . sprintf('%02d:00', $h) . '</option>';
    }
    return '<select name="' . h($name) . '">' . $out . '</select>';
};

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pertanyaan · FURA</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap">
<style>
* { box-sizing:border-box; }
body { margin:0; background:#f5f3ef; color:#1a1c1f; padding:2rem 1.25rem 4rem;
       font:400 0.9375rem/1.55 "Public Sans", system-ui, sans-serif; }
main { max-width:52rem; margin:0 auto; }
h1 { font-size:1.375rem; margin:0 0 0.25rem; letter-spacing:-0.01em; }
p.sub { margin:0 0 1.75rem; color:#6b6d73; font-size:0.875rem; }
h2 { font-size:1rem; margin:2rem 0 0.5rem; }
p.why { margin:0 0 0.75rem; color:#6b6d73; font-size:0.8125rem; max-width:42rem; }
.card { background:#fff; border:1px solid #e4e1db; border-radius:0.75rem; padding:1.25rem; }
label { display:block; font-size:0.75rem; font-weight:500; margin:0 0 0.25rem; color:#6b6d73; }
input[type=text], textarea, select {
  width:100%; padding:0.4375rem 0.5rem; font:inherit; font-size:0.875rem; background:#faf9f6;
  border:1px solid #d9d5ce; border-radius:0.375rem; }
textarea { min-height:3.25rem; resize:vertical; }
select { width:auto; }
.grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(11rem, 1fr)); gap:0.75rem; }
.chrow { display:grid; grid-template-columns:6rem 1fr 1.4fr; gap:0.5rem; margin:0 0 0.5rem; }
.fields { display:grid; gap:0.625rem; }
.qblock { border-top:1px solid #efece7; padding:0.875rem 0 0; margin:0.875rem 0 0; }
.qblock:first-child { border-top:0; padding-top:0; margin-top:0; }
.qname { font-weight:600; font-size:0.875rem; margin:0 0 0.5rem; }
.qname span { font-weight:400; color:#6b6d73; font-size:0.8125rem; }
button { padding:0.5rem 0.875rem; font:inherit; font-size:0.875rem; font-weight:500;
         cursor:pointer; background:#1a1c1f; color:#fff; border:0; border-radius:0.375rem; }
button.quiet { background:#faf9f6; color:#1a1c1f; border:1px solid #d9d5ce; }
button[disabled] { opacity:0.45; cursor:not-allowed; }
.note code { background:#f0ede8; padding:0.0625rem 0.3125rem; border-radius:0.25rem; }
.note { padding:0.625rem 0.75rem; border-radius:0.5rem; margin:0 0 1rem; font-size:0.875rem; }
.ok { background:#e8f0eb; color:#2f6248; }
.warn { background:#f7eedd; color:#9a6410; }
.check { display:flex; gap:0.5rem; align-items:flex-start; font-size:0.875rem; color:#1a1c1f; }
.check input { margin-top:0.2rem; }
.prev { background:#faf9f6; border:1px dashed #d9d5ce; border-radius:0.5rem; padding:0.75rem;
        font-size:0.8125rem; color:#6b6d73; }
.prev b { color:#1a1c1f; font-weight:600; }
/* Sengaja tidak menempel di dasar layar: formulirnya panjang, dan batang yang menempel
   menutupi baris yang sedang dibaca orangnya. Menyimpan dilakukan sekali di akhir. */
.bar { padding:1.25rem 0 0; margin-top:1.5rem; border-top:1px solid #e4e1db;
       display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap; }
.bar span { color:#6b6d73; font-size:0.8125rem; }
a { color:#1a1c1f; }
</style>
</head>
<body>
<main>
  <h1>Pertanyaan MESSI</h1>
  <p class="sub"><a href="index.php">← kembali ke laporan</a> · <a href="admin.php">Orang &amp; tim</a></p>

  <?php if (count($teams) > 1): ?>
    <p class="why" style="margin:0 0 0.5rem">Pertanyaan milik tim. Yang di bawah ini
       berlaku untuk:</p>
    <p style="margin:0 0 1.5rem">
      <?php foreach ($teams as $t): ?>
        <a href="?team=<?= (int) $t['id'] ?>" style="margin-right:0.75rem;<?=
           $t['id'] === $teamId ? 'font-weight:600' : 'color:#6b6d73' ?>"><?= h($t['name']) ?></a>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>

  <?php if ($notice): ?><p class="note ok"><?= h($notice) ?></p><?php endif; ?>
  <?php if ($noTable): ?>
    <p class="note warn"><strong>Belum bisa disimpan.</strong> Tabel <code>settings</code>
       belum ada di database ini, jadi yang di bawah adalah pertanyaan bawaan dan
       perubahannya tidak akan tersimpan. Laporan harian tetap jalan seperti biasa.<br>
       <?= messi_import_again() ?></p>
  <?php endif; ?>

  <form method="post">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="team" value="<?= (int) $teamId ?>">

  <h2>Nama tim</h2>
  <p class="why">Dicetak di kepala laporan. Kosongkan kalau tidak perlu.</p>
  <div class="card">
    <input type="text" name="team_name" maxlength="60" value="<?= h($cfg['team_name']) ?>"
           placeholder="mis. OASYS">
  </div>

  <h2>Space Google Chat tim ini</h2>
  <p class="why">Ke sinilah pengingat jam buka dan jam tutup dikirim. Kosongkan untuk
     memakai space yang ada di <code>config.php</code>. Alamat ini rahasia — siapa pun
     yang memilikinya bisa menulis ke space itu — jadi tidak pernah ditampilkan kembali
     di sini, bahkan kepada admin.</p>
  <div class="card">
    <input type="hidden" name="chat_webhook_keep" value="1">
    <label><?= $cfg['chat_webhook'] === ''
      ? 'Belum diisi — memakai space di config.php'
      : 'Sudah diisi. Isi kotak ini hanya kalau mau menggantinya.' ?></label>
    <input type="text" name="chat_webhook" value="" autocomplete="off"
           placeholder="https://chat.googleapis.com/v1/spaces/…">
  </div>

  <h2>Channel</h2>
  <p class="why">Satu baris per channel yang dihitung tiap hari. Kode dipakai sebagai kunci
     angkanya — huruf dan angka saja. Kosongkan kodenya untuk menghapus barisnya.
     <?php if ($sent): ?><br><strong>Ada <?= $sent ?> laporan yang sudah terkirim.</strong>
     Laporan lama tetap dibaca dengan channel yang berlaku saat dikirim, jadi isinya tidak
     berubah — tapi channel yang dihapus tidak lagi dihitung mulai laporan berikutnya.
     <?php endif; ?></p>
  <div class="card">
    <div class="chrow"><label>Kode</label><label>Nama pendek</label><label>Nama panjang</label></div>
    <?php $i = 0; foreach ($cfg['channels'] as $c): ?>
      <div class="chrow">
        <input type="text" name="ch[<?= $i ?>][key]" value="<?= h($c['key']) ?>" maxlength="12">
        <input type="text" name="ch[<?= $i ?>][label]" value="<?= h($c['label']) ?>" maxlength="24">
        <input type="text" name="ch[<?= $i ?>][full]" value="<?= h($c['full']) ?>" maxlength="60">
      </div>
    <?php $i++; endforeach; ?>
    <?php for ($n = 0; $n < 3; $n++, $i++): ?>
      <div class="chrow">
        <input type="text" name="ch[<?= $i ?>][key]" maxlength="12" placeholder="baru">
        <input type="text" name="ch[<?= $i ?>][label]" maxlength="24">
        <input type="text" name="ch[<?= $i ?>][full]" maxlength="60">
      </div>
    <?php endfor; ?>
  </div>

  <h2>Ambang dan jam</h2>
  <p class="why">Gantung lebih dari batasnya membuat laporan merah. Setelah jam tutup,
     laporan masih bisa dikirim tapi ditandai telat.</p>
  <div class="card">
    <div class="grid">
      <div><label>Batas gantung (hari)</label>
        <select name="threshold_days">
          <?php foreach ([1,2,3,5,7,14,30] as $d): ?>
            <option value="<?= $d ?>" <?= $d === $days ? 'selected' : '' ?>><?= $d ?> hari</option>
          <?php endforeach; ?>
        </select></div>
      <div><label>Jam buka</label><?= $hours('open_hour', $cfg['open_hour'], 0, 23) ?></div>
      <div><label>Jam tutup</label><?= $hours('due_hour', $cfg['due_hour'], 1, 24) ?></div>
      <div><label>Maksimal rencana per laporan</label>
        <select name="max_plans">
          <?php foreach ([1,2,3,5,8,10] as $m): ?>
            <option value="<?= $m ?>" <?= $m === $cfg['max_plans'] ? 'selected' : '' ?>><?= $m ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <p class="prev" style="margin:1rem 0 0">Jadinya: kolomnya terbaca
      <b>Gantung &gt;<?= $days ?> hari</b> dan <b>Gantung &lt;<?= $days ?> hari</b>,
      lampunya <b>"Ada yang gantung lebih dari <?= $days ?> hari"</b>, dan satu jam sebelum
      <b><?= sprintf('%02d:00', $cfg['due_hour']) ?></b> yang belum lapor diingatkan.</p>
  </div>

  <h2>Pertanyaannya</h2>
  <p class="why">Kalimat yang dibaca pemain. Pesan kesalahan muncul kalau jawabannya
     dikosongkan — ditulis sebagai kalimat yang memberi tahu harus apa, bukan sekadar
     "wajib diisi".</p>
  <div class="card">
    <?php
    $labels = [
        'grid'       => ['Langkah 1 — angka', 'judul layar angkanya'],
        'detail'     => ['Yang mana saja', 'muncul kalau ada yang gantung'],
        'plan'       => ['Rencana', 'tiap baris rencana'],
        'due'        => ['Tanggal beres', 'jadi janji yang ditagih di tanggal itu'],
        'escalation' => ['Minta bantuan', 'diumumkan ke chat begitu diisi'],
        'prista'     => ['Calon project', 'boleh dikosongkan pemain'],
    ];
    $fields = ['label' => 'Pertanyaan', 'hint' => 'Keterangan kecil',
               'placeholder' => 'Contoh isian', 'error' => 'Kalau dikosongkan'];
    foreach ($q as $name => $one): ?>
      <div class="qblock">
        <p class="qname"><?= h($labels[$name][0]) ?> <span>— <?= h($labels[$name][1]) ?></span></p>
        <div class="fields">
          <?php foreach ($fields as $f => $title): if (!isset($one[$f])) { continue; } ?>
            <div><label><?= h($title) ?></label>
              <input type="text" name="q[<?= h($name) ?>][<?= $f ?>]"
                     value="<?= h((string) $one[$f]) ?>" maxlength="300"></div>
          <?php endforeach; ?>
          <?php if (isset($one['show'])): ?>
            <label class="check"><input type="checkbox" name="q[<?= h($name) ?>][show]" value="1"
              <?= $one['show'] ? 'checked' : '' ?>>
              <span>Tanyakan pertanyaan ini. Kalau dimatikan, pertanyaannya hilang dari
                layar dan barisnya hilang dari laporan.</span></label>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <h2>Pernyataan</h2>
  <p class="why">Dicentang pemain sebelum mengirim, dan dicetak di kaki laporan.</p>
  <div class="card">
    <textarea name="declaration" maxlength="600"><?= h($cfg['declaration']) ?></textarea>
  </div>

  <div class="bar">
    <button type="submit"<?= $noTable ? ' disabled' : '' ?>>Simpan</button>
    <span>Berlaku untuk laporan berikutnya. Laporan yang sudah terkirim tidak berubah.</span>
  </div>
  </form>

  <form method="post" style="margin-top:1rem">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="do" value="reset">
    <button class="quiet" type="submit"
      onclick="return confirm('Kembalikan semua pertanyaan ke bawaannya?')">
      Kembalikan ke bawaan</button>
  </form>
</main>
</body>
</html>
