<?php
/**
 * Membuat modul sendiri.
 *
 * Sampai halaman ini ada, menambah modul berarti menulis program — jadi perusahaan yang
 * memakai FURA tidak bisa menambah apa pun sendiri, dan tiap permintaan kecil harus
 * lewat kami. Di sini modul jadi sesuatu yang disusun, bukan diprogram: beri nama, susun
 * pertanyaannya, tentukan jamnya, selesai.
 *
 * Satu aturan yang membentuk seluruh halaman ini: *tiap tombol menyimpan dulu*. Menambah
 * pertanyaan, menghapus, menggeser ke atas — semuanya mengirim seluruh formulir, lalu
 * mengerjakan satu perubahan kecil. Tanpa itu, menekan "tambah pertanyaan" setelah
 * mengetik lima menit akan membuang lima menit itu, dan orang berhenti mempercayai
 * tombol-tombolnya.
 */

declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require __DIR__ . '/lib/require-php8.php';

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/repo.php';
require_once __DIR__ . '/lib/katalog.php';
require_once __DIR__ . '/lib/layout.php';

messi_require_current();

$me = auth_user();
if (!$me) { header('Location: login.php'); exit; }
if (!is_manager($me)) { http_response_code(403); exit('Halaman ini untuk admin.'); }

$teams = repo_teams();
if (!$teams) {
    repo_default_team();
    $teams = repo_teams();
}
$teamId = (int) ($_POST['team'] ?? $_GET['team'] ?? 0);
if (!isset($teams[$teamId])) {
    $teamId = repo_team_of($me);
}
if (!isset($teams[$teamId])) {
    $teamId = (int) array_key_first($teams);
}

// Tiap tim yang belum punya modul mendapat MESSI-nya sendiri. Katalog kosong tidak
// memberi tahu apa pun tentang apa yang bisa dibuat di sini.
katalog_seed($teamId);

$notice = null;
$error  = null;

/**
 * Membaca formulir jadi dokumen modul.
 *
 * Yang dibaca di sini adalah apa yang sedang ada di layar, bukan apa yang tersimpan —
 * karena tiap tombol di halaman ini menyimpan dulu, lalu mengubah satu hal.
 */
function modul_dari_form(array $post): array
{
    $fields = [];
    foreach (is_array($post['f'] ?? null) ? $post['f'] : [] as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $f = [
            'id'    => $raw['id'] ?? '',
            'type'  => $raw['type'] ?? 'text',
            'label' => $raw['label'] ?? '',
            'hint'  => $raw['hint'] ?? '',
            'when'  => $raw['when'] ?? 'selalu',
            // Kotak centang yang tidak dicentang tidak ikut terkirim sama sekali.
            'required' => isset($raw['required']),
            'announce' => isset($raw['announce']),
            'placeholder' => $raw['placeholder'] ?? '',
            'error' => $raw['error'] ?? '',
            'due_label' => $raw['due_label'] ?? '',
            'due_error' => $raw['due_error'] ?? '',
            'max'   => $raw['max'] ?? null,
            'min'   => $raw['min'] ?? null,
        ];
        if (($raw['type'] ?? '') === 'choice') {
            // Satu pilihan per baris: lebih enak ditempel daripada sepuluh kotak terpisah.
            $f['options'] = preg_split('/\R/u', (string) ($raw['options'] ?? '')) ?: [];
        }
        if (($raw['type'] ?? '') === 'grid') {
            $f['rows'] = array_values(is_array($raw['rows'] ?? null) ? $raw['rows'] : []);
            $f['cols'] = array_values(is_array($raw['cols'] ?? null) ? $raw['cols'] : []);
        }
        $fields[] = $f;
    }

    return [
        'key'   => $post['key'] ?? '',
        'name'  => $post['name'] ?? '',
        'full'  => $post['full'] ?? '',
        'what'  => $post['what'] ?? '',
        'intro' => $post['intro'] ?? '',
        'open_hour'   => $post['open_hour'] ?? null,
        'due_hour'    => $post['due_hour'] ?? null,
        'workdays'    => is_array($post['workdays'] ?? null) ? $post['workdays'] : [],
        'declaration' => $post['declaration'] ?? '',
        'fields'      => $fields,
    ];
}

