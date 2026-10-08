<?php
/**
 * Satu pintu untuk yang mengelola.
 *
 * Sebelum ini ada empat halaman tanpa satu tempat yang menampungnya — orang & tim,
 * pertanyaan, cek sistem, pemutakhiran — dan pintunya ada di footer halaman laporan.
 * Owner yang baru selesai membuat akun mendarat di katalog modul, dan yang pertama dia
 * butuhkan justru yang paling tersembunyi.
 *
 * Halaman ini dua hal sekaligus. Selama penyiapan belum selesai, dia menuntun tiga
 * langkah yang harus terjadi sebelum sistemnya berguna. Setelah itu dia jadi beranda
 * biasa dan berhenti menyuruh-nyuruh.
 */

declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require __DIR__ . '/lib/require-php8.php';

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/repo.php';
require_once __DIR__ . '/lib/schema.php';
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
$teamId = repo_team_of($me);
if (!isset($teams[$teamId])) {
    $teamId = (int) array_key_first($teams);
}
$cfg = repo_config($teamId);

require_once __DIR__ . '/lib/katalog.php';
$semuaModul = katalog_list($teamId, true);
$modulN     = count(array_filter($semuaModul, fn($m) => $m['active']));
$modulMati  = count($semuaModul) - $modulN;

/* ------------------------------------------------------------------ orang */

$people = q('SELECT role, active, accepted_at, reset_asked_at FROM users')->fetchAll();
$aktif  = count(array_filter($people, fn($p) => (int) $p['active'] === 1
                                             && $p['accepted_at'] !== null));
$belum  = count(array_filter($people, fn($p) => $p['accepted_at'] === null));
// Permintaan link masuk. Kalau mail_from sudah diisi, linknya sudah dikirim sendiri ke
// emailnya dan baris ini hilang begitu orangnya berhasil masuk; kalau belum, admin yang
// harus mengeluarkannya — dan itu satu-satunya jalan pulangnya.
$minta  = count(array_filter($people, fn($p) => ($p['reset_asked_at'] ?? null) !== null));

// Hari izin yang tercatat dari bulan lalu sampai tiga bulan ke depan. Angka, bukan daftar:
// yang dibutuhkan di halaman ini cuma "ada atau belum", sisanya di halamannya sendiri.
$izinN = count(repo_excused(is_manager($me) ? null : repo_team_of($me)));

/* -------------------------------------------------------------- penyiapan */

// Tiga hal harus terjadi sebelum sistemnya berguna, dan sebelum ini tidak ada apa pun
// yang menyebutkannya atau menandai mana yang sudah.
$sudahSoal = (bool) (q_opt('SELECT name FROM settings WHERE name = ? AND team_id = ?',
                           ['messi', $teamId])?->fetch());
$sudahChat = $cfg['chat_webhook'] !== '' || trim((string) cfg('chat_webhook')) !== '';
$sudahOrang = count($people) > 1;

$langkah = [
    ['ok' => $sudahSoal, 'judul' => 'Pastikan pertanyaannya',
     'apa' => 'Nama channel, berapa hari sebuah chat boleh menggantung, jam buka dan jam '
            . 'tutup. Sudah ada isian bawaan — buka sekali untuk memastikan cocok.',
     'ke' => 'soal.php?team=' . $teamId, 'tombol' => 'Buka pertanyaan'],
    ['ok' => $sudahChat, 'judul' => 'Hubungkan Google Chat',
     'apa' => 'Tempat pengingat dikirim tiap pagi dan sore. Setelah ditempel, kirim pesan '
            . 'tes dari halaman yang sama — supaya tahu sekarang, bukan besok jam buka.',
     'ke' => 'soal.php?team=' . $teamId, 'tombol' => 'Tempel webhook'],
    ['ok' => $sudahOrang, 'judul' => 'Undang orangnya',
     'apa' => 'Tempel email mereka sekaligus. Yang keluar adalah satu link per orang; '
            . 'passwordnya dibuat masing-masing, jadi tidak ada yang perlu kamu ketik.',
     'ke' => 'admin.php', 'tombol' => 'Undang orang'],
];
$belumKelar = count(array_filter($langkah, fn($l) => !$l['ok']));

/* ------------------------------------------------------------- kesehatan */

