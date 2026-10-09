<?php
/**
 * The squad list: who reports, who leads, and who is connected to the bot.
 *
 * Small on purpose. Adding somebody, resetting a password and handing out a pairing code
 * are the only three things anyone has actually needed to do.
 */

declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require __DIR__ . '/lib/require-php8.php';

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/repo.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/layout.php';

// Says what is missing on the first screen, not on the first click.
messi_require_current();

$me = auth_user();
if (!$me) { header('Location: login.php'); exit; }
if (!is_manager($me)) { http_response_code(403); exit('Halaman ini untuk admin.'); }

$teams = repo_teams();
if (!$teams) {                       // pemasangan baru: selalu ada satu tim
    repo_default_team();
    $teams = repo_teams();
}
$notice = null;

$error = null;
// Satu daftar, bukan satu link istimewa: menambah satu orang dan menempel sepuluh email
// menghasilkan hal yang sama, jadi yang menampilkannya juga satu.
$links = [];
$linksLabel = '';

/**
 * Boleh tidak orang ini menyentuh baris itu?
 *
 * Owner bisa menyentuh siapa saja kecuali dirinya sendiri. Admin tidak bisa menyentuh
 * owner maupun sesama admin — kalau bisa, satu admin bisa menurunkan yang lain lalu
 * mengambil alih, dan "admin" berhenti berarti apa pun.
 */
function admin_may_touch(array $me, ?array $target): bool
{
    if (!$target || (int) $target['id'] === (int) $me['id']) {
        return false;
    }
    if (is_owner($me)) {
        return true;
    }
    return !in_array($target['role'], ['admin', 'owner'], true);
}

/**
 * Menambah satu orang, lalu membuatkan undangannya.
 *
 * Dipakai formulir satu orang maupun kotak tempel-banyak. Satu tempat, karena aturan
 * yang ditulis dua kali adalah aturan yang suatu hari berbeda di salah satunya.
 *
 * @return array{0: ?string, 1: ?array}  pesan kesalahan, atau undangan yang jadi
 */
function admin_tambah(array $teams, array $canGive, string $name, string $email,
                      string $role, int $team, string $joined): array
{
    $email = strtolower(trim($email));
    $name  = trim((string) preg_replace('/\s+/u', ' ', $name));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Barisnya ikut dikutip. "Email kosong" di tempelan sepuluh baris berarti admin
        // harus menebak sendiri baris yang mana.
        $apa = $email !== '' ? $email : ($name !== '' ? $name : '(kosong)');
        return ['"' . $apa . '" bukan email yang benar.', null];
    }
    if ($name === '') {
        return ['Nama untuk ' . $email . ' belum ada.', null];
    }
    if (!isset($teams[$team])) {
        return ['Pilih timnya.', null];
    }
    if (!in_array($role, $canGive, true)) {
        $role = 'player';
    }
    if (q1('SELECT id FROM users WHERE email = ?', [$email])) {
        return [$email . ' sudah terdaftar.', null];
    }
    // Password dikosongkan: yang membuatnya adalah orangnya sendiri lewat undangan.
    // Mulai hari ini berarti hari-hari sebelum hari ini tidak dihitung melawan dia.
    q('INSERT INTO users (email, name, password_hash, role, team_id, joined_on, created_at)
       VALUES (?,?,?,?,?,?,?)',
      [$email, mb_substr($name, 0, 120), '', $role, $team, $joined, Clock::nowUtcSql()]);
    $id = (int) db()->lastInsertId();
    $url = auth_make_invite($id);
    return [null, ['name' => $name, 'email' => $email, 'url' => $url,
                   'mail' => admin_kirim(['name' => $name, 'email' => $email], $url, true)]];
}

/**
 * Mengirim linknya ke emailnya, kalau pemasangan ini bisa mengirim email.
 *
 * Hasilnya ikut ditampilkan per baris, bukan cuma "terkirim" di atas: dari sepuluh
 * undangan bisa saja sembilan sampai dan satu ditolak, dan satu kalimat untuk sepuluh
 * baris membuat yang satu itu tidak kelihatan.
 *
 * @return string 'sent' | 'gagal' | 'off' (pemasangan ini memang tidak mengirim email)
 */
