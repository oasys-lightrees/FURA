<?php
/**
 * Copy this to config.php and fill it in. config.php is gitignored — it holds passwords.
 *
 * In cPanel the database name and user are prefixed with your account name, e.g. an
 * account `lightree` with a database called `messi` gets `lightree_messi`. Copy the exact
 * strings from MySQL® Databases.
 */

return [
    'db' => [
        'host' => 'localhost',
        'name' => 'akun_messi',
        'user' => 'akun_messi',
        'pass' => 'ganti-ini',
    ],

    // Where the app lives, with no trailing slash. Used to build login links.
    'base_url' => 'https://contoh.com/messi',

    // Google Chat: open the team's space -> nama space -> Apps & integrations ->
    // Webhooks -> Add webhooks -> beri nama "MESSI" -> salin URL-nya ke sini.
    // Kosongkan dan aplikasinya tetap jalan penuh — cuma tidak ada pesan otomatis.
    'chat_webhook' => '',

    // Email keluar: undangan dan link masuk dikirim sendiri, bukan disalin admin.
    // Di cPanel, buat dulu satu akun email di domain ini (Email Accounts), misalnya
    // fura@contoh.com, lalu tulis alamatnya di sini — alamat di domain lain (gmail.com)
    // hampir selalu masuk spam. Kosongkan dan semuanya kembali seperti sebelumnya:
    // linknya ditampilkan di halaman Orang & tim untuk disalin dan dikirim japri.
    'mail_from' => '',
    'mail_name' => 'FURA',

    // The cron URL carries this. Without it anyone could trigger the morning messages.
    // Make a long random one: openssl rand -hex 24
    'cron_key' => 'ganti-ini-jadi-acak-panjang',

    // The team's first reporting day. Nobody is marked absent before it.
    'first_day' => '2026-09-28',

    // How long a login lasts. Long on purpose: a daily report people have to log in for
    // is a daily report that does not get written.
    'session_days' => 30,
];
