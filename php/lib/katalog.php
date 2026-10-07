<?php
/**
 * Modul milik tim: menyimpan, membaca, mengurutkan.
 *
 * Dipisahkan dari lib/modul.php dengan sengaja. Di sana aturannya murni — bisa diuji
 * tanpa database sama sekali, dan 63 pemeriksaannya jalan dalam sekejap. Begitu
 * database ikut masuk, tes jadi lambat dan orang berhenti menjalankannya.
 *
 * Satu modul milik satu tim, bukan milik perusahaan. Alasannya sama dengan alasan
 * pertanyaan MESSI milik tim: HR dan sales tidak melaporkan hal yang sama, dan memaksa
 * mereka memakai satu modul berarti salah satunya mengisi kolom yang tidak berarti
 * apa-apa baginya — yang paling cepat membuat orang berhenti mengisi dengan jujur.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/modul.php';

/** Batas jumlah modul per tim. Katalog yang panjang berhenti jadi katalog. */
const KATALOG_MAX = 12;

/**
 * Semua modul tim ini, sudah dirapikan.
 *
 * Dirapikan saat dibaca, bukan cuma saat disimpan: baris di database selalu bisa lebih
 * tua daripada kode yang membacanya.
 *
 * @return array<int, array{id:int, code:string, active:bool, sort:int, spec:array}>
 */
function katalog_list(int $teamId, bool $withInactive = false): array
{
    $st = q_opt('SELECT id, code, active, sort_order, spec FROM modules
                  WHERE team_id = ? ORDER BY sort_order, id', [$teamId]);
    if ($st === null) {
        return [];                      // tabelnya belum dibuat: pemasangan belum dimutakhirkan
    }
    $out = [];
    foreach ($st as $r) {
        if (!$withInactive && !$r['active']) {
            continue;
        }
        $out[] = [
            'id'     => (int) $r['id'],
            'code'   => (string) $r['code'],
            'active' => (bool) $r['active'],
            'sort'   => (int) $r['sort_order'],
            'spec'   => modul_normalize(json_decode((string) $r['spec'], true)),
        ];
    }
    return $out;
}

function katalog_get(int $teamId, int $id): ?array
{
    foreach (katalog_list($teamId, true) as $m) {
        if ($m['id'] === $id) {
            return $m;
        }
    }
    return null;
}

function katalog_by_code(int $teamId, string $code): ?array
{
    foreach (katalog_list($teamId, true) as $m) {
        if ($m['code'] === $code) {
            return $m;
        }
    }
    return null;
}

/**
 * Menyimpan modul. $id null berarti modul baru.
 *
 * Kuncinya tidak pernah berubah setelah dibuat. Jawaban yang sudah tersimpan menunjuk ke
 * kunci itu; menggantinya berarti laporan bulan lalu kehilangan modulnya — dan yang
 * terlihat bukan pesan kesalahan, cuma rekap yang tiba-tiba kosong.
 */
function katalog_save(array $user, int $teamId, ?int $id, $in): int
{
    if (!in_array($user['role'] ?? '', ['admin', 'owner'], true)) {
        throw new RepoError('Halaman ini untuk admin.');
    }
    $spec = modul_normalize($in);
    $now  = Clock::nowUtcSql();

    if ($id === null) {
        $ada = katalog_list($teamId, true);
        if (count($ada) >= KATALOG_MAX) {
            throw new RepoError('Sudah ada ' . KATALOG_MAX . ' modul di tim ini. '
                              . 'Nonaktifkan salah satu dulu.');
        }
        if (katalog_by_code($teamId, $spec['key'])) {
            throw new RepoError('Sudah ada modul dengan kode ' . $spec['key'] . ' di tim ini.');
        }
        $urut = 0;
        foreach ($ada as $m) {
            $urut = max($urut, $m['sort']);
        }
        q('INSERT INTO modules (team_id, code, spec, active, sort_order, created_at,
                                updated_at, updated_by)
           VALUES (?,?,?,1,?,?,?,?)',
          [$teamId, $spec['key'], json_encode($spec, JSON_UNESCAPED_UNICODE),
           $urut + 1, $now, $now, (int) $user['id']]);
        return (int) db()->lastInsertId();
    }

    $lama = katalog_get($teamId, $id);
    if (!$lama) {
        throw new RepoError('Modul itu tidak ada di tim ini.');
    }
    $spec['key'] = $lama['code'];       // kuncinya tetap, apa pun yang dikirim formulir
    q('UPDATE modules SET spec = ?, updated_at = ?, updated_by = ? WHERE id = ? AND team_id = ?',
      [json_encode($spec, JSON_UNESCAPED_UNICODE), $now, (int) $user['id'], $id, $teamId]);
    return $id;
}

