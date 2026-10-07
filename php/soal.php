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
require_once __DIR__ . '/lib/layout.php';

messi_require_current();

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

/* -------------------------------------------------------------- pesan tes */

// Webhook yang salah tempel tidak memberi tanda apa pun sampai besok jam buka, dan yang
// terlihat besok cuma "botnya mati". Satu tombol di sini memindahkan kabar buruk itu ke
// detik ini, ke orang yang masih memegang alamatnya dan masih ingat dari mana dia
// menyalinnya.
$uji = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['uji'])) {
    require_once __DIR__ . '/lib/chat.php';
    $now = repo_config($teamId);          // sesudah disimpan, bukan sebelumnya
    $nama = $now['team_name'] !== '' ? $now['team_name'] : ($teams[$teamId]['name'] ?? 'tim ini');
    $uji = chat_send('Tes dari FURA. Kalau pesan ini kelihatan, pengingat harian untuk '
                     . $nama . ' akan sampai ke space ini.', 15, $now['chat_webhook']);
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

page_head('Pertanyaan', ['me' => $me, 'wide' => true, 'css' => <<<'CSS'
select { width:auto }
.chrow { display:grid; grid-template-columns:6rem 1fr 1.4fr; gap:0.5rem; margin:0 0 0.5rem }
/* Di layar sempit tiga kotak sebaris berarti ketiganya terlalu sempit untuk dibaca,
   apalagi diisi. Ditumpuk, dan judul kolomnya diganti tulisan di dalam kotaknya. */
@media (max-width: 34rem) {
  .chrow { grid-template-columns:1fr; gap:0.375rem; margin:0 0 0.875rem;
           padding:0 0 0.875rem; border-bottom:1px solid var(--line) }
  .chrow.judul { display:none }
}
.fields { display:grid; gap:0.625rem }
.qblock { border-top:1px solid var(--line); padding:0.875rem 0 0; margin:0.875rem 0 0 }
.qblock:first-child { border-top:0; padding-top:0; margin-top:0 }
.qname { font-weight:600; font-size:0.875rem; margin:0 0 0.5rem }
.qname span { font-weight:400; color:var(--muted); font-size:0.8125rem }
.check { display:flex; gap:0.5rem; align-items:flex-start; font-size:0.875rem; color:var(--ink) }
.check input { margin-top:0.2rem }
.prev { background:var(--raise); border:1px dashed var(--line); border-radius:0.5rem;
        padding:0.75rem; font-size:0.8125rem; color:var(--muted) }
.prev b { color:var(--ink); font-weight:600 }
/* Sengaja tidak menempel di dasar layar: formulirnya panjang, dan batang yang menempel
   menutupi baris yang sedang dibaca orangnya. Menyimpan dilakukan sekali di akhir. */
.bar { padding:1.25rem 0 0; margin-top:1.5rem; border-top:1px solid var(--line);
       display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap }
.bar span { color:var(--muted); font-size:0.8125rem }
CSS]);
?>
  <h1>Pertanyaan MESSI</h1>
  <p class="sub"><a href="kelola.php">‹ Kelola</a> · <a href="admin.php">Orang &amp; tim</a></p>

  <?php if (count($teams) > 1): ?>
    <p class="why" style="margin:0 0 0.5rem">Pertanyaan milik tim. Yang di bawah ini
       berlaku untuk:</p>
    <p style="margin:0 0 1.5rem">
      <?php foreach ($teams as $t): ?>
        <a href="?team=<?= (int) $t['id'] ?>" style="margin-right:0.75rem;<?=
           $t['id'] === $teamId ? 'font-weight:600' : 'color:var(--muted)' ?>"><?= h($t['name']) ?></a>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>

  <?php if ($notice): ?><p class="note ok"><?= h($notice) ?></p><?php endif; ?>
  <?php if ($uji === true): ?>
    <p class="note ok"><strong>Pesan tes terkirim.</strong> Kalau sudah kelihatan di
       space-nya, pengingat harian juga akan sampai ke sana.</p>
  <?php elseif ($uji === false): ?>
    <p class="note bad"><strong>Pesan tes gagal terkirim.</strong> Alamatnya mungkin
       salah tempel atau sudah tidak berlaku — buat ulang webhook-nya dari space itu,
       lalu simpan di sini. Kalau tetap gagal, buka <a href="cek.php">Cek sistem</a>:
       baris <em>Keluar jaringan</em> memberi tahu kalau hostingnya yang memblokir.</p>
  <?php endif; ?>
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
    <p style="margin:0.875rem 0 0"><button class="quiet" type="submit" name="uji" value="1"
       <?= $noTable ? 'disabled' : '' ?>>Simpan lalu kirim pesan tes</button></p>
    <p class="why" style="margin:0.5rem 0 0">Menyimpan seluruh halaman ini dulu, lalu
       mengirim satu pesan pendek ke space-nya — supaya kamu tahu sekarang, bukan besok
       pagi.</p>
  </div>

  <h2>Channel</h2>
  <p class="why">Satu baris per channel yang dihitung tiap hari. Kode dipakai sebagai kunci
     angkanya — huruf dan angka saja. Kosongkan kodenya untuk menghapus barisnya.
     <?php if ($sent): ?><br><strong>Ada <?= $sent ?> laporan yang sudah terkirim.</strong>
     Laporan lama tetap dibaca dengan channel yang berlaku saat dikirim, jadi isinya tidak
     berubah — tapi channel yang dihapus tidak lagi dihitung mulai laporan berikutnya.
     <?php endif; ?></p>
  <div class="card">
    <div class="chrow judul"><label>Kode</label><label>Nama pendek</label><label>Nama panjang</label></div>
    <?php $i = 0; foreach ($cfg['channels'] as $c): ?>
      <div class="chrow">
        <input type="text" name="ch[<?= $i ?>][key]" value="<?= h($c['key']) ?>"
               maxlength="12" placeholder="Kode">
        <input type="text" name="ch[<?= $i ?>][label]" value="<?= h($c['label']) ?>"
               maxlength="24" placeholder="Nama pendek">
        <input type="text" name="ch[<?= $i ?>][full]" value="<?= h($c['full']) ?>"
               maxlength="60" placeholder="Nama panjang">
      </div>
    <?php $i++; endforeach; ?>
    <?php for ($n = 0; $n < 3; $n++, $i++): ?>
      <div class="chrow">
        <input type="text" name="ch[<?= $i ?>][key]" maxlength="12" placeholder="Kode baru">
        <input type="text" name="ch[<?= $i ?>][label]" maxlength="24" placeholder="Nama pendek">
        <input type="text" name="ch[<?= $i ?>][full]" maxlength="60" placeholder="Nama panjang">
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
<?php
page_foot();