/**
 * Satu pertanyaan baru dari jenis yang diminta, lengkap dengan contoh seadanya.
 *
 * array_replace, bukan "+": pada penggabungan array PHP yang kiri menang, jadi label
 * kosong di $dasar akan mengalahkan label contoh di sebelah kanan — dan tiap pertanyaan
 * yang baru ditambahkan lahir tanpa judul.
 */
function modul_field_baru(string $type, int $n): array
{
    $dasar = ['id' => 'f' . $n, 'type' => $type, 'label' => '', 'when' => 'selalu'];
    if ($type === 'grid') {
        return array_replace($dasar, [
            'label' => 'Berapa banyak hari ini?',
            'rows' => [['key' => 'WAG', 'label' => 'WAG', 'full' => 'WhatsApp Group']],
            'cols' => [['key' => 'open', 'label' => 'Masih aktif', 'flag' => ''],
                       ['key' => 'perlu', 'label' => 'Perlu ditindak', 'flag' => 'merah']],
        ]);
    }
    if ($type === 'plans') {
        return array_replace($dasar,
            ['label' => 'Mau diapakan?', 'due_label' => 'Kapan beres?', 'max' => 5]);
    }
    if ($type === 'choice') {
        return array_replace($dasar,
            ['label' => 'Pilih salah satu', 'options' => ['Jalan', 'Berhenti']]);
    }
    return array_replace($dasar, ['label' => 'Pertanyaan baru']);
}

/* ------------------------------------------------------------------- POST */

$editId = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$spec = null;                                  // yang sedang digarap, kalau sedang mengedit

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $do = (string) ($_POST['do'] ?? '');

    try {
        if ($do === 'new') {
            $baru = modul_default((string) ($_POST['newkey'] ?? ''),
                                  (string) ($_POST['newname'] ?? ''));
            if ($baru['key'] === 'MODUL' && trim((string) ($_POST['newkey'] ?? '')) === '') {
                $error = 'Isi dulu kodenya — huruf dan angka saja, misalnya PRISTA.';
            } else {
                $editId = katalog_save($me, $teamId, null, $baru);
                $notice = 'Modul ' . $baru['key'] . ' dibuat. Sekarang susun pertanyaannya.';
            }

        } elseif ($do === 'aktif' || $do === 'nonaktif') {
            katalog_set_active($me, $teamId, (int) $_POST['id'], $do === 'aktif');
            $notice = $do === 'aktif' ? 'Modul dinyalakan.' : 'Modul dimatikan dari katalog.';
            $editId = 0;

        } elseif ($do === 'naik' || $do === 'turun') {
            katalog_move($me, $teamId, (int) $_POST['id'], $do === 'naik' ? -1 : 1);
            $editId = 0;

        } elseif ($do === 'hapus') {
            katalog_delete($me, $teamId, (int) $_POST['id']);
            $notice = 'Modul dihapus.';
            $editId = 0;

        } elseif (in_array($do, ['simpan', 'addfield', 'rmfield', 'fup', 'fdown'], true)) {
            // Semuanya berangkat dari keadaan layar, lalu satu perubahan kecil.
            $kerja = modul_dari_form($_POST);

            if ($do === 'addfield') {
                $jenis = (string) ($_POST['newtype'] ?? 'text');
                $jenis = in_array($jenis, MODUL_FIELD_TYPES, true) ? $jenis : 'text';
                $kerja['fields'][] = modul_field_baru($jenis, count($kerja['fields']) + 1);
            } elseif ($do === 'rmfield') {
                $i = (int) ($_POST['i'] ?? -1);
                if (isset($kerja['fields'][$i])) {
                    array_splice($kerja['fields'], $i, 1);
                }
            } elseif ($do === 'fup' || $do === 'fdown') {
                $i = (int) ($_POST['i'] ?? -1);
                $j = $i + ($do === 'fup' ? -1 : 1);
                if (isset($kerja['fields'][$i], $kerja['fields'][$j])) {
                    [$kerja['fields'][$i], $kerja['fields'][$j]] =
                        [$kerja['fields'][$j], $kerja['fields'][$i]];
                }
            }

            $editId = katalog_save($me, $teamId, $editId ?: null, $kerja);
            if ($do === 'simpan') {
                $notice = 'Tersimpan. Yang tampil di bawah adalah yang sekarang berlaku.';
            }
        }
    } catch (RepoError $e) {
        $error = $e->getMessage();
    }
}

