<?php
/**
 * Membawa database yang sudah jalan ke bentuk yang dibutuhkan kode hari ini.
 *
 * `install.sql` hanya berisi CREATE TABLE IF NOT EXISTS. Itu tepat untuk pemasangan baru
 * dan aman diulang, tapi tidak pernah menyentuh tabel yang sudah ada — jadi kolom dan
 * nilai enum yang baru tidak akan pernah sampai ke database yang sudah berisi laporan
 * tiga bulan. Berkas ini yang menutup jarak itu.
 *
 * Dua aturan yang membuatnya bisa dipercaya:
 *
 * 1. Tiap langkah memeriksa dirinya sendiri lewat information_schema, bukan lewat catatan
 *    versi. Catatan versi bisa salah; keadaan database tidak. Dijalankan dua kali tidak
 *    melakukan apa-apa pada kali kedua.
 * 2. Hasil akhirnya harus sama persis dengan hasil install.sql yang baru. Itu bukan
 *    harapan, itu yang diperiksa tests/test_schema.php dengan membandingkan kedua skema
 *    kolom demi kolom — satu-satunya cara agar dua jalur ini tidak pelan-pelan berbeda.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function schema_has_table(string $table): bool
{
    return (bool) q1('SELECT 1 AS n FROM information_schema.TABLES
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
}

function schema_has_column(string $table, string $column): bool
{
    return (bool) q1('SELECT 1 AS n FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                     [$table, $column]);
}

function schema_column_type(string $table, string $column): string
{
    $row = q1('SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
              [$table, $column]);
    return (string) ($row['t'] ?? '');
}

/** Kunci asing diperiksa sendiri: kolom boleh sudah ada sementara kuncinya belum,
 *  misalnya karena upgrade sebelumnya berhenti di antara keduanya. */
function schema_has_fk(string $table, string $name): bool
{
    return (bool) q1('SELECT 1 AS n FROM information_schema.TABLE_CONSTRAINTS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                         AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
                     [$table, $name, 'FOREIGN KEY']);
}