function admin_kirim(array $orang, string $url, bool $baru): string
{
    if (!mail_enabled()) {
        return 'off';
    }
    return mail_invite($orang, $url, $baru) ? 'sent' : 'gagal';
}

/**
 * Membaca tempelan jadi daftar orang.
 *
 * Tiga bentuk diterima, karena ketiganya yang sebenarnya ditempel orang: alamat polos
 * satu per baris, "Nama <alamat>" seperti yang tercopot dari daftar kontak, dan satu
 * baris penuh alamat dipisah koma seperti isi kolom "To". Yang terakhir itu yang paling
 * mudah salah: kalau barisnya dibaca sebagai satu orang, alamat kedua jadi *nama* orang
 * pertama, dan yang lain hilang tanpa sepatah kata.
 *
 * Tanpa nama, namanya ditebak dari depan tanda @ — nama tebakan yang bisa diperbaiki
 * nanti lebih baik daripada menolak seluruh tempelan karena satu baris kurang lengkap.
 */
function admin_baca_daftar(string $blob): array
{
    $out = [];
    foreach (preg_split('/\R/u', $blob) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^(.*?)<([^>]*)>/u', $line, $m)) {
            $out[] = ['name' => trim($m[1], " \t\"'"), 'email' => trim($m[2])];
            continue;
        }
        $bits = preg_split('/[,;\s]+/u', $line) ?: [];
        $alamat = array_values(array_filter($bits, fn($b) => str_contains($b, '@')));
        $sisa = array_values(array_filter($bits, fn($b) => !str_contains($b, '@')));
        if (count($alamat) > 1) {
            // Banyak alamat dalam satu baris: tidak ada cara tahu nama mana milik alamat
            // mana, jadi semuanya ditebak — dan tidak ada yang hilang.
            foreach ($alamat as $satu) {
                $out[] = ['name' => '', 'email' => $satu];
            }
            continue;
        }
        $out[] = ['name' => trim(implode(' ', $sisa)), 'email' => $alamat[0] ?? ''];
    }

    foreach ($out as $i => $satu) {
        if ($satu['name'] === '' && $satu['email'] !== '') {
            $local = substr($satu['email'], 0, (int) strpos($satu['email'], '@'));
            $out[$i]['name'] = ucwords(trim((string) preg_replace('/[._\-]+/', ' ', $local)));
        }
    }
    return $out;
}

