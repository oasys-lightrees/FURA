<?php
/**
 * Izin: cuti, sakit, dinas luar.
 *
 * Satu lubang yang paling cepat merusak kepercayaan pada rekap: orang yang cuti seminggu
 * kembali ke kantor dengan lima hari merah "tidak lapor" di namanya, dan tidak ada satu
 * pun tombol untuk membetulkannya. Rekap yang menyimpan tuduhan yang semua orang tahu
 * salah berhenti dibaca sebagai rekap — dan begitu satu baris merahnya tidak berarti
 * apa-apa, tidak ada baris merah lain yang berarti apa-apa.
 *
 * Dibuat per rentang tanggal, bukan per hari, karena cuti memang datang per rentang.
 * Yang ditandai leader atau admin, bukan orangnya sendiri: izin yang bisa diberikan
 * sendiri bukan izin, cuma tombol "hapus tanda merah".
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
if (!is_leader($me)) { http_response_code(403); exit('Halaman ini untuk leader dan admin.'); }

// Leader membaca dan menandai timnya sendiri; admin dan owner semua tim. Dibatasi di sini
// *dan* di repo_set_excused: yang ini supaya daftarnya benar, yang itu supaya kiriman
// mentah tidak bisa melewatinya.
$onlyTeam = is_manager($me) ? null : repo_team_of($me);

$notice = null;
$error  = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $do = (string) ($_POST['do'] ?? '');
    try {
        if ($do === 'tandai' || $do === 'batal') {
            $n = repo_set_excused($me, (int) ($_POST['id'] ?? 0),
                                  (string) ($_POST['from'] ?? ''),
                                  (string) ($_POST['to'] ?? ''),
                                  $do === 'tandai',
                                  (string) ($_POST['note'] ?? ''));
            if ($n === 0) {
                $notice = $do === 'tandai'
                    ? 'Tidak ada hari yang berubah. Rentangnya mungkin hanya akhir pekan, '
                    . 'atau hari-harinya sudah ditandai izin, atau laporannya sudah masuk.'
                    : 'Tidak ada hari izin di rentang itu.';
            } else {
                $hari = $n === 1 ? '1 hari' : $n . ' hari';
                $notice = $do === 'tandai'
                    ? $hari . ' ditandai izin. Hari itu tidak lagi dihitung tidak lapor, '
                    . 'dan botnya tidak menagihnya.'
                    : $hari . ' dibatalkan izinnya.';
            }
        }
    } catch (RepoError $e) {
        $error = $e->getMessage();
    }
}

/* ------------------------------------------------------------------ daftar */

$orang = q('SELECT id, name, team_id FROM users
             WHERE active = 1 AND accepted_at IS NOT NULL'
           . ($onlyTeam === null ? '' : ' AND team_id = ?')
           . ' ORDER BY name', $onlyTeam === null ? [] : [$onlyTeam])->fetchAll();
$teams = repo_teams(true);
$izin = repo_excused($onlyTeam);

/**
 * Hari-hari berurutan jadi satu baris.
 *
 * Cuti Jumat lalu Senin adalah satu cuti, bukan dua: yang di antaranya akhir pekan, dan
 * akhir pekan tidak pernah ditandai. Jadi yang dicari hari kerja berikutnya, bukan hari
 * berikutnya — kalau tidak, cuti seminggu tampil sebagai lima baris terpisah dan tombol
 * batalnya harus ditekan lima kali.
 */
function izin_rentang(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $akhir = count($out) - 1;
        $nyambung = $akhir >= 0
                 && $out[$akhir]['user_id'] === $r['user_id']
                 && $out[$akhir]['note'] === $r['note']
                 && izin_workday_next($out[$akhir]['to']) === $r['day'];
        if ($nyambung) {
            $out[$akhir]['to'] = $r['day'];
            $out[$akhir]['n']++;
            continue;
        }
        $out[] = ['user_id' => $r['user_id'], 'name' => $r['name'], 'from' => $r['day'],
                  'to' => $r['day'], 'note' => $r['note'], 'by' => $r['by'], 'n' => 1];
    }
    return $out;
}

/** Hari kerja pertama sesudah hari ini. Akhir pekan dilewati, bukan dihitung. */
function izin_workday_next(string $day): string
{
    for ($i = 1; $i <= 7; $i++) {
        $d = messi_add_days($day, $i);
        if (messi_is_workday($d)) {
            return $d;
        }
    }
    return messi_add_days($day, 1);
}

$rentang = izin_rentang($izin);
$today = Clock::today();
$namaOrang = [];
foreach (q('SELECT id, name FROM users')->fetchAll() as $u) {
    $namaOrang[(int) $u['id']] = (string) $u['name'];
}
$csrf = csrf_token();