function schema_has_key(string $table, string $key): bool
{
    return (bool) q1('SELECT 1 AS n FROM information_schema.STATISTICS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                     [$table, $key]);
}

/**
 * Langkah-langkahnya, berurutan.
 *
 * `why` ditulis untuk admin yang membaca halaman upgrade, bukan untuk programer: dia
 * yang menekan tombolnya, dan dia berhak tahu apa yang akan berubah di datanya.
 */
/**
 * Apakah indeks unik ini persis berisi kolom-kolom itu, dalam urutan itu.
 *
 * Bukan cuma "ada indeks bernama begitu": yang diubah langkah di bawah adalah isinya,
 * dan indeks lama yang namanya kebetulan sama akan terbaca sebagai sudah selesai.
 */
function schema_unique_is(string $table, string $key, array $columns): bool
{
    $rows = q('SELECT column_name FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
                  AND non_unique = 0
                ORDER BY seq_in_index', [$table, $key])->fetchAll(PDO::FETCH_COLUMN);
    return array_map('strtolower', $rows) === array_map('strtolower', $columns);
}

function schema_steps(): array
{
    return [
        [
            'id'   => 'teams',
            'why'  => 'Membuat tabel tim, lalu memindahkan semua orang yang ada ke satu tim '
                    . 'bernama "Tim" — yang bisa diganti namanya setelah ini.',
            'todo' => fn() => !schema_has_table('teams')
                           || !schema_has_column('users', 'team_id')
                           || !schema_has_key('users', 'ix_users_team')
                           || !schema_has_fk('users', 'fk_users_team')
                           || (int) q1('SELECT COUNT(*) AS n FROM users WHERE team_id IS NULL')['n'] > 0,
            'run'  => function (): void {
                if (!schema_has_column('users', 'team_id')) {
                    db()->exec('ALTER TABLE users ADD COLUMN team_id INT UNSIGNED DEFAULT NULL
                                  AFTER role');
                }
                if (!schema_has_key('users', 'ix_users_team')) {
                    db()->exec('ALTER TABLE users ADD KEY ix_users_team (team_id)');
                }
                if (!schema_has_fk('users', 'fk_users_team')) {
                    db()->exec('ALTER TABLE users ADD CONSTRAINT fk_users_team
                                  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL');
                }
                // Semua orang harus punya tim, kalau tidak rekapnya kehilangan mereka.
                if ((int) q1('SELECT COUNT(*) AS n FROM users WHERE team_id IS NULL')['n'] > 0) {
                    $team = q1('SELECT id FROM teams ORDER BY id LIMIT 1');
                    if (!$team) {
                        q('INSERT INTO teams (name, created_at) VALUES (?,?)',
                          ['Tim', Clock::nowUtcSql()]);
                        $team = ['id' => db()->lastInsertId()];
                    }
                    q('UPDATE users SET team_id = ? WHERE team_id IS NULL', [(int) $team['id']]);
                }
            },
        ],
        [
            'id'   => 'users.role owner',
            'why'  => 'Menambah peran owner. Peran yang sudah ada tidak berubah; akun admin '
                    . 'pertama dinaikkan jadi owner supaya ada yang bisa mengangkat admin.',
            'todo' => fn() => !str_contains(schema_column_type('users', 'role'), "'owner'")
                           || (!q1("SELECT id FROM users WHERE role = 'owner' LIMIT 1")
                               && q1("SELECT id FROM users WHERE role = 'admin' LIMIT 1")),
            'run'  => function (): void {
                if (!str_contains(schema_column_type('users', 'role'), "'owner'")) {
                    db()->exec("ALTER TABLE users MODIFY COLUMN role
                                  ENUM('player','leader','admin','owner') NOT NULL DEFAULT 'player'");
                }
                // Perusahaan tanpa owner adalah perusahaan yang tidak bisa mengangkat admin.
                if (!q1("SELECT id FROM users WHERE role = 'owner' LIMIT 1")) {
                    $first = q1("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
                    if ($first) {
                        q("UPDATE users SET role = 'owner' WHERE id = ?", [(int) $first['id']]);
                    }
                }
            },
        ],
        [
            'id'   => 'users.invited_at',
            'why'  => 'Mencatat siapa yang diundang dan siapa yang sudah membuat passwordnya '
                    . 'sendiri. Akun yang sudah ada dianggap sudah menerima undangannya.',
            // Sisa pekerjaan ikut dihitung, bukan cuma ada-tidaknya kolom: kalau langkah
            // ini pernah berhenti separuh jalan, menjalankannya lagi harus menyelesaikannya.
            'todo' => fn() => !schema_has_column('users', 'invited_at')
                           || (int) q1("SELECT COUNT(*) AS n FROM users
                                         WHERE accepted_at IS NULL AND password_hash <> ''")['n'] > 0,
            'run'  => function (): void {
                if (!schema_has_column('users', 'invited_at')) {
                    db()->exec('ALTER TABLE users
                                  ADD COLUMN invited_at DATETIME DEFAULT NULL AFTER active,
                                  ADD COLUMN accepted_at DATETIME DEFAULT NULL AFTER invited_at');
                }
                // Mereka sudah punya password yang dipakai; jangan sampai terkunci di luar.
                q("UPDATE users SET accepted_at = created_at
                    WHERE accepted_at IS NULL AND password_hash <> ''");
            },
        ],
        [
            'id'   => 'users.reset_asked_at',
            'why'  => 'Mencatat siapa yang bilang lupa passwordnya, supaya permintaannya '
                    . 'muncul di halaman Orang & tim. Tanpa ini, orang yang lupa tidak '
                    . 'punya satu pun jalan pulang selain menelepon seseorang.',
            'todo' => fn() => !schema_has_column('users', 'reset_asked_at'),
            'run'  => fn() => db()->exec('ALTER TABLE users ADD COLUMN reset_asked_at
                                  DATETIME DEFAULT NULL AFTER accepted_at'),
        ],
        [
            'id'   => 'login_tokens.kind',
            'why'  => 'Membedakan link masuk dari undangan. Link yang sudah ada tetap '
                    . 'berlaku sebagai link masuk.',
            'todo' => fn() => !schema_has_column('login_tokens', 'kind'),
            'run'  => fn() => db()->exec("ALTER TABLE login_tokens ADD COLUMN kind
                                  ENUM('login','invite') NOT NULL DEFAULT 'login' AFTER user_id"),
        ],
        [
            'id'   => 'cycles.team_id',
            'why'  => 'Mencatat tim di tiap laporan, supaya memindahkan orang ke tim lain '
                    . 'tidak mengubah rekap bulan lalu. Laporan yang sudah ada diisi dengan '
                    . 'tim orangnya sekarang.',
            'todo' => fn() => !schema_has_column('cycles', 'team_id')
                           || !schema_has_fk('cycles', 'fk_cycles_team')
                           || (int) q1('SELECT COUNT(*) AS n FROM cycles c
                                          JOIN users u ON u.id = c.user_id
                                         WHERE c.team_id IS NULL AND u.team_id IS NOT NULL')['n'] > 0,
            'run'  => function (): void {
                if (!schema_has_column('cycles', 'team_id')) {
                    db()->exec('ALTER TABLE cycles ADD COLUMN team_id INT UNSIGNED DEFAULT NULL
                                  AFTER day');
                }
                if (!schema_has_fk('cycles', 'fk_cycles_team')) {
                    db()->exec('ALTER TABLE cycles ADD CONSTRAINT fk_cycles_team
                                  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL');
                }
                q('UPDATE cycles c JOIN users u ON u.id = c.user_id
                      SET c.team_id = u.team_id WHERE c.team_id IS NULL');
            },
        ],
        [
            'id'   => 'settings.team_id',
            'why'  => 'Pertanyaan dan ambang jadi milik tim, bukan milik seluruh perusahaan. '
                    . 'Setelan yang sudah ada diberikan ke tim pertama.',
            // Langkah ini punya empat bagian, jadi syaratnya memeriksa keempatnya. Berhenti
            // di tengah lalu dijalankan lagi harus menyelesaikan sisanya, bukan melewatinya.
            'todo' => fn() => schema_has_table('settings')
                           && (!schema_has_column('settings', 'team_id')
                               || (int) q1('SELECT COUNT(*) AS n FROM settings WHERE team_id = 0')['n'] > 0
                               || !schema_has_key('settings', 'ix_settings_team')
                               || !schema_has_fk('settings', 'fk_settings_team')),
            'run'  => function (): void {
                if (!schema_has_column('settings', 'team_id')) {
                    db()->exec('ALTER TABLE settings ADD COLUMN team_id INT UNSIGNED NOT NULL
                                  DEFAULT 0 AFTER name');
                }
                $team = q1('SELECT id FROM teams ORDER BY id LIMIT 1');
                if ($team) {
                    q('UPDATE settings SET team_id = ? WHERE team_id = 0', [(int) $team['id']]);
                }
                // Baris yatim: tidak ada tim yang bisa memilikinya, dan setelan tanpa
                // pemilik tidak pernah terbaca lagi. Bawaannya tetap berlaku.
                q('DELETE FROM settings WHERE team_id = 0');
                if (!schema_has_key('settings', 'ix_settings_team')) {
                    db()->exec('ALTER TABLE settings
                                  DROP PRIMARY KEY,
                                  ADD PRIMARY KEY (name, team_id),
                                  ALTER COLUMN team_id DROP DEFAULT,
                                  ADD KEY ix_settings_team (team_id)');
                }
                if (!schema_has_fk('settings', 'fk_settings_team')) {
                    db()->exec('ALTER TABLE settings ADD CONSTRAINT fk_settings_team
                                  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE');
                }
            },
        ],
        [
            'id'   => 'modules',
            'why'  => 'Membuat tabel modul, lalu menuliskan MESSI yang sekarang berjalan ke '
                    . 'dalamnya — lengkap dengan channel, ambang dan kalimat yang sudah kamu '
                    . 'ubah. Setelah ini modul bisa ditambah sendiri dari halaman Kelola, dan '
                    . 'MESSI jadi salah satunya, bukan satu-satunya.',
            'todo' => fn() => !schema_has_table('modules')
                           || !schema_has_column('cycles', 'module_id')
                           || !schema_has_fk('cycles', 'fk_cycles_module')
                           || !schema_unique_is('cycles', 'uq_cycle_day',
                                                ['user_id', 'day', 'module_id'])
                           // Laporan lama yang belum menunjuk ke modulnya. Sengaja tidak
                           // ikut memeriksa "tiap tim punya modul": tim lahir setelah
                           // pemasangan, dan pemasangan yang baru saja jadi akan terbaca
                           // sebagai tertunda padahal belum ada apa-apa untuk dikerjakan.
                           // Penyemaiannya ditangani halaman yang membutuhkannya.
                           || (int) q1('SELECT COUNT(*) AS n FROM cycles
                                         WHERE module_id IS NULL AND team_id IS NOT NULL')['n'] > 0,
            'run'  => function (): void {
                if (!schema_has_table('modules')) {
                    db()->exec("CREATE TABLE modules (
                        id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        team_id    INT UNSIGNED NOT NULL,
                        code       VARCHAR(12) NOT NULL,
                        spec       LONGTEXT NOT NULL CHECK (JSON_VALID(spec)),
                        active     TINYINT(1) NOT NULL DEFAULT 1,
                        sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_by INT UNSIGNED DEFAULT NULL,
                        PRIMARY KEY (id),
                        UNIQUE KEY uq_module_code (team_id, code),
                        KEY ix_modules_team (team_id, sort_order),
                        CONSTRAINT fk_modules_team FOREIGN KEY (team_id)
                            REFERENCES teams(id) ON DELETE CASCADE,
                        CONSTRAINT fk_modules_user FOREIGN KEY (updated_by)
                            REFERENCES users(id) ON DELETE SET NULL
                      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }

                // Tiap tim mendapat MESSI-nya sendiri, dibangun dari setelan tim itu.
                require_once __DIR__ . '/katalog.php';
                foreach (q('SELECT id FROM teams') as $t) {
                    katalog_seed((int) $t['id']);
                }

                if (!schema_has_column('cycles', 'module_id')) {
                    db()->exec('ALTER TABLE cycles ADD COLUMN module_id INT UNSIGNED DEFAULT NULL
                                  AFTER team_id');
                }
                // Laporan yang sudah ada semuanya laporan MESSI — waktu itu tidak ada
                // modul lain untuk dilaporkan.
                q("UPDATE cycles c JOIN modules m ON m.team_id = c.team_id AND m.code = 'MESSI'
                      SET c.module_id = m.id WHERE c.module_id IS NULL");

                if (!schema_unique_is('cycles', 'uq_cycle_day',
                                      ['user_id', 'day', 'module_id'])) {
                    // Satu orang satu hari satu laporan — per modul, bukan lagi seluruhnya.
                    db()->exec('ALTER TABLE cycles DROP INDEX uq_cycle_day,
                                  ADD UNIQUE KEY uq_cycle_day (user_id, day, module_id)');
                }
                if (!schema_has_key('cycles', 'ix_cycles_module')) {
                    db()->exec('ALTER TABLE cycles ADD KEY ix_cycles_module (module_id)');
                }
                if (!schema_has_fk('cycles', 'fk_cycles_module')) {
                    db()->exec('ALTER TABLE cycles ADD CONSTRAINT fk_cycles_module
                                  FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE RESTRICT');
                }
            },
        ],
    ];
}

/** Langkah yang masih harus dijalankan. Daftar kosong berarti databasenya sudah pas. */
function schema_pending(): array
{
    $out = [];
    foreach (schema_steps() as $step) {
        if (($step['todo'])()) {
            $out[] = ['id' => $step['id'], 'why' => $step['why']];
        }
    }
    return $out;
}

/** install.sql dijalankan persis seperti phpMyAdmin menjalankannya. */
function schema_run_install_sql(): void
{
    $path = dirname(__DIR__) . '/install.sql';
    if (!is_file($path)) {
        return;
    }
    // Baris komentar dibuang dulu: memecah per ";" tanpa itu akan menyisakan potongan
    // yang diawali "--" dan menelan perintah berikutnya.
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($path));
    foreach (explode(';', (string) $sql) as $one) {
        if (trim($one) !== '') {
            db()->exec($one);
        }
    }
}

/**
 * Membuat tabel yang belum ada, lalu menjalankan langkah yang belum dijalankan.
 * Mengembalikan daftar apa yang barusan dikerjakan — kosong berarti tidak ada yang perlu.
 */
/**
 * Menyusulkan databasenya sendiri, tanpa menunggu ada yang menekan tombol.
 *
 * Tombol "Jalankan" itu dulu dipasang sebagai penjagaan: perubahan bentuk database tidak
 * bisa dibatalkan, jadi biar ada manusia yang menekannya. Dalam praktiknya tidak ada satu
 * pun kali di mana jawabannya "jangan" — yang terjadi cuma seluruh tim menatap halaman
 * "belum siap" sampai adminnya sempat membuka laptop. Penjagaan yang selalu dilewati
 * bukan penjagaan, cuma tembok.
 *
 * Yang membuat ini aman bukan tombolnya, melainkan sifat langkah-langkahnya: menambah,
 * bukan membuang; dan tiap langkah memeriksa dirinya sendiri, jadi berhenti di tengah
 * lalu dijalankan lagi akan menyelesaikan sisanya.
 *
 * Gagal pun tidak membuat halamannya meledak: yang tertunda tetap tertunda, temboknya
 * muncul seperti dulu, dan alasannya tercatat di job_log supaya halaman Cek sistem bisa
 * menyebutkannya.
 */
function schema_upgrade_auto(): array
{
    // Kunci tingkat server, bukan tingkat tabel. Dua permintaan yang datang bersamaan akan
    // menjalankan ALTER yang sama pada tabel yang sama, dan MySQL tidak membungkus DDL
    // dalam transaksi — yang kedua tidak ditolak dengan rapi, dia gagal di tengah.
    $punya = (int) (q1('SELECT GET_LOCK(?, ?) AS k', ['fura_schema', 10])['k'] ?? 0);
    if ($punya !== 1) {
        return [];                 // ada yang sedang mengerjakannya; yang ini tidak ikut campur
    }
    try {
        // Diperiksa lagi setelah kuncinya dipegang: menunggu tadi justru bisa berarti ada
        // yang sedang menyelesaikannya, dan sekarang sudah tidak ada sisa apa pun.
        if (!schema_pending()) {
            return [];
        }
        $did = schema_upgrade();
        if ($did) {
            log_job('schema_auto', implode(', ', $did));
        }
        return $did;
    } catch (Throwable $e) {
        // Ditelan di sini, tapi tidak disembunyikan: temboknya kembali muncul, dan
        // alasannya ada di job_log untuk dibaca halaman Cek sistem.
        try {
            log_job('schema_error', substr($e->getMessage(), 0, 400));
        } catch (Throwable $x) {
            // databasenya sendiri yang tidak bisa dihubungi: tidak ada tempat mencatat
        }
        return [];
    } finally {
        q_opt('SELECT RELEASE_LOCK(?)', ['fura_schema']);
    }
}

function schema_upgrade(): array
{
    schema_run_install_sql();
    $did = [];
    foreach (schema_steps() as $step) {
        if (($step['todo'])()) {
            ($step['run'])();
            $did[] = $step['id'];
        }
    }
    return $did;
}