// Peran yang boleh diberikan orang ini: hanya owner yang bisa mengangkat admin atau
// owner. Satu tempat, dipakai formulirnya maupun pemeriksaan kirimannya — dua tempat
// berarti suatu hari formulirnya menyembunyikan apa yang masih diterima server.
$canGive = is_owner($me) ? ['player', 'leader', 'admin', 'owner'] : ['player', 'leader'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $do = (string) ($_POST['do'] ?? '');
    $targetId = (int) ($_POST['id'] ?? 0);
    $target = $targetId ? q1('SELECT * FROM users WHERE id = ?', [$targetId]) : null;

    if ($do === 'add' || $do === 'addmany') {
        $role   = (string) ($_POST['role'] ?? 'player');
        $team   = (int) ($_POST['team'] ?? 0);
        $joined = (string) ($_POST['joined'] ?? Clock::today());
        // Satu orang lewat formulirnya, atau sepuluh lewat kotak tempelan: keduanya
        // berakhir sebagai daftar yang sama dan lewat pemeriksaan yang sama.
        $daftar = $do === 'addmany'
            ? admin_baca_daftar((string) ($_POST['daftar'] ?? ''))
            : [['name' => (string) ($_POST['name'] ?? ''),
                'email' => (string) ($_POST['email'] ?? '')]];

        $gagal = [];
        foreach ($daftar as $satu) {
            [$kenapa, $undangan] = admin_tambah($teams, $canGive, $satu['name'],
                                                $satu['email'], $role, $team, $joined);
            if ($undangan) { $links[] = $undangan; } else { $gagal[] = (string) $kenapa; }
        }
        if (!$daftar) {
            $error = 'Belum ada email yang ditempel.';
        } elseif ($gagal) {
            // Yang berhasil tetap berhasil. Membatalkan sembilan orang karena satu
            // emailnya salah ketik berarti seluruh tempelan harus diulang.
            $error = implode(' ', array_slice($gagal, 0, 5))
                   . (count($gagal) > 5 ? ' (dan ' . (count($gagal) - 5) . ' lagi)' : '');
        }
        if ($links) {
            $linksLabel = count($links) === 1
                ? 'Undangan untuk ' . $links[0]['name'] . ' — berlaku 72 jam, sekali pakai.'
                : count($links) . ' undangan — masing-masing berlaku 72 jam, sekali pakai.';
            $notice = count($links) === 1
                ? $links[0]['name'] . ' ditambahkan.'
                : count($links) . ' orang ditambahkan.';
        }

    } elseif ($do === 'invite') {
        if (!admin_may_touch($me, $target)) {
            $error = 'Tidak bisa mengubah baris itu.';
        } else {
            $url = auth_make_invite($targetId);
            $links[] = ['name' => $target['name'], 'email' => $target['email'],
                        'url' => $url,
                        'mail' => admin_kirim($target, $url, $target['accepted_at'] === null)];
            $linksLabel = ($target['accepted_at'] === null ? 'Undangan baru untuk ' : 'Link buat password baru untuk ')
                          . $target['name'] . ' — berlaku 72 jam, sekali pakai.';
            // Tanpa $notice: peringatan "kirim japri" sudah ada tepat di sebelah linknya,
            // dan peringatan yang sama dua kali di satu halaman berhenti dibaca.
            // Permintaannya sudah dijawab, jadi baris "minta link masuk" ikut hilang.
            auth_clear_reset($targetId);
        }

    } elseif ($do === 'link') {
        // One use, 60 minutes. Hand it over privately — in a Google Chat space it would
        // be a login link for everyone who can read that space.
        if (!admin_may_touch($me, $target)) {
            $error = 'Tidak bisa mengubah baris itu.';
        } else {
            $url = auth_make_login_link($targetId, 60);
            $links[] = ['name' => $target['name'], 'email' => $target['email'], 'url' => $url,
                        'mail' => mail_enabled()
                            ? (mail_login_link($target, $url, 60) ? 'sent' : 'gagal') : 'off'];
            $linksLabel = 'Link masuk untuk ' . $target['name'] . ' — berlaku 60 menit, sekali pakai.';
            auth_clear_reset($targetId);
        }

    } elseif ($do === 'active') {
        if (!admin_may_touch($me, $target)) {
            $error = 'Tidak bisa mengubah baris itu.';
        } else {
            q('UPDATE users SET active = ? WHERE id = ?', [(int) $_POST['active'], $targetId]);
            $notice = 'Status diubah.';
        }

    } elseif ($do === 'team') {
        $team = (int) ($_POST['team'] ?? 0);
        if (!admin_may_touch($me, $target)) {
            $error = 'Tidak bisa mengubah baris itu.';
        } elseif (!isset($teams[$team])) {
            $error = 'Tim itu tidak ada.';
        } else {
            // Laporan yang sudah dikirim membawa timnya sendiri, jadi pindah tim hari ini
            // tidak menyentuh rekap kemarin.
            q('UPDATE users SET team_id = ? WHERE id = ?', [$team, $targetId]);
            $notice = $target['name'] . ' dipindah ke ' . $teams[$team]['name'] . '.';
        }

    } elseif ($do === 'role') {
        $role = in_array($_POST['role'] ?? '', $canGive, true) ? (string) $_POST['role'] : null;
        // Owner lain yang masih aktif — selain yang sedang diubah. Menghitung semuanya
        // termasuk yang diubah akan menolak penurunan owner yang sudah nonaktif padahal
        // masih ada owner lain yang memegang kendali.
        $lainnya = (int) q1("SELECT COUNT(*) AS n FROM users
                              WHERE role = 'owner' AND active = 1 AND id <> ?",
                            [$targetId])['n'];
        if (!admin_may_touch($me, $target)) {
            $error = 'Tidak bisa mengubah baris itu.';
        } elseif ($role === null) {
            $error = 'Peran itu di luar wewenangmu.';
        } elseif ($target['role'] === 'owner' && $role !== 'owner' && $lainnya === 0) {
            // Penjaga, bukan jalan: lewat halaman ini keadaan itu tidak bisa dicapai,
            // karena peran sendiri tidak bisa diubah sendiri. Ada supaya tetap benar
            // kalau suatu hari ada jalan lain menuju ke sini.
            $error = 'Harus selalu ada satu owner. Angkat owner lain dulu.';
        } else {
            q('UPDATE users SET role = ? WHERE id = ?', [$role, $targetId]);
            $notice = 'Peran diubah.';
        }

    } elseif ($do === 'addteam') {
        try {
            repo_add_team($me, (string) ($_POST['name'] ?? ''));
            $notice = 'Tim ditambahkan.';
            $teams = repo_teams();
        } catch (RepoError $e) {
            $error = $e->getMessage();
        }

    } elseif ($do === 'timaktif' || $do === 'timmati') {
        try {
            repo_set_team_active($me, (int) ($_POST['team'] ?? 0), $do === 'timaktif');
            $notice = $do === 'timaktif' ? 'Tim dinyalakan.'
                : 'Tim dimatikan. Namanya hilang dari semua pilihan; laporan lamanya tetap bisa dibaca.';
            $teams = repo_teams();
        } catch (RepoError $e) {
            $error = $e->getMessage();
        }

    } elseif ($do === 'hapustim') {
        try {
            repo_delete_team($me, (int) ($_POST['team'] ?? 0));
            $notice = 'Tim dihapus.';
            $teams = repo_teams();
        } catch (RepoError $e) {
            $error = $e->getMessage();
        }

    } elseif ($do === 'renteam') {
        $team = (int) ($_POST['team'] ?? 0);
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')));
        if (!isset($teams[$team]) || $name === '') {
            $error = 'Nama timnya belum benar.';
        } elseif (q1('SELECT id FROM teams WHERE name = ? AND id <> ?', [$name, $team])) {
            $error = 'Sudah ada tim dengan nama itu.';
        } else {
            q('UPDATE teams SET name = ? WHERE id = ?', [mb_substr($name, 0, 60), $team]);
            $notice = 'Nama tim diubah.';
            $teams = repo_teams();
        }
    }
}

