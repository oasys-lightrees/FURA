<?php
/**
 * "Saya lupa passwordnya."
 *
 * Sebelum halaman ini ada, halaman masuk tidak punya satu pun petunjuk: yang lupa
 * menatap layar tanpa tahu harus ke siapa. Yang paling dirugikan bukan pelapor — dia
 * punya atasan di ruangan yang sama — tapi owner yang sendirian memegang pemasangannya.
 *
 * Yang dikerjakan di sini sengaja kecil: menitipkan pesan, bukan mengirim email, karena
 * pemasangan ini belum bisa mengirim email sama sekali. Menjanjikan email yang tidak
 * pernah datang lebih buruk daripada mengatakan terus terang bahwa yang menolong adalah
 * admin.
 *
 * Jawabannya selalu sama, terdaftar atau tidak. Halaman yang menjawab berbeda adalah
 * daftar nama siapa saja yang bekerja di sini, dan siapa pun boleh membukanya.
 */

declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require __DIR__ . '/lib/require-php8.php';

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

messi_require_ready();

$sent = false;
$bisa = true;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    // Kotak kosong tidak punya apa pun untuk dititipkan, tapi juga bukan tanda
    // pemasangannya belum dimutakhirkan — jangan sampai jawabannya menuduh yang salah.
    // Dibatasi seperti percobaan masuk, dan dari pasangan (email, IP) yang sama — supaya
    // menekan tombolnya seratus kali tidak lebih berguna daripada sekali.
    if ($email !== '' && !auth_throttled($email)) {
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
  <p class="why">Isi emailmu. Permintaannya dicatat, dan adminmu yang mengeluarkan link
     masuk sekali pakai — sistem ini belum mengirim email sendiri, jadi tidak ada yang
     akan masuk ke inbox.</p>
  <form method="post">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" required autofocus autocomplete="username">
    <p style="margin:1.25rem 0 0"><button type="submit">Catat
       permintaan</button></p>
  </form>
  <p class="why" style="margin-top:1.25rem"><a href="login.php">‹ Kembali ke halaman
     masuk</a></p>
<?php endif; ?>
</div>
<?php
page_foot();
