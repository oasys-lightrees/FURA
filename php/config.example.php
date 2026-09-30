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

    // Google Chat: open the squad's space -> nama space -> Apps & integrations ->
    // Webhooks -> Add webhooks -> beri nama "MESSI" -> salin URL-nya ke sini.
    // Kosongkan dan aplikasinya tetap jalan penuh — cuma tidak ada pesan otomatis.
    'chat_webhook' => '',

    // Opsional: space terpisah untuk rekap jam 18:00. Kosong berarti rekapnya ikut
    // ke space squad di atas, sebagaimana laporan MESSI memang selalu diposting di sana.
    'chat_webhook_leader' => '',

    // The cron URL carries this. Without it anyone could trigger the morning messages.
    // Make a long random one: openssl rand -hex 24
    'cron_key' => 'ganti-ini-jadi-acak-panjang',

    // The squad's first reporting day. Nobody is marked absent before it.
    'first_day' => '2026-09-28',

    // How long a login lasts. Long on purpose: a daily report people have to log in for
    // is a daily report that does not get written.
    'session_days' => 30,
];