$modules = katalog_list($teamId, true);
$edit = $editId ? katalog_get($teamId, $editId) : null;
// MESSI tidak disusun di sini — dia adalah halaman Pertanyaan, dibaca sebagai modul.
// Yang membuka "Susun" untuknya diantar ke sana, bukan dibiarkan mengetik di formulir
// yang tulisannya akan ditimpa begitu setelan timnya disimpan.
$keMessi = $edit && $edit['code'] === MODUL_MESSI;
if ($keMessi) {
    $edit = null;
}
$spec = $edit['spec'] ?? null;
$csrf = csrf_token();

$jamPilih = function (string $name, int $now, int $from, int $to): string {
    $out = '';
    for ($h = $from; $h <= $to; $h++) {
        $out .= '<option value="' . $h . '"' . ($h === $now ? ' selected' : '') . '>'
             . sprintf('%02d:00', $h) . '</option>';
    }
    return '<select name="' . h($name) . '">' . $out . '</select>';
};

$namaHari = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];
$namaJenis = [
    'grid'     => ['Angka', 'tabel baris × kolom; dari sinilah lampu merahnya'],
    'text'     => ['Isian pendek', 'satu baris'],
    'textarea' => ['Isian panjang', 'beberapa baris'],
    'number'   => ['Satu angka', 'tanpa tabel'],
    'choice'   => ['Pilihan', 'pilih satu dari daftar'],
    'plans'    => ['Rencana + tanggal', 'tiap baris jadi janji yang ditagih di tanggalnya'],
];

