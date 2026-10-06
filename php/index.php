<?php
/**
 * The app.
 *
 * The page itself (app.html) is the same one the squad already tested, unchanged. What
 * this file adds is the seam it was written against: it expects a `claude.use("db")` and
 * a `claude.use("user")`, so those are provided here, backed by MySQL instead. Keeping
 * the page untouched means the 81 browser checks in web/tests still describe what ships.
 */

declare(strict_types=1);

// Before anything else, because the rest of the code needs PHP 8 to even be read.
require __DIR__ . '/lib/require-php8.php';

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/repo.php';

// Says what is missing on the first screen, not on the first click.
messi_require_ready();

$user = auth_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

// The first screen needs no round trip: everything it reads is already here — and
// nothing it does not. A player gets their own reports; the squad view is a leader's.
$mine = is_leader($user) ? null : (int) $user['id'];
// Leader membaca timnya sendiri; admin dan owner membaca semuanya. Ini bukan penghematan
// permintaan — nama dan laporan orang di tim lain bukan milik seorang leader untuk dibaca.
$team = is_manager($user) ? null : repo_team_of($user);
$myTeam = repo_team_of($user);
$cfg = repo_config($myTeam);
$teams = is_manager($user) ? repo_teams() : [$myTeam => (repo_teams()[$myTeam] ?? null)];
$boot = [
    'config' => messi_config_public($cfg),
    'teams'  => array_values(array_filter($teams)),
    'me' => [
        'id'       => uid((int) $user['id']),
        'name'     => $user['name'],
        'isLeader' => is_leader($user),
        'team'     => $myTeam,
    ],
    'cycles'      => repo_cycles(90, $mine, $team),
    'commitments' => repo_commitments(90, $mine, $team),
    'roster'      => repo_roster($team),
];

$app = (string) file_get_contents(__DIR__ . '/app.html');
// The title belongs in the head; the rest of the file is the page.
$app = preg_replace('~^\s*<title>(.*?)</title>~s', '', $app, 1, $found);
$title = 'FURA' . ($cfg['team_name'] === '' ? '' : ' · ' . $cfg['team_name']);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f5f3ef">
<title><?= h($title) ?></title>
<script>
/* The shim. Same shape as the platform API the page was built against, four calls wide:
   read three collections, write one document, and say who is asking. */
window.claude = (function () {
  const BOOT = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

  // Pertanyaan, ambang dan jam datang dari server. Halaman punya bawaannya sendiri dan
  // tetap jalan tanpa ini, supaya versi artifact-nya tidak ikut butuh database.
  window.FURA_CONFIG = BOOT.config;
  // Tim yang boleh dibaca orang ini, dan timnya sendiri. Leader cuma menerima satu;
  // owner dan admin menerima semuanya, dan halamannya menyediakan pemilihnya.
  window.FURA_TEAMS = BOOT.teams;
  window.FURA_TEAM = BOOT.me.team;

  const snapshot = obj => ({
    docs: Object.entries(obj || {}).map(([id, d]) => ({ id, data: () => d })),
  });

  async function save(collection, id, doc) {
    const r = await fetch("api/save.php", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-MESSI": "1" },
      body: JSON.stringify({ collection, id, doc }),
    });
    const out = await r.json().catch(() => ({}));
    if (!r.ok || !out.ok) {
      // 401 means the session went; sending people back to the door beats a silent
      // failure that looks like a saved report.
      if (r.status === 401) location.href = "login.php";
      throw new Error(out.error || "Gagal menyimpan.");
    }
    return out.result;
  }

  const db = {
    collection: name => ({
      get: async () => snapshot(BOOT[name]),
      doc: id => ({ set: doc => save(name, id, doc) }),
    }),
  };

  const user = {
    id: async () => BOOT.me.id,
    me: async () => ({ id: BOOT.me.id, name: BOOT.me.name }),
    isOwner: () => BOOT.me.isLeader,
    profiles: async ids => Object.fromEntries(
      ids.map(i => [i, { name: (BOOT.roster[i] || {}).name || "" }])),
  };

  return { use: async what => ({ db, user }[what] || Promise.reject(new Error(what))) };
})();
</script>
</head>
<body>
<?= $app ?>
<footer class="signout">
  Masuk sebagai <strong><?= h($user['name']) ?></strong> ·
  <?php if (is_manager($user)): ?><a href="admin.php">Orang &amp; tim</a> ·
    <a href="soal.php">Pertanyaan</a> · <?php endif; ?>
  <a href="api/logout.php">Keluar</a>
</footer>
<style>
.signout { max-width:44rem; margin:0 auto; padding:1.5rem 1.25rem 3rem;
           font:400 0.8125rem/1.5 "Public Sans", system-ui, sans-serif; color:#6b6d73; }
.signout a { color:inherit; }
</style>
</body>
</html>