/** Dinyalakan atau dimatikan. Yang dimatikan hilang dari katalog pemain, bukan dihapus. */
function katalog_set_active(array $user, int $teamId, int $id, bool $active): void
{
    if (!in_array($user['role'] ?? '', ['admin', 'owner'], true)) {
        throw new RepoError('Halaman ini untuk admin.');
    }
    if (!katalog_get($teamId, $id)) {
        throw new RepoError('Modul itu tidak ada di tim ini.');
    }
    q('UPDATE modules SET active = ?, updated_at = ?, updated_by = ? WHERE id = ? AND team_id = ?',
      [$active ? 1 : 0, Clock::nowUtcSql(), (int) $user['id'], $id, $teamId]);
}

/**
 * Memindahkan satu modul ke atas atau ke bawah.
 *
 * Urutan di katalog adalah urutan orang mengerjakannya tiap pagi, jadi ini bukan hiasan.
 */
function katalog_move(array $user, int $teamId, int $id, int $arah): void
{
    if (!in_array($user['role'] ?? '', ['admin', 'owner'], true)) {
        throw new RepoError('Halaman ini untuk admin.');
    }
    $all = katalog_list($teamId, true);
    $i = null;
    foreach ($all as $n => $m) {
        if ($m['id'] === $id) { $i = $n; break; }
    }
    if ($i === null) {
        throw new RepoError('Modul itu tidak ada di tim ini.');
    }
    $j = $i + ($arah < 0 ? -1 : 1);
    if ($j < 0 || $j >= count($all)) {
        return;                         // sudah di ujung; bukan kesalahan, cuma tidak ada efek
    }
    [$all[$i], $all[$j]] = [$all[$j], $all[$i]];
    // Ditulis ulang semuanya, bukan ditukar dua baris: urutan yang tersimpan bisa saja
    // sudah kembar atau berlubang karena penghapusan, dan tukar-dua-baris mengekalkannya.
    foreach ($all as $n => $m) {
        q('UPDATE modules SET sort_order = ? WHERE id = ? AND team_id = ?',
          [$n + 1, $m['id'], $teamId]);
    }
}

/**
 * Menghapus modul — hanya kalau belum pernah ada yang melaporkannya.
 *
 * Begitu ada satu laporan saja, modulnya jadi bagian dari riwayat: menghapusnya membuat
 * laporan lama tidak bisa dibaca lagi. Yang itu dinonaktifkan, bukan dihapus.
 */
function katalog_delete(array $user, int $teamId, int $id): void
{
    if (!in_array($user['role'] ?? '', ['admin', 'owner'], true)) {
        throw new RepoError('Halaman ini untuk admin.');
    }
    if (!katalog_get($teamId, $id)) {
        throw new RepoError('Modul itu tidak ada di tim ini.');
    }
    $dipakai = q_opt('SELECT id FROM cycles WHERE module_id = ? LIMIT 1', [$id]);
    if ($dipakai !== null && $dipakai->fetch()) {
        throw new RepoError('Modul ini sudah punya laporan, jadi tidak bisa dihapus. '
                          . 'Nonaktifkan saja — laporan lamanya tetap bisa dibaca.');
    }
    q('DELETE FROM modules WHERE id = ? AND team_id = ?', [$id, $teamId]);
}

/**
 * Tim yang belum punya satu modul pun mendapat MESSI, dibangun dari setelannya sendiri.
 *
 * Bukan modul contoh yang kosong: yang sudah memakai MESSI selama ini harus menemukan
 * MESSI-nya sendiri di sini, lengkap dengan channel, ambang dan kalimat yang sudah dia
 * ubah — kalau tidak, pemutakhiran ini terasa seperti kehilangan.
 */
function katalog_seed(int $teamId): ?int
{
    if (katalog_list($teamId, true)) {
        return null;
    }
    require_once __DIR__ . '/repo.php';
    $spec = modul_messi(repo_config($teamId));
    $now = Clock::nowUtcSql();
    $st = q_opt('INSERT INTO modules (team_id, code, spec, active, sort_order, created_at,
                                      updated_at)
                 VALUES (?,?,?,1,1,?,?)',
                [$teamId, $spec['key'], json_encode($spec, JSON_UNESCAPED_UNICODE), $now, $now]);
    return $st === null ? null : (int) db()->lastInsertId();
}