page_head('Modul', ['me' => $me, 'wide' => true, 'css' => <<<'CSS'
.mods td b { display:block }
.mods td span { color:var(--muted); font-size:0.8125rem }
.mati td { opacity:0.55 }
.fld { border:1px solid var(--line); border-radius:0.75rem; padding:1rem 1.125rem;
       margin:0 0 0.75rem; background:var(--surface) }
.fld .kepala { display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;
               margin:0 0 0.75rem }
.fld .kepala b { font-size:0.9375rem }
.fld .kepala .jenis { color:var(--muted); font-size:0.8125rem }
.fld .kepala .alat { margin-left:auto; display:flex; gap:0.25rem }
.fld .isi { display:grid; gap:0.625rem }
.chrow { display:grid; grid-template-columns:6rem 1fr 1.2fr 7rem; gap:0.5rem; margin:0 0 0.5rem }
.chrow.judul label { margin:0 }
@media (max-width: 40rem) {
  .chrow { grid-template-columns:1fr; gap:0.375rem; margin:0 0 0.875rem;
           padding:0 0 0.875rem; border-bottom:1px solid var(--line) }
  .chrow.judul { display:none }
}
.hari { display:flex; flex-wrap:wrap; gap:0.75rem }
.hari label { display:flex; align-items:center; gap:0.3125rem; font-size:0.875rem;
              color:var(--ink); font-weight:400; margin:0 }
.pilih { display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap }
.bar { padding:1.25rem 0 0; margin-top:1.5rem; border-top:1px solid var(--line);
       display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap }
.bar span { color:var(--muted); font-size:0.8125rem }
CSS]);
?>
  <h1>Modul</h1>
  <p class="sub"><a href="kelola.php">‹ Kelola</a> · <a href="soal.php">Pertanyaan MESSI</a></p>

  <?php if (count($teams) > 1): ?>
    <p class="why" style="margin:0 0 0.5rem">Modul milik tim. Yang di bawah ini milik:</p>
    <p style="margin:0 0 1.5rem">
      <?php foreach ($teams as $t): ?>
        <a href="?team=<?= (int) $t['id'] ?>" style="margin-right:0.75rem;<?=
           (int) $t['id'] === $teamId ? 'font-weight:600' : 'color:var(--muted)' ?>"><?=
           h($t['name']) ?></a>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>

  <?php if ($notice): ?><p class="note ok"><?= h($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="note bad"><?= h($error) ?></p><?php endif; ?>
  <?php if ($keMessi): ?>
    <p class="note warn"><strong>MESSI disusun di halaman Pertanyaan.</strong>
       Pertanyaan, ambang gantung dan jamnya diubah di sana, dan yang terbaca di sini
       mengikutinya sendiri. <a href="soal.php?team=<?= $teamId ?>">Buka Pertanyaan
       MESSI</a>.</p>
  <?php endif; ?>

  <div class="card scroll">
    <table class="mods">
      <tr><th>Modul</th><th>Pertanyaan</th><th>Jam</th><th></th></tr>
      <?php foreach ($modules as $i => $m): ?>
      <tr class="<?= $m['active'] ? '' : 'mati' ?>">
        <td><b><?= h($m['spec']['name']) ?></b>
            <span><?= h($m['code']) ?><?= $m['spec']['full'] === '' ? ''
                  : ' · ' . h($m['spec']['full']) ?></span></td>
        <td><?= count($m['spec']['fields']) ?> pertanyaan</td>
        <td><?= sprintf('%02d:00', $m['spec']['open_hour']) ?>–<?=
            sprintf('%02d:00', $m['spec']['due_hour']) ?></td>
        <td>
          <?php $tetap = $m['code'] === MODUL_MESSI; ?>
          <?php if ($tetap): ?>
            <a href="soal.php?team=<?= $teamId ?>">Ubah di Pertanyaan</a>
          <?php else: ?>
            <a href="?team=<?= $teamId ?>&amp;id=<?= $m['id'] ?>">Susun</a>
          <?php endif; ?>
          <form class="row" method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="team" value="<?= $teamId ?>">
            <input type="hidden" name="id" value="<?= $m['id'] ?>">
            <?php if (!$tetap): ?>
              <button class="quiet" type="submit" name="do"
                      value="<?= $m['active'] ? 'nonaktif' : 'aktif' ?>"><?=
                $m['active'] ? 'Matikan' : 'Nyalakan' ?></button>
            <?php endif; ?>
            <?php if ($i > 0): ?>
              <button class="quiet" type="submit" name="do" value="naik" title="Naikkan">↑</button>
            <?php endif; ?>
            <?php if ($i < count($modules) - 1): ?>
              <button class="quiet" type="submit" name="do" value="turun" title="Turunkan">↓</button>
            <?php endif; ?>
            <?php if (!$tetap): ?>
              <button class="quiet" type="submit" name="do" value="hapus"
                onclick="return confirm('Hapus modul ini? Hanya bisa kalau belum pernah ada laporannya.')"
                >Hapus</button>
            <?php endif; ?>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <p class="why" style="margin:0.75rem 0 0">MESSI diubah di
     <a href="soal.php?team=<?= $teamId ?>">Pertanyaan</a>, dan tidak bisa dimatikan atau
     dihapus — dialah satu-satunya modul yang punya layar pengisian, jadi tanpa dia tidak
     ada laporan harian.</p>

  <h2>Modul baru</h2>
  <p class="note warn">Modul yang kamu susun di sini sudah tersimpan utuh, tapi
     <strong>layar pengisian hariannya belum ada</strong> — untuk sekarang yang bisa diisi
     pemain cuma MESSI. Dikatakan di depan supaya tidak ada yang menyusun modul sepuluh
     pertanyaan lalu menunggu laporan yang tidak akan pernah masuk.</p>
  <p class="why">Kodenya dipakai laporan untuk menunjuk balik ke modulnya, jadi tidak bisa
     diganti setelah dibuat. Huruf dan angka saja, pendek — seperti MESSI atau PRISTA.</p>
  <div class="card">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="team" value="<?= $teamId ?>">
      <input type="hidden" name="do" value="new">
      <div class="grid">
        <div><label for="nk">Kode</label>
          <input id="nk" name="newkey" type="text" maxlength="12" placeholder="PRISTA" required></div>
        <div><label for="nn">Nama</label>
          <input id="nn" name="newname" type="text" maxlength="40" placeholder="PRISTA"></div>
      </div>
      <p style="margin:1rem 0 0"><button type="submit">Buat modul</button></p>
    </form>
  </div>

<?php if ($spec): ?>
  <h2 style="margin-top:2.5rem">Susun: <?= h($spec['name']) ?>
    <span class="tag"><?= h($edit['code']) ?></span></h2>
  <p class="why">Tiap tombol di bawah menyimpan dulu, baru mengerjakan perubahannya —
     jadi menambah atau menggeser pertanyaan tidak pernah membuang yang sudah kamu ketik.</p>

  <form method="post">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="team" value="<?= $teamId ?>">
  <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
  <input type="hidden" name="key" value="<?= h($spec['key']) ?>">

  <h3>Yang terlihat di katalog</h3>
  <div class="card">
    <div class="grid">
      <div><label for="mn">Nama</label>
        <input id="mn" name="name" type="text" maxlength="40" value="<?= h($spec['name']) ?>"></div>
      <div><label for="mf">Kepanjangannya</label>
        <input id="mf" name="full" type="text" maxlength="60" value="<?= h($spec['full']) ?>"
               placeholder="mis. Project Status"></div>
    </div>
    <p style="margin:0.75rem 0 0"><label for="mw">Satu kalimat di kartunya</label>
      <input id="mw" name="what" type="text" maxlength="300" value="<?= h($spec['what']) ?>"></p>
    <p style="margin:0.75rem 0 0"><label for="mi">Penjelasan di "Apa ini?"</label>
      <textarea id="mi" name="intro" maxlength="1200" rows="3"
        placeholder="Buat apa modul ini, dan kenapa diisi tiap hari."><?= h($spec['intro']) ?></textarea></p>
  </div>

  <h3>Kapan diisi</h3>
  <div class="card">
    <div class="grid">
      <div><label>Jam buka</label><?= $jamPilih('open_hour', $spec['open_hour'], 0, 23) ?></div>
      <div><label>Jam tutup</label><?= $jamPilih('due_hour', $spec['due_hour'], 1, 24) ?></div>
    </div>
    <p style="margin:0.875rem 0 0.375rem"><label>Hari</label></p>
    <div class="hari">
      <?php foreach ($namaHari as $n => $nama): ?>
        <label><input type="checkbox" name="workdays[]" value="<?= $n ?>"
          <?= in_array($n, $spec['workdays'], true) ? 'checked' : '' ?>><?= $nama ?></label>
      <?php endforeach; ?>
    </div>
    <p class="why" style="margin:0.75rem 0 0">Pengingat dikirim satu jam sebelum jam tutup,
       dan cuma di hari yang dicentang.</p>
  </div>

  <h3>Pertanyaannya</h3>
  <?php foreach ($spec['fields'] as $i => $f): ?>
    <div class="fld">
      <div class="kepala">
        <b><?= $i + 1 ?>.</b>
        <span class="jenis"><?= h($namaJenis[$f['type']][0]) ?> — <?=
          h($namaJenis[$f['type']][1]) ?></span>
        <span class="alat">
          <?php if ($i > 0): ?>
            <button class="quiet" type="submit" name="do" value="fup"
                    onclick="this.form.i.value=<?= $i ?>" title="Naikkan">↑</button>
          <?php endif; ?>
          <?php if ($i < count($spec['fields']) - 1): ?>
            <button class="quiet" type="submit" name="do" value="fdown"
                    onclick="this.form.i.value=<?= $i ?>" title="Turunkan">↓</button>
          <?php endif; ?>
          <button class="quiet" type="submit" name="do" value="rmfield"
                  onclick="this.form.i.value=<?= $i ?>; return confirm('Hapus pertanyaan ini?')"
                  title="Hapus">×</button>
        </span>
      </div>
      <input type="hidden" name="f[<?= $i ?>][type]" value="<?= h($f['type']) ?>">
      <input type="hidden" name="f[<?= $i ?>][id]" value="<?= h($f['id']) ?>">
      <div class="isi">
        <div><label>Pertanyaan</label>
          <input type="text" name="f[<?= $i ?>][label]" maxlength="300"
                 value="<?= h($f['label']) ?>"></div>
        <div><label>Keterangan kecil</label>
          <input type="text" name="f[<?= $i ?>][hint]" maxlength="300"
                 value="<?= h($f['hint']) ?>"></div>

        <?php if ($f['type'] === 'grid'): ?>
          <p class="why" style="margin:0.25rem 0 0">Baris biasanya channel atau sumber;
             kolom adalah yang dihitung. Kolom bertanda <b>merah</b> yang menyalakan lampu
             laporan — dan pertanyaan bersyarat di bawahnya baru ditanyakan kalau menyala.
             Kosongkan kodenya untuk menghapus barisnya.</p>
          <div>
            <div class="chrow judul"><label>Kode</label><label>Nama pendek</label>
              <label>Nama panjang</label><label></label></div>
            <?php $n = 0; foreach ($f['rows'] as $r): ?>
              <div class="chrow">
                <input type="text" name="f[<?= $i ?>][rows][<?= $n ?>][key]"
                       value="<?= h($r['key']) ?>" maxlength="12" placeholder="Kode">
                <input type="text" name="f[<?= $i ?>][rows][<?= $n ?>][label]"
                       value="<?= h($r['label']) ?>" maxlength="24" placeholder="Nama pendek">
                <input type="text" name="f[<?= $i ?>][rows][<?= $n ?>][full]"
                       value="<?= h($r['full']) ?>" maxlength="60" placeholder="Nama panjang">
                <span></span>
              </div>
            <?php $n++; endforeach; ?>
            <?php for ($b = 0; $b < 2; $b++, $n++): ?>
              <div class="chrow">
                <input type="text" name="f[<?= $i ?>][rows][<?= $n ?>][key]" maxlength="12"
                       placeholder="Baris baru">
                <input type="text" name="f[<?= $i ?>][rows][<?= $n ?>][label]" maxlength="24"
                       placeholder="Nama pendek">
                <input type="text" name="f[<?= $i ?>][rows][<?= $n ?>][full]" maxlength="60"
                       placeholder="Nama panjang">
                <span></span>
              </div>
            <?php endfor; ?>
          </div>
          <div>
            <div class="chrow judul"><label>Kode kolom</label><label>Judul kolom</label>
              <label></label><label>Tanda</label></div>
            <?php $n = 0; foreach ($f['cols'] as $c): ?>
              <div class="chrow">
                <input type="text" name="f[<?= $i ?>][cols][<?= $n ?>][key]"
                       value="<?= h($c['key']) ?>" maxlength="12" placeholder="Kode kolom">
                <input type="text" name="f[<?= $i ?>][cols][<?= $n ?>][label]"
                       value="<?= h($c['label']) ?>" maxlength="40" placeholder="Judul kolom">
                <span></span>
                <select name="f[<?= $i ?>][cols][<?= $n ?>][flag]">
                  <option value="" <?= $c['flag'] === '' ? 'selected' : '' ?>>biasa</option>
                  <option value="merah" <?= $c['flag'] === 'merah' ? 'selected' : '' ?>>merah</option>
                  <option value="hijau" <?= $c['flag'] === 'hijau' ? 'selected' : '' ?>>hijau</option>
                </select>
              </div>
            <?php $n++; endforeach; ?>
            <div class="chrow">
              <input type="text" name="f[<?= $i ?>][cols][<?= $n ?>][key]" maxlength="12"
                     placeholder="Kolom baru">
              <input type="text" name="f[<?= $i ?>][cols][<?= $n ?>][label]" maxlength="40"
                     placeholder="Judul kolom">
              <span></span>
              <select name="f[<?= $i ?>][cols][<?= $n ?>][flag]">
                <option value="">biasa</option><option value="merah">merah</option>
                <option value="hijau">hijau</option>
              </select>
            </div>
          </div>

        <?php else: ?>
          <div><label>Contoh isian</label>
            <input type="text" name="f[<?= $i ?>][placeholder]" maxlength="300"
                   value="<?= h($f['placeholder']) ?>"></div>
          <div><label>Kalau dikosongkan</label>
            <input type="text" name="f[<?= $i ?>][error]" maxlength="300"
                   value="<?= h($f['error']) ?>"></div>

          <?php if ($f['type'] === 'choice'): ?>
            <div><label>Pilihannya — satu per baris</label>
              <textarea name="f[<?= $i ?>][options]" rows="3"><?=
                h(implode("\n", $f['options'])) ?></textarea></div>
          <?php endif; ?>

          <?php if ($f['type'] === 'number'): ?>
            <div class="grid">
              <div><label>Paling kecil</label>
                <input type="text" name="f[<?= $i ?>][min]" value="<?= (int) $f['min'] ?>"></div>
              <div><label>Paling besar</label>
                <input type="text" name="f[<?= $i ?>][max]" value="<?= (int) $f['max'] ?>"></div>
            </div>
          <?php endif; ?>

          <?php if ($f['type'] === 'plans'): ?>
            <div><label>Pertanyaan tanggalnya</label>
              <input type="text" name="f[<?= $i ?>][due_label]" maxlength="300"
                     value="<?= h($f['due_label']) ?>"></div>
            <div><label>Kalau tanggalnya dikosongkan</label>
              <input type="text" name="f[<?= $i ?>][due_error]" maxlength="300"
                     value="<?= h($f['due_error']) ?>"></div>
            <div><label>Maksimal baris</label>
              <input type="text" name="f[<?= $i ?>][max]" value="<?= (int) $f['max'] ?>"></div>
            <p class="why" style="margin:0">Tiap baris jadi janji: di tanggalnya orangnya
               ditanya "sudah beres?", dan yang lewat tanpa dijawab ditandai tidak ditepati.</p>
          <?php endif; ?>

          <?php if (in_array($f['type'], ['text', 'textarea'], true)): ?>
            <label class="check"><input type="checkbox" name="f[<?= $i ?>][announce]" value="1"
              <?= !empty($f['announce']) ? 'checked' : '' ?>>
              <span>Umumkan ke chat begitu diisi — tanpa menunggu jam berapa pun.</span></label>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($f['type'] !== 'grid'): ?>
          <div class="pilih">
            <label class="check"><input type="checkbox" name="f[<?= $i ?>][required]" value="1"
              <?= $f['required'] ? 'checked' : '' ?>><span>Wajib diisi</span></label>
            <span style="color:var(--muted)">·</span>
            <label>Ditanyakan</label>
            <select name="f[<?= $i ?>][when]">
              <option value="selalu" <?= $f['when'] === 'selalu' ? 'selected' : '' ?>>selalu</option>
              <option value="merah" <?= $f['when'] === 'merah' ? 'selected' : '' ?>>hanya kalau
                lampunya merah</option>
            </select>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <input type="hidden" name="i" value="-1">

  <div class="card">
    <label for="nt">Tambah pertanyaan</label>
    <div class="pilih">
      <select id="nt" name="newtype">
        <?php foreach ($namaJenis as $k => [$nama, $jelas]): ?>
          <option value="<?= $k ?>"><?= h($nama) ?> — <?= h($jelas) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="quiet" type="submit" name="do" value="addfield">Tambah</button>
    </div>
  </div>

  <h3>Pernyataan</h3>
  <p class="why">Dicentang sebelum mengirim, dan dicetak di kaki laporan. Kosongkan kalau
     modul ini tidak perlu pernyataan.</p>
  <div class="card">
    <textarea name="declaration" maxlength="600" rows="2"><?= h($spec['declaration']) ?></textarea>
  </div>

  <div class="bar">
    <button type="submit" name="do" value="simpan">Simpan</button>
    <span>Berlaku untuk laporan berikutnya. Laporan yang sudah terkirim tidak berubah.</span>
  </div>
  </form>
<?php endif; ?>
<?php
page_foot();