page_head('Izin', ['me' => $me, 'wide' => true, 'css' => <<<'CSS'
.izin td b { display:block }
.izin td span { color:var(--muted); font-size:0.8125rem }
.lalu td { opacity:0.6 }
.tiga { display:grid; grid-template-columns:repeat(auto-fit, minmax(10rem, 1fr)); gap:0.75rem }
.tiga input, .tiga select { width:100% }
CSS]);
?>
  <h1>Izin</h1>
  <p class="sub"><?= is_manager($me) ? '<a href="kelola.php">‹ Kelola</a> · ' : '' ?><a
     href="index.php">Laporan</a></p>

  <?php if ($notice): ?><p class="note ok"><?= h($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="note bad"><?= h($error) ?></p><?php endif; ?>

  <p class="why">Hari yang ditandai izin tidak dihitung <em>tidak lapor</em>, tidak muncul
     di <em>Perlu perhatian</em>, dan tidak ditagih bot di pengingat jam tutup. Akhir pekan
     tidak perlu ditandai — memang tidak pernah dihitung. Hari yang laporannya sudah masuk
     tidak ikut ditandai: laporan yang sungguhan selalu menang.</p>

  <?php if (!$orang): ?>
    <p class="note warn">Belum ada orang di <?= $onlyTeam === null ? 'perusahaan ini'
       : 'timmu' ?> yang bisa ditandai.</p>
  <?php else: ?>
  <h2>Tandai izin</h2>
  <div class="card">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="do" value="tandai">
      <div class="tiga">
        <div><label for="id">Siapa</label>
          <select id="id" name="id">
            <?php foreach ($orang as $o): ?>
              <option value="<?= (int) $o['id'] ?>"><?= h($o['name']) ?><?=
                $onlyTeam === null && $o['team_id'] !== null
                  ? ' · ' . h($teams[(int) $o['team_id']]['name'] ?? '—') : '' ?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label for="d1">Dari tanggal</label>
          <input id="d1" name="from" type="date" required value="<?= h($today) ?>"></div>
        <div><label for="d2">Sampai tanggal</label>
          <input id="d2" name="to" type="date" required value="<?= h($today) ?>"></div>
      </div>
      <p style="margin:0.875rem 0 0"><label for="nt">Keterangan — boleh dikosongkan</label>
        <input id="nt" name="note" type="text" maxlength="120"
               placeholder="mis. cuti tahunan, sakit, dinas luar"></p>
      <p style="margin:1rem 0 0"><button type="submit">Tandai izin</button></p>
      <p class="why" style="margin:0.5rem 0 0">Satu hari saja: isi dua tanggal yang sama.
         Boleh untuk hari yang sudah lewat maupun yang akan datang, sampai
         <?= MESSI_IZIN_MAX_DAYS ?> hari sekaligus.</p>
    </form>
  </div>
  <?php endif; ?>

  <h2>Yang sudah tercatat</h2>
  <?php if (!$rentang): ?>
    <p class="why">Belum ada izin yang tercatat untuk bulan ini dan sesudahnya.</p>
  <?php else: ?>
  <div class="card scroll">
    <table class="izin">
      <tr><th>Siapa</th><th>Kapan</th><th>Keterangan</th><th></th></tr>
      <?php foreach ($rentang as $r): ?>
      <tr class="<?= $r['to'] < $today ? 'lalu' : '' ?>">
        <td><b><?= h($r['name']) ?></b>
          <?php if ($r['by'] && isset($namaOrang[$r['by']])): ?>
            <span>ditandai <?= h($namaOrang[$r['by']]) ?></span>
          <?php endif; ?></td>
        <td><?= h(messi_fmt_day($r['from'])) ?><?= $r['from'] === $r['to'] ? ''
            : ' – ' . h(messi_fmt_day($r['to'])) ?>
          <span><?= $r['n'] === 1 ? '1 hari kerja' : $r['n'] . ' hari kerja' ?><?=
            $r['to'] < $today ? ' · sudah lewat' : '' ?></span></td>
        <td><?= $r['note'] === '' ? '<span>—</span>' : h($r['note']) ?></td>
        <td>
          <form class="row" method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="do" value="batal">
            <input type="hidden" name="id" value="<?= (int) $r['user_id'] ?>">
            <input type="hidden" name="from" value="<?= h($r['from']) ?>">
            <input type="hidden" name="to" value="<?= h($r['to']) ?>">
            <button class="quiet" type="submit"
              onclick="return confirm('Batalkan izin ini? Hari yang sudah lewat akan kembali tercatat tidak lapor.')"
              >Batalkan</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
<?php
page_foot();
