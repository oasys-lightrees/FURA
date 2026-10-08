<?php
/**
 * Email keluar.
 *
 * Dua pesan saja, dan dua-duanya tentang satu hal: jalan masuk. Undangan untuk orang baru,
 * dan link masuk untuk yang lupa passwordnya. Sampai ini ada, keduanya harus disalin admin
 * lalu ditempel ke chat satu per satu — yang berarti undangan sepuluh orang adalah sepuluh
 * pekerjaan manual, dan orang yang lupa passwordnya di hari Sabtu menunggu sampai Senin.
 *
 * Dibuat dengan mail() bawaan PHP, bukan SMTP. Alasannya satu: di cPanel mail() langsung
 * jalan tanpa kredensial apa pun, sementara SMTP berarti satu password lagi untuk disimpan
 * di config.php dan satu lagi hal yang bisa salah ketik. Yang hilang: pesan masuk spam
 * lebih sering kalau domainnya belum punya SPF. Itu disebutkan di PASANG.txt, bukan
 * disembunyikan.
 *
 * Kalau mail_from kosong, seluruh berkas ini diam dan semua halaman kembali ke perilaku
 * sebelumnya: link disalin admin. Menjanjikan email yang tidak pernah datang jauh lebih
 * buruk daripada mengatakan terus terang bahwa yang menolong adalah admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Satu celah, supaya tes bisa membaca apa yang akan dikirim tanpa MTA dan tanpa jaringan. */
final class Mail
{
    /** @var null|callable(string,string,string,string):bool  ke, nama, judul, isi */
    public static $send = null;
}

/** Dari siapa pesannya. Nama boleh kosong; alamatnya tidak. */
function mail_from(): array
{
    $nama = trim((string) cfg('mail_name'));
    return ['email' => trim((string) cfg('mail_from')),
            'name'  => $nama !== '' ? $nama : 'FURA'];
}

function mail_enabled(): bool
{
    if (Mail::$send !== null) {
        return true;
    }
    $from = mail_from()['email'];
    return $from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Teks untuk dipasang di header.
 *
 * Header email hanya boleh ASCII, jadi nama ber-aksen harus dibungkus. Dan baris baru di
 * dalam header adalah cara menyuntikkan header lain — satu "\nBcc: ..." di nama tim sudah
 * cukup untuk mengirim salinan tiap undangan ke mana pun. Nama tim itu diketik admin, dan
 * admin bukan orang yang paling tepercaya di sini: dia cuma orang yang paling banyak
 * mengetik.
 */
function mail_header_text(string $s): string
{
    $s = trim((string) preg_replace('/[\r\n]+/', ' ', $s));
    return preg_match('/[^\x20-\x7E]/', $s)
        ? '=?UTF-8?B?' . base64_encode($s) . '?='
        : $s;
}

/**
 * Mengirim satu email. Gagal dicatat, tidak pernah fatal.
 *
 * Yang memanggil selalu punya rencana kedua — linknya tetap ditampilkan untuk disalin —
 * jadi email yang tidak terkirim membuat pekerjaannya kembali manual, bukan hilang.
 */
function mail_send(string $to, string $toName, string $subject, string $body): bool
{
    if (!mail_enabled()) {
        return false;
    }
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        log_job('mail_fail', 'alamat tidak sah');
        return false;
    }
    if (Mail::$send !== null) {
        return (Mail::$send)($to, $toName, $subject, $body);
    }

    $from = mail_from();
    $headers = implode("\r\n", [
        'From: ' . mail_header_text($from['name']) . ' <' . $from['email'] . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        // Pesan ini tidak menunggu balasan, dan pemberitahuan "saya sedang cuti" yang
        // membalasnya tidak boleh memicu pesan berikutnya.
        'Auto-Submitted: auto-generated',
        'X-Mailer: FURA',
    ]);
    $teks = (string) preg_replace("/\r\n?|\n/", "\r\n", $body);
    $judul = mail_header_text($subject);

    // -f memberi tahu amplopnya dari siapa, yang membuat pesannya lolos SPF di sebagian
    // hosting. Di sebagian lainnya parameter tambahan justru dilarang sama sekali, dan
    // yang terjadi bukan pesan kesalahan — cuma false. Jadi dicoba sekali lagi tanpa itu.
    $ok = @mail($to, $judul, $teks, $headers, '-f' . $from['email']);
    if (!$ok) {
        $ok = @mail($to, $judul, $teks, $headers);
    }
    if (!$ok) {
        log_job('mail_fail', 'mail() menolak mengirim ke ' . substr($to, 0, 120));
    }
    return (bool) $ok;
}

/* -------------------------------------------------------------- dua pesannya */

/** Nama perusahaan di mata penerima: nama tim pertama kalau ada, kalau tidak "FURA". */
function mail_signature(): string
{
    $base = rtrim((string) cfg('base_url'), '/');
    return "\n\n—\nFURA · Follow Up Report Automation"
         . ($base === '' ? '' : "\n" . $base);
}

/**
 * Undangan, atau link untuk membuat password baru.
 *
 * $baru memisahkan keduanya. Orang yang baru diundang perlu diberi tahu ini tempat apa;
 * orang yang sudah dua bulan memakainya tidak — dia cuma lupa passwordnya, dan paragraf
 * perkenalan di situ terbaca seperti email dari sistem lain.
 */
function mail_invite(array $user, string $url, bool $baru): bool
{
    $nama = (string) ($user['name'] ?? '');
    $isi = $baru
        ? "Halo " . $nama . ",\n\n"
          . "Kamu didaftarkan di FURA, tempat laporan harian tim dicatat. Buat "
          . "passwordmu sendiri lewat link ini — yang mendaftarkanmu tidak akan tahu "
          . "passwordnya:\n\n" . $url . "\n\n"
          . "Link ini sekali pakai dan berlaku 72 jam. Kalau sudah kedaluwarsa, minta "
          . "adminmu mengirim yang baru."
        : "Halo " . $nama . ",\n\n"
          . "Ini link untuk membuat password baru akun FURA-mu:\n\n" . $url . "\n\n"
          . "Link ini sekali pakai dan berlaku 72 jam. Kalau bukan kamu yang meminta, "
          . "abaikan saja — password yang sekarang tetap berlaku.";
    return mail_send((string) ($user['email'] ?? ''), $nama,
                     $baru ? 'Undangan FURA' : 'Password baru untuk akun FURA-mu',
                     $isi . mail_signature());
}

/** Link masuk sekali pakai, untuk yang lupa passwordnya. */
function mail_login_link(array $user, string $url, int $minutes): bool
{
    $nama = (string) ($user['name'] ?? '');
    $isi = "Halo " . $nama . ",\n\n"
         . "Ada yang meminta link masuk untuk akun FURA ini. Buka link di bawah, lalu "
         . "ganti passwordmu dari menu Akun:\n\n" . $url . "\n\n"
         . "Link ini sekali pakai dan berlaku " . $minutes . " menit. Kalau bukan kamu "
         . "yang meminta, abaikan saja — password yang sekarang tetap berlaku, dan link "
         . "ini hangus sendiri.";
    return mail_send((string) ($user['email'] ?? ''), $nama,
                     'Link masuk FURA', $isi . mail_signature());
}
