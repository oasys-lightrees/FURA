<?php
/**
 * "Saya lupa passwordnya."
 *
 * Sebelum halaman ini ada, halaman masuk tidak punya satu pun petunjuk: yang lupa
 * menatap layar tanpa tahu harus ke siapa. Yang paling dirugikan bukan pelapor — dia
 * punya atasan di ruangan yang sama — tapi owner yang sendirian memegang pemasangannya.
 *
 * Dua jalan pulang, dan yang mana tergantung pemasangannya. Kalau `mail_from` di
 * config.php sudah diisi, link masuk sekali pakai dikirim ke emailnya sendiri dan tidak
 * ada yang perlu menunggu siapa pun. Kalau belum, yang dikerjakan cuma menitipkan pesan:
 * admin melihat barisnya di halaman Orang & tim dan mengeluarkan linknya. Permintaannya
 * dicatat dua-duanya, karena email bisa gagal tanpa ada yang tahu.
 *
 * Jawabannya selalu sama, terdaftar atau tidak. Halaman yang menjawab berbeda adalah
 * daftar nama siapa saja yang bekerja di sini, dan siapa pun boleh membukanya.
 */

declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require __DIR__ . '/lib/require-php8.php';

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/layout.php';

messi_require_ready();

$sent = false;
$bisa = true;
$lewatEmail = mail_enabled();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    // Kotak kosong tidak punya apa pun untuk dititipkan, tapi juga bukan tanda
    // pemasangannya belum dimutakhirkan — jangan sampai jawabannya menuduh yang salah.
    // Dibatasi seperti percobaan masuk, dan dari pasangan (email, IP) yang sama — supaya
    // menekan tombolnya seratus kali tidak lebih berguna daripada sekali, dan supaya
    // inbox orang lain tidak bisa dibanjiri dari sini.
    if ($email !== '' && !auth_throttled($email)) {
        // Dikirim kalau bisa, dan dicatat apa pun hasilnya. Email yang gagal tidak
        // meninggalkan siapa pun tanpa jalan pulang, dan permintaan yang sudah selesai
        // hilang sendiri dari halaman admin begitu orangnya berhasil masuk.
        auth_mail_reset($email);
        $bisa = auth_ask_reset($email);
        auth_note_failure($email);
    }
    $sent = true;
}

page_head('Lupa password', ['center' => true]);
?>
<div class="card">
  <h1>Lupa password</h1>
<?php if ($sent && !$bisa): ?>
  <p class="note warn">Permintaanmu belum bisa dicatat: database pemasangan ini belum
     dimutakhirkan.</p>
  <p class="why">Hubungi adminmu langsung dan minta link masuk sekali pakai. Kalau kamu
     sendiri adminnya, buka <strong>upgrade.php</strong> setelah masuk, lalu halaman ini
     berfungsi seperti seharusnya.</p>
  <p class="why" style="margin-top:1.25rem"><a href="login.php">‹ Kembali ke halaman
     masuk</a></p>
<?php elseif ($sent && $lewatEmail): ?>
  <p class="note ok">Kalau alamat itu terdaftar, link masuknya sudah dikirim ke sana.</p>
  <p class="why">Cek inbox dan folder spam. Link-nya sekali pakai dan berlaku 60 menit;
     setelah masuk, ganti passwordmu dari menu <em>Akun</em>.</p>
  <p class="why">Permintaannya juga dicatat untuk adminmu, jadi kalau emailnya tidak
     sampai dalam beberapa menit, dia bisa mengeluarkan link masuk langsung untukmu.</p>
  <p class="why" style="margin-top:1.25rem"><a href="login.php">‹ Kembali ke halaman
     masuk</a></p>
<?php elseif ($sent): ?>
  <p class="note ok">Permintaanmu sudah dicatat.</p>
  <p class="why">Admin di perusahaanmu akan melihatnya di halaman <em>Orang &amp; tim</em>
     dan mengeluarkan link masuk sekali pakai untukmu. Link itu dikirim japri — lewat
     chat atau langsung — bukan ke space bersama.</p>
  <p class="why">Kalau kamu sendiri yang memegang pemasangan ini dan tidak ada admin
     lain, link-nya harus dibuat dari database. Langkahnya ada di
     <strong>PASANG.txt</strong>, satu folder dengan berkas ini.</p>
  <p class="why" style="margin-top:1.25rem"><a href="login.php">‹ Kembali ke halaman
     masuk</a></p>
<?php else: ?>
  <p class="why"><?= $lewatEmail
     ? 'Isi emailmu. Link masuk sekali pakai akan dikirim ke alamat itu, dan dari sana '
       . 'kamu bisa membuat password baru.'
     : 'Isi emailmu. Permintaannya dicatat, dan adminmu yang mengeluarkan link masuk '
       . 'sekali pakai — pemasangan ini belum mengirim email sendiri, jadi tidak ada yang '
       . 'akan masuk ke inbox.' ?></p>
  <form method="post">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" required autofocus autocomplete="username">
    <p style="margin:1.25rem 0 0"><button type="submit"><?= $lewatEmail
       ? 'Kirim link masuk' : 'Catat permintaan' ?></button></p>
  </form>
  <p class="why" style="margin-top:1.25rem"><a href="login.php">‹ Kembali ke halaman
     masuk</a></p>
<?php endif; ?>
</div>
<?php
page_foot();