$pending = schema_pending();
$tick  = q1("SELECT ran_at FROM job_log WHERE kind = 'tick' ORDER BY id DESC LIMIT 1");
$kirim = q1("SELECT ran_at FROM job_log
              WHERE kind = 'notify_open' AND detail LIKE '%sent=1%'
              ORDER BY id DESC LIMIT 1");
$salah = q1("SELECT ran_at, kind FROM job_log
              WHERE kind IN ('chat_error', 'tick_error', 'tick_fatal', 'needs_upgrade')
              ORDER BY id DESC LIMIT 1");

$menitLalu = fn(?string $sql) => $sql === null
    ? null : (int) ((time() - strtotime($sql . ' UTC')) / 60);
$tickAge = $menitLalu($tick['ran_at'] ?? null);
// Dua jam lewat sedikit: cron per jam yang sehat selalu meninggalkan denyut dalam
// rentang itu, dan satu jam saja terlalu ketat untuk hosting yang menunda jalannya.
$cronSehat = $tickAge !== null && $tickAge < 130;

$umur = function (?int $m): string {
    if ($m === null) { return 'belum pernah'; }
    if ($m < 90) { return $m . ' menit lalu'; }
    if ($m < 60 * 36) { return (int) round($m / 60) . ' jam lalu'; }
    return (int) round($m / 1440) . ' hari lalu';
};

page_head('Kelola', ['me' => $me, 'css' => <<<'CSS'
.kartu { display:grid; grid-template-columns:repeat(auto-fit, minmax(15rem, 1fr)); gap:0.75rem }
.kartu a { display:block; text-decoration:none; background:var(--surface);
           border:1px solid var(--line); border-radius:0.75rem; padding:1rem 1.125rem }
.kartu a:hover { border-color:var(--ink) }
.kartu b { display:block; font-size:0.9375rem }
.kartu span { display:block; color:var(--muted); font-size:0.8125rem; margin-top:0.25rem }
.kartu em { display:block; font-style:normal; font-size:0.8125rem; margin-top:0.375rem }
.langkah { display:grid; gap:0.625rem }
.langkah > div { display:flex; gap:0.875rem; background:var(--surface);
                 border:1px solid var(--line); border-radius:0.75rem; padding:1rem 1.125rem }
.langkah .tanda { flex:0 0 auto; width:1.5rem; height:1.5rem; border-radius:50%;
                  display:grid; place-items:center; font-size:0.75rem; font-weight:700;
                  background:var(--raise); border:1px solid var(--line); color:var(--muted) }
.langkah .sudah .tanda { background:var(--kept); border-color:var(--kept); color:#fff }
.langkah b { display:block; font-size:0.9375rem }
.langkah p { margin:0.25rem 0 0; color:var(--muted); font-size:0.8125rem }
.langkah .aksi { margin:0.625rem 0 0 }
.langkah .aksi a { display:inline-block; padding:0.5rem 0.875rem; font-size:0.875rem;
                   font-weight:500; background:var(--raise); color:var(--ink);
                   border:1px solid var(--line); border-radius:0.375rem;
                   text-decoration:none }
.langkah .aksi a:hover { border-color:var(--ink) }
.langkah .sudah p, .langkah .sudah .aksi { display:none }
/* ".kartu span" di atas juga mengenai baris ini dan lebih spesifik daripada ".sehat"
   sendirian, jadi display:flex-nya kalah — dan titiknya, yang lebarnya cuma berlaku
   untuk elemen blok, jadi selebar nol. Terlihat seperti titiknya "tidak ada warnanya",
   padahal warnanya benar sejak awal. */
.kartu .sehat { display:flex; align-items:center; gap:0.5rem; font-size:0.8125rem;
                margin:0 0 0.25rem }
.kartu .sehat i { width:0.5rem; height:0.5rem; border-radius:50%; flex:none;
                  background:var(--muted) }
.kartu .sehat.ya i { background:var(--kept) }
.kartu .sehat.tidak i { background:var(--due) }
CSS]);
?>
  <h1>Kelola</h1>
  <p class="sub"><a href="index.php">‹ Laporan</a> ·
     <?= count($teams) === 1 ? h($teams[$teamId]['name']) : count($teams) . ' tim' ?></p>

  <?php if ($pending): ?>
    <p class="note warn"><strong>Database belum sesuai versi ini.</strong>
       <?= count($pending) ?> hal belum dikerjakan, jadi sebagian fitur baru belum bisa
       dipakai. Laporan harian tetap jalan.
       <a href="upgrade.php">Buka pemutakhiran database</a>.</p>
  <?php endif; ?>

  <?php if ($minta): ?>
    <p class="note warn"><strong><?= $minta ?> orang minta link masuk.</strong>
       Mereka lupa passwordnya dan menunggu kamu mengeluarkan link.
       <a href="admin.php">Buka Orang &amp; tim</a>.</p>
  <?php endif; ?>

  <?php if ($belumKelar): ?>
    <h2 style="margin-top:0.5rem">Penyiapan</h2>
    <p class="why">Tiga hal ini yang membuat sistemnya mulai berguna.
       <?= $belumKelar === count($langkah) ? 'Belum ada yang dikerjakan.'
           : (count($langkah) - $belumKelar) . ' dari ' . count($langkah) . ' sudah.' ?>
       Begitu ketiganya beres, bagian ini hilang sendiri.</p>
    <div class="langkah">
      <?php foreach ($langkah as $i => $l): ?>
        <div class="<?= $l['ok'] ? 'sudah' : '' ?>">
          <span class="tanda"><?= $l['ok'] ? '&check;' : $i + 1 ?></span>
          <div>
            <b><?= h($l['judul']) ?></b>
            <p><?= h($l['apa']) ?></p>
            <p class="aksi"><a href="<?= h($l['ke']) ?>"><?= h($l['tombol']) ?></a></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2>Yang bisa diatur</h2>
  <div class="kartu">
    <a href="admin.php">
      <b>Orang &amp; tim</b>
      <span>Undang orang, atur peran, buat tim.</span>
      <em><?= $aktif ?> orang aktif<?= $belum ? ' · ' . $belum . ' belum terima undangan' : '' ?><?=
          $minta ? ' · ' . $minta . ' minta link masuk' : '' ?></em>
    </a>
    <a href="modul.php?team=<?= $teamId ?>">
      <b>Modul</b>
      <span>Susun modul sendiri: pertanyaannya, jamnya, dan apa yang jadi janji.</span>
      <em><?= $modulN ?> modul<?= $modulMati ? ' · ' . $modulMati . ' dimatikan' : '' ?></em>
    </a>
    <a href="soal.php?team=<?= $teamId ?>">
      <b>Pertanyaan</b>
      <span>Channel, ambang gantung, jam buka dan tutup, kalimat pertanyaannya.</span>
      <em>Gantung &gt;<?= (int) $cfg['threshold_days'] ?> hari ·
          <?= sprintf('%02d:00', (int) $cfg['open_hour']) ?>–<?=
              sprintf('%02d:00', (int) $cfg['due_hour']) ?> ·
          <?= count($cfg['channels']) ?> channel</em>
    </a>
    <a href="izin.php">
      <b>Izin</b>
      <span>Cuti, sakit, dinas luar — supaya harinya tidak tercatat tidak lapor.</span>
      <em><?= $izinN === 0 ? 'Belum ada yang tercatat'
          : ($izinN === 1 ? '1 hari izin tercatat' : $izinN . ' hari izin tercatat') ?></em>
    </a>
    <a href="cek.php">
      <b>Cek sistem</b>
      <span>Apakah pengingatnya benar-benar terkirim, dan kalau tidak, kenapa.</span>
      <em>
        <span class="sehat <?= $cronSehat ? 'ya' : 'tidak' ?>"><i></i><?= $tickAge === null
          ? 'Cron belum pernah jalan'
          : 'Cron terakhir jalan ' . h($umur($tickAge)) ?></span>
        <span class="sehat <?= $kirim ? 'ya' : 'tidak' ?>"><i></i><?= $kirim
          ? 'Pengingat terakhir terkirim ' . h($umur($menitLalu($kirim['ran_at'])))
          : 'Belum ada pengingat yang terkirim' ?></span>
        <?php if ($salah): ?>
          <span class="sehat tidak"><i></i>Kesalahan terakhir
            <?= h($umur($menitLalu($salah['ran_at']))) ?> (<?= h($salah['kind']) ?>)</span>
        <?php endif; ?>
      </em>
    </a>
    <?php if ($pending): ?>
    <a href="upgrade.php">
      <b>Pemutakhiran database</b>
      <span>Menyusulkan database ke bentuk yang dibutuhkan versi ini.</span>
      <em><?= count($pending) ?> hal menunggu</em>
    </a>
    <?php endif; ?>
  </div>
<?php
page_foot();
