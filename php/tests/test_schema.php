<?php
/**
 * Pemasangan lama harus bisa menyusul, dan hasilnya harus sama dengan pemasangan baru.
 *
 * Dua jalur menuju satu bentuk database: `install.sql` untuk yang baru, dan lib/schema.php
 * untuk yang sudah berisi laporan tiga bulan. Dua jalur yang tidak pernah dibandingkan
 * akan berbeda pelan-pelan, dan bedanya baru ketahuan di server orang lain. Jadi di sini
 * keduanya benar-benar dijalankan lalu dibandingkan kolom demi kolom.
 *
 *   MESSI_TEST_SOCKET=/var/run/mysqld/mysqld.sock php tests/test_schema.php
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap_test.php';
require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/repo.php';   // Cfg, yang ikut dibuang saat pindah database

$root = test_db('messi_schema_test');          // database baru dari install.sql hari ini

/** Bentuk sebuah database: kolom dan indeks tiap tabel, apa adanya dari MariaDB. */
function bentuk(PDO $pdo, string $db): array
{
    $out = [];
    $cols = $pdo->prepare(
        'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
           FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?
          ORDER BY TABLE_NAME, COLUMN_NAME');
    $cols->execute([$db]);
    foreach ($cols as $r) {
        $out['kolom'][$r['TABLE_NAME']][$r['COLUMN_NAME']] =
            $r['COLUMN_TYPE'] . '|' . $r['IS_NULLABLE'] . '|'
            . var_export($r['COLUMN_DEFAULT'], true) . '|' . $r['EXTRA'];
    }
    $idx = $pdo->prepare(
        'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
           FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ?
          GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY TABLE_NAME, INDEX_NAME');
    $idx->execute([$db]);
    foreach ($idx as $r) {
        $out['indeks'][$r['TABLE_NAME']][$r['INDEX_NAME']] = $r['NON_UNIQUE'] . ':' . $r['cols'];
    }
    return $out;
}

$baru = bentuk($root, $GLOBALS['MESSI_TEST_DBNAME']);

eq('database baru sudah lengkap sejak awal, tidak ada yang tertunda', schema_pending(), []);
eq('dan menjalankan upgrade padanya tidak mengubah apa pun', schema_upgrade(), []);

/* ------------------------------------------ pemasangan lama yang harus menyusul */

/**
 * Membangun database dari skema lama yang dibekukan, mengisinya dengan data sungguhan,
 * lalu menjalankan upgrade — persis urutan yang terjadi di server orang.
 */
function pasang_lama(PDO $root, string $fixture, string $db): void
{
    $root->exec("DROP DATABASE IF EXISTS `$db`");
    $root->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $root->exec("USE `$db`");
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(__DIR__ . '/fixtures/' . $fixture));
    foreach (explode(';', (string) $sql) as $stmt) {
        if (trim($stmt) !== '') {
            $root->exec($stmt);
        }
    }
}

foreach (['skema-lama-tanpa-settings.sql' => 'sebelum ada halaman pertanyaan',
          'skema-lama-dengan-settings.sql' => 'setelah halaman pertanyaan'] as $fixture => $sebutan) {

    $db = 'messi_schema_lama';
    pasang_lama($root, $fixture, $db);

    // Data sungguhan, supaya yang diuji bukan database kosong.
    $root->exec("INSERT INTO users (email, name, password_hash, role, joined_on, created_at)
                 VALUES ('a\\@x.test','Ami','h','admin','2026-09-28','2026-09-28 00:00:00'),
                        ('b\\@x.test','Budi','h','player','2026-09-28','2026-09-28 00:00:00')");
    $root->exec("INSERT INTO cycles (user_id, day, status, answers, submitted_at)
                 VALUES (2, '2026-09-30', 'submitted', '{\"detail\":\"ada\"}', '2026-09-30 05:00:00')");
    $root->exec("INSERT INTO commitments (user_id, cycle_id, action_text, due_date, status)
                 VALUES (2, 1, 'Telepon Klien A', '2026-10-01', 'open')");
    if (str_contains($fixture, 'dengan-settings')) {
        $root->exec("INSERT INTO settings (name, value, updated_at)
                     VALUES ('messi', '{\"threshold_days\":1}', '2026-09-30 05:00:00')");
    }

    // Aplikasinya diarahkan ke database lama itu.
    $GLOBALS['MESSI_CONFIG']['db']['name'] = $db;
    Db::forget();
    Cfg::forget();

    $pending = schema_pending();
    ok("$sebutan: ada yang tertunda, dan disebutkan", count($pending) > 0);
    ok("$sebutan: tiap langkah menjelaskan akibatnya ke datanya",
       count(array_filter($pending, fn($p) => strlen($p['why']) > 30)) === count($pending));

    $did = schema_upgrade();
    ok("$sebutan: upgrade mengerjakan sesuatu", count($did) > 0);
    eq("$sebutan: setelah upgrade tidak ada lagi yang tertunda", schema_pending(), []);
    eq("$sebutan: dijalankan dua kali tidak mengubah apa-apa lagi", schema_upgrade(), []);

    // Inti suite ini.
    eq("$sebutan: bentuk databasenya sama persis dengan pemasangan baru",
       bentuk($root, $db), $baru);

    // Dan datanya selamat.
    eq("$sebutan: orangnya tidak hilang",
       (int) q1('SELECT COUNT(*) AS n FROM users')['n'], 2);
    eq("$sebutan: laporannya tidak hilang",
       (int) q1('SELECT COUNT(*) AS n FROM cycles')['n'], 1);
    eq("$sebutan: janjinya tidak hilang",
       (int) q1('SELECT COUNT(*) AS n FROM commitments')['n'], 1);

    // Modul: MESSI yang selama ini berjalan harus ketemu lagi di katalog, bukan modul
    // contoh yang kosong — kalau tidak, pemutakhiran ini terasa seperti kehilangan.
    eq("$sebutan: tiap tim dapat satu modul MESSI",
       (int) q1("SELECT COUNT(*) AS n FROM modules WHERE code = 'MESSI'")['n'],
       (int) q1('SELECT COUNT(*) AS n FROM teams')['n']);
    eq("$sebutan: laporan lama ikut ditunjuk ke modul itu",
       (int) q1('SELECT COUNT(*) AS n FROM cycles WHERE module_id IS NULL')['n'], 0);

    $spec = modul_normalize(json_decode(
        (string) q1("SELECT spec FROM modules WHERE code = 'MESSI'")['spec'], true));
    eq("$sebutan: modulnya berisi pertanyaan MESSI, bukan modul kosong",
       $spec['fields'][0]['type'], 'grid');
    if (str_contains($fixture, 'dengan-settings')) {
        // Ambang yang sudah diubah admin ikut terbawa, bukan kembali ke bawaan.
        eq("$sebutan: ambang yang sudah disetel ikut terbawa",
           $spec['fields'][0]['cols'][1]['label'], 'Gantung >1 hari');
    }
    eq("$sebutan: semua orang punya tim",
       (int) q1('SELECT COUNT(*) AS n FROM users WHERE team_id IS NULL')['n'], 0);
    eq("$sebutan: dan semuanya tim yang sama, karena dulu memang satu tim",
       (int) q1('SELECT COUNT(DISTINCT team_id) AS n FROM users')['n'], 1);
    eq("$sebutan: laporan lama ikut ditandai timnya",
       (int) q1('SELECT COUNT(*) AS n FROM cycles WHERE team_id IS NULL')['n'], 0);
    // Orang yang sudah memakai sistemnya tidak boleh ikut terkunci di luar oleh undangan.
    eq("$sebutan: akun yang sudah ada dianggap sudah menerima undangannya",
       (int) q1('SELECT COUNT(*) AS n FROM users WHERE accepted_at IS NULL')['n'], 0);
    // Harus ada yang bisa mengangkat admin, kalau tidak perusahaannya terkunci.
    eq("$sebutan: admin pertama jadi owner",
       (string) q1('SELECT role FROM users ORDER BY id LIMIT 1')['role'], 'owner');
    eq("$sebutan: yang lain tidak ikut naik",
       (string) q1('SELECT role FROM users ORDER BY id DESC LIMIT 1')['role'], 'player');

    if (str_contains($fixture, 'dengan-settings')) {
        eq("$sebutan: setelan yang sudah diubah admin tidak hilang",
           (int) q1('SELECT COUNT(*) AS n FROM settings')['n'], 1);
        eq("$sebutan: dan sekarang dimiliki sebuah tim",
           (int) q1('SELECT COUNT(*) AS n FROM settings WHERE team_id = 0')['n'], 0);
    }
}

/* ------------------------------------------ berhenti di tengah, lalu dijalankan lagi */

// DDL di MySQL tidak bisa dibungkus transaksi, jadi upgrade yang mati di tengah memang
// bisa terjadi — listrik mati, PHP kehabisan waktu. Yang bisa dijamin bukan "tidak pernah
// terhenti", tapi "dijalankan lagi akan menyelesaikannya".
$db = 'messi_schema_lama';
pasang_lama($root, 'skema-lama-dengan-settings.sql', $db);
$root->exec("INSERT INTO users (email, name, password_hash, role, joined_on, created_at)
             VALUES ('c\@x.test','Cici','h','admin','2026-09-28','2026-09-28 00:00:00')");
$root->exec('INSERT INTO settings (name, value, updated_at)'
          . ' VALUES (\'messi\', \'{"threshold_days":2}\', \'2026-09-30 05:00:00\')');
$GLOBALS['MESSI_CONFIG']['db']['name'] = $db;
Db::forget();
Cfg::forget();

// Setengah jalan: tabel dan kolomnya sudah dibuat, isinya belum sempat dipindahkan.
schema_run_install_sql();
db()->exec('ALTER TABLE settings ADD COLUMN team_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER name');
db()->exec('ALTER TABLE users ADD COLUMN team_id INT UNSIGNED DEFAULT NULL AFTER role');

ok('upgrade yang terhenti di tengah masih terlihat sebagai pekerjaan yang tersisa',
   count(schema_pending()) > 0);
schema_upgrade();
eq('dan menjalankannya lagi menyelesaikannya', schema_pending(), []);
eq('hasilnya tetap sama persis dengan pemasangan baru', bentuk($root, $db), $baru);
eq('setelan yang separuh terpindah tidak ditinggalkan tanpa pemilik',
   (int) q1('SELECT COUNT(*) AS n FROM settings WHERE team_id = 0')['n'], 0);
eq('dan orangnya tetap punya tim',
   (int) q1('SELECT COUNT(*) AS n FROM users WHERE team_id IS NULL')['n'], 0);

// Varian kedua: kolomnya sudah ada DAN sudah terisi, tapi indeks dan kunci asingnya
// belum — yang terjadi kalau rangkaian ALTER-nya putus di antara keduanya. Di sini tidak
// ada baris kosong yang bisa memicu langkahnya, jadi yang harus menyadarinya adalah
// pemeriksaan indeksnya sendiri.
pasang_lama($root, 'skema-lama-dengan-settings.sql', $db);
$root->exec("INSERT INTO users (email, name, password_hash, role, joined_on, created_at)
             VALUES ('d\@x.test','Dedi','h','admin','2026-09-28','2026-09-28 00:00:00')");
$GLOBALS['MESSI_CONFIG']['db']['name'] = $db;
Db::forget();
Cfg::forget();
schema_run_install_sql();
db()->exec('ALTER TABLE users ADD COLUMN team_id INT UNSIGNED DEFAULT NULL AFTER role');
q('INSERT INTO teams (name, created_at) VALUES (?,?)', ['Tim', '2026-09-28 00:00:00']);
q('UPDATE users SET team_id = (SELECT id FROM teams ORDER BY id LIMIT 1)');

ok('indeks yang belum sempat dibuat juga terhitung pekerjaan yang tersisa',
   count(schema_pending()) > 0);
schema_upgrade();
eq('dan ikut diselesaikan', schema_pending(), []);
eq('bentuknya pun kembali sama persis', bentuk($root, $db), $baru);

$root->exec('DROP DATABASE IF EXISTS messi_schema_lama');
$root->exec('DROP DATABASE IF EXISTS `' . $GLOBALS['MESSI_TEST_DBNAME'] . '`');
done();