// Pemilih tim di tiap baris orang cuma berisi yang aktif — memindahkan seseorang ke tim
// yang sudah dimatikan berarti menyembunyikannya dari semua rekap. Tabel Tim di bawah
// yang memperlihatkan semuanya, karena di situlah yang mati dihidupkan lagi.
$allTeams = repo_teams(true);

$people = q('SELECT id, name, email, role, active, joined_on, team_id, invited_at,
                    accepted_at, reset_asked_at
               FROM users ORDER BY active DESC, name')->fetchAll();
// Siapa yang bilang lupa passwordnya. Mereka tidak punya jalan pulang lain, jadi ini
// disebutkan di atas dengan namanya — bukan cuma ditempeli label di barisnya, yang di
// daftar dua puluh orang berarti harus dicari dulu.
$mintaLink = array_values(array_filter($people, fn($p) => $p['reset_asked_at'] !== null));
$csrf = csrf_token();
$pendingSchema = schema_pending();

page_head('Orang & tim', ['me' => $me, 'wide' => true, 'css' => <<<'CSS'
td.who strong { display:block }
td.who span { color:var(--muted); font-size:0.8125rem }
.off td { opacity:0.5 }
.tag.minta { background:var(--due-bg); border-color:var(--due-bg); color:var(--due) }
.links td { border-top:1px solid var(--line) }
.links code { display:block; word-break:break-all }   /* link panjang, boleh dipenggal */
/* Tombol yang paling sering dipakai ada di kolom paling kanan, dan di layar sempit
   kolom itu di luar layar. Tabelnya memang bisa digeser — yang kurang cuma orang tahu
   bahwa ada yang bisa digeser. */
/* Tombol baris: tiga tombol seukuran tombol utama di tiap baris membuat enam orang jadi
   delapan belas tombol yang semuanya terlihat sama penting — termasuk yang merusak. */
button.kecil { padding:0.3125rem 0.625rem; font-size:0.8125rem }
/* Judul kolom yang dibawa masuk ke dalam barisnya, dipakai hanya waktu tabelnya berhenti
   jadi tabel. Elemen sungguhan, bukan content pada ::before: yang digambar CSS tidak ikut
   tersalin waktu orang menyalin halamannya, dan tidak selalu terbaca pembaca layar. */
.lbl { display:none; font-size:0.75rem; font-weight:500; color:var(--muted);
       margin:0 0 0.125rem }
button.bahaya:hover { border-color:var(--broken); color:var(--broken) }

/* Di telepon tabelnya lebih lebar daripada layarnya, dan yang terjadi bukan sekadar
   "harus digeser": barisnya jadi setinggi dua kali isinya karena tombolnya membungkus
   di luar layar, dan kolom paling kanan — tempat semua tombolnya — tidak pernah terlihat
   sampai orangnya menebak bahwa tabel itu bisa digeser. Jadi di bawah 46rem tabelnya
   berhenti jadi tabel: satu orang satu kartu, judul kolomnya dibawa masuk ke dalam. */
.geser { display:none; color:var(--muted); font-size:0.8125rem; margin:0 0 0.5rem }
@media (max-width: 46rem) {
  .scroll { overflow-x:visible }
  table.orang, table.orang tbody, table.orang tr, table.orang td { display:block; width:100% }
  table.orang tr:first-child { display:none }                 /* baris judul kolom */
  table.orang tr { border-top:1px solid var(--line); padding:0.875rem 0 }
  table.orang tr:nth-child(2) { border-top:0; padding-top:0 }
  table.orang td { border:0; padding:0.25rem 0 }
  table.orang td.who { padding-bottom:0.5rem }
  table.orang.tim input[name=name] { width:auto; min-width:9rem }
  /* Tanpa judul kolom, dua pilihan berdampingan jadi dua kotak tanpa nama. */
  table.orang td.k .lbl { display:block }
  table.orang form.row { margin:0 0.375rem 0.375rem 0 }
  table.orang select { width:auto; min-width:8rem }
}
.pisah { display:flex; align-items:center; gap:0.75rem; margin:1.5rem 0 1rem;
         color:var(--muted); font-size:0.8125rem }
.pisah::before, .pisah::after { content:""; flex:1; border-top:1px solid var(--line) }
CSS]);
?>
  <h1>Orang &amp; tim</h1>
  <p class="sub"><a href="kelola.php">‹ Kelola</a> · <a href="soal.php">Pertanyaan</a></p>

  <?php if ($pendingSchema): ?>
    <p class="note warn"><strong>Database belum sesuai versi ini.</strong>
       <?= count($pendingSchema) ?> hal belum dikerjakan, jadi sebagian fitur baru belum
       bisa dipakai. <a href="upgrade.php">Buka pemutakhiran database</a>.</p>
  <?php endif; ?>
  <?php if ($notice): ?><p class="note ok"><?= h($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="note bad"><?= h($error) ?></p><?php endif; ?>

  <?php if ($mintaLink): ?>
    <p class="note warn"><strong><?= h(implode(', ', array_map(
         fn($p) => (string) $p['name'], $mintaLink))) ?></strong>
       bilang lupa passwordnya.
       <?php if (mail_enabled()): ?>
         Link masuk sudah dikirim ke emailnya sendiri — baris ini hilang begitu dia
         berhasil masuk. Kalau emailnya tidak sampai, tekan <em>Link masuk</em> di
         barisnya dan kirim linknya japri.
       <?php else: ?>
         Tekan <em>Link masuk</em> di barisnya, lalu kirim linknya japri.
       <?php endif; ?></p>
  <?php endif; ?>

  <?php if ($links): ?>
    <?php
    // Berapa yang sampai sendiri, dan berapa yang masih harus disalin tangan. Dipisahkan
    // karena kalimat yang menyuruh "kirim japri" salah untuk yang sudah terkirim, dan
    // kalimat "sudah dikirim" berbahaya untuk yang gagal — orangnya akan menunggu email
    // yang tidak pernah ada.
    $terkirim = count(array_filter($links, fn($l) => ($l['mail'] ?? 'off') === 'sent'));
    $gagalKirim = count(array_filter($links, fn($l) => ($l['mail'] ?? 'off') === 'gagal'));
    ?>
    <p class="note ok"><?= h($linksLabel) ?>
       <?php if ($terkirim): ?><br><strong><?= $terkirim === count($links)
         ? ($terkirim === 1 ? 'Sudah dikirim ke emailnya.' : 'Semuanya sudah dikirim ke emailnya.')
         : $terkirim . ' dari ' . count($links) . ' sudah dikirim ke emailnya.' ?></strong>
         Minta orangnya cek folder spam kalau belum kelihatan.<?php endif; ?>
       <?php if ($terkirim < count($links)): ?><br>Yang belum terkirim: salin linknya di
         bawah dan kirim <strong>japri</strong>, jangan ke space — siapa pun yang bisa
         membaca space itu bisa memakainya.<?php endif; ?></p>
    <?php if ($gagalKirim): ?>
      <p class="note bad"><strong><?= $gagalKirim ?> email gagal dikirim.</strong>
         Linknya tetap berlaku — salin dari tabel di bawah. Penyebabnya ada di
         <a href="cek.php">Cek sistem</a>, baris <em>Email</em>.</p>
    <?php endif; ?>
    <div class="card scroll" style="margin:0 0 1rem">
      <table class="links">
        <tr><th>Orang</th><th>Link</th><th></th></tr>
        <?php foreach ($links as $l): ?>
          <tr>
            <td class="who"><strong><?= h($l['name']) ?></strong><span><?= h($l['email']) ?></span>
              <?php if (($l['mail'] ?? 'off') === 'sent'): ?>
                <span class="tag">email terkirim</span>
              <?php elseif (($l['mail'] ?? 'off') === 'gagal'): ?>
                <span class="tag minta">email gagal</span>
              <?php endif; ?></td>
            <td><code><?= h($l['url']) ?></code></td>
            <td><button class="quiet" type="button" data-salin="<?= h($l['url']) ?>">Salin</button></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php if (count($links) > 1): ?>
        <p style="margin:1rem 0 0"><button class="quiet" type="button" id="salinSemua"
           data-salin="<?= h(implode("\n", array_map(
             fn($l) => $l['name'] . ' — ' . $l['url'], $links))) ?>">Salin semua
           (<?= count($links) ?>)</button></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php
  // Dari lib/layout.php, bukan daftar kedua di sini: halaman ini sempat menyebut peran
  // yang sama "Pelapor" sementara menu di bawah avatar menyebutnya "Pemain" — dua kata
  // untuk satu hal, di dua layar yang dibuka orang yang sama.
  $roleNames = MESSI_ROLE_LABEL;
  ?>

  <p class="geser">Tabelnya lebih lebar dari layar ini — geser ke samping untuk
     tombol-tombolnya.</p>
  <div class="card scroll">
  <table class="orang">
    <tr><th>Orang</th><th>Tim</th><th>Peran</th><th>Mulai</th><th></th></tr>
    <?php foreach ($people as $p): $mine = (int) $p['id'] === (int) $me['id'];
          $boleh = admin_may_touch($me, $p); ?>
    <tr class="<?= $p['active'] ? '' : 'off' ?>">
      <td class="who"><strong><?= h($p['name']) ?></strong><span><?= h($p['email']) ?></span>
        <?php if ($p['accepted_at'] === null): ?>
          <span class="tag">belum terima undangan</span>
        <?php endif; ?>
        <?php if ($p['reset_asked_at'] !== null): ?>
          <span class="tag minta">minta link masuk</span>
        <?php endif; ?></td>
      <td class="k"><span class="lbl">Tim</span>
        <?php if (!$boleh): ?>
          <span class="tag"><?= h($teams[(int) $p['team_id']]['name'] ?? '—') ?></span>
        <?php else: ?>
        <form class="row" method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="do" value="team">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <select name="team" onchange="this.form.submit()">
            <?php foreach ($teams as $t): ?>
              <option value="<?= (int) $t['id'] ?>"
                <?= (int) $p['team_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
      </td>
      <td class="k"><span class="lbl">Peran</span>
        <?php if ($mine): ?>
          <span class="tag"><?= h($roleNames[$p['role']] ?? $p['role']) ?> (kamu)</span>
        <?php elseif (!$boleh): ?>
          <span class="tag"><?= h($roleNames[$p['role']] ?? $p['role']) ?></span>
        <?php else: ?>
        <form class="row" method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="do" value="role">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <select name="role" onchange="this.form.submit()">
            <?php foreach ($canGive as $k): ?>
              <option value="<?= $k ?>" <?= $p['role'] === $k ? 'selected' : '' ?>><?= h($roleNames[$k]) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
      </td>
      <td class="k"><span class="lbl">Mulai lapor</span><?= h(messi_fmt_day($p['joined_on'])) ?></td>
      <td>
        <?php if ($boleh): ?>
        <form class="row" method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="do" value="invite">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button class="quiet kecil" type="submit" title="<?= $p['accepted_at'] === null
            ? 'Mengirim ulang undangannya; dia yang membuat passwordnya sendiri.'
            : 'Link sekali pakai untuk membuat password baru. Berlaku 72 jam.' ?>"><?=
            $p['accepted_at'] === null ? 'Undang ulang' : 'Password baru' ?></button>
        </form>
        <?php if ($p['accepted_at'] !== null): ?>
        <form class="row" method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="do" value="link">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button class="quiet kecil" type="submit"
            title="Link sekali pakai yang langsung memasukkan dia, tanpa password. Berlaku 60 menit."
            >Link masuk</button>
        </form>
        <?php endif; ?>
        <form class="row" method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="do" value="active">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <input type="hidden" name="active" value="<?= $p['active'] ? 0 : 1 ?>">
          <button class="quiet kecil <?= $p['active'] ? 'bahaya' : '' ?>" type="submit"
            title="<?= $p['active']
              ? 'Dia berhenti muncul di rekap dan berhenti dihitung. Laporan lamanya tetap ada.'
              : 'Dia kembali muncul di rekap dan kembali dihitung.' ?>"><?=
            $p['active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>

  <h2>Undang orang</h2>
  <p class="why">Passwordnya dibuat orangnya sendiri lewat link undangan yang muncul
     setelah ini — jadi tidak ada password yang perlu kamu ketik, kirim, atau ingat.</p>
  <div class="card">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="do" value="add">
      <div class="grid">
        <div><label for="n">Nama</label><input id="n" name="name" type="text" required></div>
        <div><label for="e">Email</label><input id="e" name="email" type="email" required></div>
        <div><label for="tm">Tim</label><select id="tm" name="team">
          <?php foreach ($teams as $t): ?>
            <option value="<?= (int) $t['id'] ?>"><?= h($t['name']) ?></option>
          <?php endforeach; ?></select></div>
        <div><label for="r">Peran</label><select id="r" name="role">
          <?php foreach ($canGive as $k): ?>
            <option value="<?= $k ?>"><?= h($roleNames[$k]) ?></option>
          <?php endforeach; ?></select></div>
        <div><label for="j">Mulai lapor</label>
          <input id="j" name="joined" type="date" value="<?= h(Clock::today()) ?>"></div>
      </div>
      <p style="margin:1rem 0 0"><button type="submit">Undang</button></p>
    </form>

    <p class="pisah">atau tempel seluruh timnya sekaligus</p>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="do" value="addmany">
      <label for="daftar">Satu orang per baris</label>
      <textarea id="daftar" name="daftar" rows="5"
        placeholder="budi@contoh.com&#10;Sari Wijaya &lt;sari@contoh.com&gt;"></textarea>
      <p class="why" style="margin:0.5rem 0 0.75rem">Alamat polos saja boleh — namanya
         ditebak dari depan tanda @. Yang satu baris salah ketik dilaporkan sendiri;
         yang lain tetap jadi.</p>
      <div class="grid">
        <div><label for="tm2">Tim</label><select id="tm2" name="team">
          <?php foreach ($teams as $t): ?>
            <option value="<?= (int) $t['id'] ?>"><?= h($t['name']) ?></option>
          <?php endforeach; ?></select></div>
        <div><label for="r2">Peran</label><select id="r2" name="role">
          <?php foreach ($canGive as $k): ?>
            <option value="<?= $k ?>"><?= h($roleNames[$k]) ?></option>
          <?php endforeach; ?></select></div>
        <div><label for="j2">Mulai lapor</label>
          <input id="j2" name="joined" type="date" value="<?= h(Clock::today()) ?>"></div>
      </div>
      <p style="margin:1rem 0 0"><button type="submit">Undang semuanya</button></p>
    </form>
  </div>

  <h2>Tim</h2>
  <p class="why">Tiap tim punya pertanyaan, ambang, jam dan space chat-nya sendiri.
     Satu orang satu tim.</p>
  <div class="card">
    <table class="orang tim">
      <tr><th>Nama</th><th>Orang</th><th></th></tr>
      <?php foreach ($allTeams as $t): $pakai = repo_team_usage((int) $t['id']); ?>
      <tr class="<?= $t['active'] ? '' : 'off' ?>">
        <td>
          <form class="row" method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="do" value="renteam">
            <input type="hidden" name="team" value="<?= (int) $t['id'] ?>">
            <input name="name" value="<?= h($t['name']) ?>" maxlength="60" required>
            <button class="quiet kecil" type="submit">Ganti nama</button>
          </form>
          <?php if (!$t['active']): ?><span class="tag">dimatikan</span><?php endif; ?>
        </td>
        <td class="k"><span class="lbl">Isinya</span><?= $pakai['orang'] ?> orang<?=
            $pakai['laporan'] ? ' · ' . $pakai['laporan'] . ' laporan' : '' ?></td>
        <td>
          <a href="soal.php?team=<?= (int) $t['id'] ?>">Pertanyaan tim ini</a>
          <form class="row" method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="team" value="<?= (int) $t['id'] ?>">
            <button class="quiet kecil<?= $t['active'] ? ' bahaya' : '' ?>" type="submit"
              name="do" value="<?= $t['active'] ? 'timmati' : 'timaktif' ?>"
              title="<?= $t['active']
                ? 'Namanya hilang dari semua pilihan. Laporan lamanya tetap bisa dibaca.'
                : 'Tim ini muncul lagi di semua pilihan.' ?>"><?=
              $t['active'] ? 'Matikan' : 'Nyalakan' ?></button>
            <?php if ($pakai['orang'] === 0 && $pakai['laporan'] === 0 && count($allTeams) > 1): ?>
              <button class="quiet kecil bahaya" type="submit" name="do" value="hapustim"
                onclick="return confirm('Hapus tim ini? Pertanyaan dan modulnya ikut terhapus. Tidak bisa dibatalkan.')"
                title="Belum pernah dipakai, jadi tidak ada riwayat yang ikut hilang."
                >Hapus</button>
            <?php endif; ?>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <p class="why" style="margin:0.75rem 0 0">Tim yang sudah pernah dipakai tidak bisa
       dihapus — <strong>dimatikan</strong> saja: namanya hilang dari semua pilihan, tapi
       rekap lamanya tetap bisa dibaca. Yang bisa dihapus hanya tim yang belum punya orang
       dan belum punya satu laporan pun. Mau ganti nama saja? Ketik di kotaknya, tekan
       <em>Ganti nama</em> — laporan lamanya ikut, tidak ada yang lepas.</p>
    <form method="post" style="margin:1.25rem 0 0">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="do" value="addteam">
      <input name="name" placeholder="nama tim baru" maxlength="60" required>
      <button type="submit">Tambah tim</button>
    </form>
  </div>
<script>
/* Link sekali pakai paling sering gagal bukan karena salah dibuat, tapi karena salah
   diseleksi — satu karakter tertinggal, dan yang menerimanya melihat halaman "link tidak
   berlaku" tanpa tahu kenapa. */
document.addEventListener("click", function (ev) {
  var b = ev.target.closest && ev.target.closest("[data-salin]");
  if (!b) return;
  var semula = b.textContent;
  var sudah = function () {
    b.textContent = "Tersalin \u2713";
    setTimeout(function () { b.textContent = semula; }, 1800);
  };
  // Tanpa izin papan klip: linknya diseleksi, jadi Ctrl+C masih satu ketukan.
  var gagal = function () {
    var kotak = b.closest("tr") ? b.closest("tr").querySelector("code") : null;
    if (!kotak) { b.textContent = "Salin sendiri linknya"; return; }
    var r = document.createRange(); r.selectNodeContents(kotak);
    var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
  };
  try { navigator.clipboard.writeText(b.dataset.salin).then(sudah, gagal); }
  catch (e) { gagal(); }
});
</script>
<?php
page_foot();
