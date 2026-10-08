<?php
/**
 * Satu kerangka untuk semua halaman.
 *
 * Dulu tiap halaman membawa blok <style> sendiri: masuk, undangan, orang & tim,
 * pertanyaan, cek hosting. Akibatnya dua hal. Yang terlihat orang: pindah dari aplikasinya
 * ke halaman pengelolaan terasa pindah aplikasi, karena warna, lebar, dan jarak hurufnya
 * memang berbeda. Yang terasa di sini: satu perbaikan tampilan harus dikerjakan enam kali,
 * dan yang keenam selalu ketinggalan.
 *
 * Warnanya sama persis dengan yang dipakai aplikasinya, dan pilihan terang/gelap dibaca
 * dari kunci localStorage yang sama — jadi orang yang memilih gelap di layar laporan tidak
 * tiba-tiba ditembak putih saat membuka pengelolaan.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Kunci localStorage yang sama dengan yang dipakai app.html. Satu pilihan, bukan dua. */
const MESSI_THEME_KEY = 'messi.theme';

/**
 * Kepala halaman.
 *
 * $opt:
 *   me     array|null  — pengguna yang sedang masuk; kalau ada, avatar dan menunya tampil
 *   center bool        — satu kartu di tengah layar (masuk, undangan, buat password)
 *   wide   bool        — kolom lebar untuk tabel; bawaannya sedang
 *   css    string      — aturan khusus halaman itu, ditempelkan di akhir lembar gaya
 */
function page_head(string $title, array $opt = []): void
{
    $me     = $opt['me'] ?? null;
    $center = (bool) ($opt['center'] ?? false);
    $wide   = (bool) ($opt['wide'] ?? false);
    $css    = (string) ($opt['css'] ?? '');

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $shell = $wide ? '60rem' : '46rem';
    ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · FURA</title>
<meta name="color-scheme" content="light dark">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">
<script>
/* Sebelum satu piksel pun digambar. Kalau dijalankan setelahnya, halaman gelap berkedip
   putih dulu tiap kali dibuka — dan kedipan itu yang paling terasa di pagi hari. */
try { var t = localStorage.getItem(<?= json_encode(MESSI_THEME_KEY) ?>);
      if (t) document.documentElement.setAttribute("data-theme", t); } catch (e) {}
</script>
<style>
:root {
  color-scheme: light;
  --shell:<?= $shell ?>;
  --paper:#f5f3ef; --surface:#ffffff; --raise:#faf9f6;
  --ink:#1a1c1f; --muted:#6b6d73; --line:#e4e1db;
  --kept:#2f6248; --due:#9a6410; --broken:#97322a;
  --kept-bg:#e8f0eb; --due-bg:#f7eedd; --broken-bg:#f7e7e4;
  --ui:"Public Sans",ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;
  --mono:"IBM Plex Mono",ui-monospace,SFMono-Regular,Menlo,monospace;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) { color-scheme: dark;
    --paper:#111317; --surface:#1c1f24; --raise:#22262c;
    --ink:#e8e6e1; --muted:#979aa1; --line:#2b2f36;
    --kept:#63a184; --due:#cf9a45; --broken:#cf6f61;
    --kept-bg:#1b2a23; --due-bg:#2b2318; --broken-bg:#2c1d1b; }
}
:root[data-theme="dark"] { color-scheme: dark;
  --paper:#111317; --surface:#1c1f24; --raise:#22262c;
  --ink:#e8e6e1; --muted:#979aa1; --line:#2b2f36;
  --kept:#63a184; --due:#cf9a45; --broken:#cf6f61;
  --kept-bg:#1b2a23; --due-bg:#2b2318; --broken-bg:#2c1d1b; }

* { box-sizing:border-box; }
body { margin:0; background:var(--paper); color:var(--ink); font-family:var(--ui);
       font-size:0.9375rem; line-height:1.55; -webkit-text-size-adjust:100%;
       -webkit-font-smoothing:antialiased; }

/* kepala — bentuk yang sama dengan yang di aplikasinya */
.top { border-bottom:1px solid var(--line);
       background:color-mix(in srgb,var(--paper) 94%,transparent); }
.top-in { max-width:var(--shell); margin:0 auto; padding:0.6875rem 1rem; display:flex;
          align-items:center; gap:0.625rem; }
.logo { text-decoration:none; color:inherit; }
.logo .brand { display:block; font-weight:700; letter-spacing:0.14em; font-size:0.8125rem; }
.logo .tagline { display:block; font-size:0.59375rem; letter-spacing:0.07em;
                 color:var(--muted); text-transform:uppercase; margin-top:1px;
                 white-space:nowrap; }
.spacer { flex:1 }
.theme { background:none; border:0; color:var(--muted); font-size:0.9375rem; padding:0.25rem;
         cursor:pointer; line-height:1; }
.theme:hover { color:var(--ink) }

/* menu: <details> supaya papan tombol dan pembaca layar dapat perilakunya gratis */
.menu { position:relative }
.menu summary { list-style:none; cursor:pointer; width:1.875rem; height:1.875rem;
                border-radius:50%; background:var(--ink); color:var(--paper); display:grid;
                place-items:center; font-size:0.75rem; font-weight:700; }
.menu summary::-webkit-details-marker { display:none }
.menu .sheet { position:absolute; right:0; top:2.375rem; z-index:40; min-width:13rem;
               background:var(--surface); border:1px solid var(--line);
               border-radius:0.625rem; padding:0.375rem;
               box-shadow:0 0.5rem 1.5rem rgba(0,0,0,0.12); }
.menu .sheet b { display:block; padding:0.5rem 0.625rem 0.125rem; font-size:0.8125rem }
.menu .sheet small { display:block; padding:0 0.625rem 0.5rem; color:var(--muted);
                     font-size:0.75rem; word-break:break-all; }
.menu .sheet hr { border:0; border-top:1px solid var(--line); margin:0.375rem 0 }
.menu .sheet a { display:block; padding:0.4375rem 0.625rem; border-radius:0.375rem;
                 color:inherit; text-decoration:none; font-size:0.875rem; }
.menu .sheet a:hover { background:var(--raise) }

/* badan */
main { max-width:var(--shell); margin:0 auto; padding:1.75rem 1rem 4rem; }
h1 { font-size:1.375rem; margin:0 0 0.25rem; letter-spacing:-0.01em; }
p.sub { margin:0 0 1.75rem; color:var(--muted); font-size:0.875rem; }
h2 { font-size:1rem; margin:2rem 0 0.5rem; }
p.why { margin:0 0 0.75rem; color:var(--muted); font-size:0.8125rem; max-width:42rem; }
a { color:var(--ink) }
.card { background:var(--surface); border:1px solid var(--line); border-radius:0.75rem;
        padding:1.25rem; }
/* Warnanya menempel pada .note, bukan pada .ok sendirian: "ok" dan "bad" juga dipakai
   sebagai penanda baris di halaman cek, dan aturan yang terlalu longgar akan mewarnai
   seluruh baris itu hijau. */
.note { padding:0.625rem 0.75rem; border-radius:0.5rem; margin:0 0 1rem; font-size:0.875rem; }
.note.ok   { background:var(--kept-bg);   color:var(--kept) }
.note.warn { background:var(--due-bg);    color:var(--due) }
.note.bad  { background:var(--broken-bg); color:var(--broken) }
.note a { color:inherit }
.tag { font-size:0.75rem; padding:0.125rem 0.4375rem; border-radius:0.25rem;
       background:var(--raise); border:1px solid var(--line); color:var(--muted); }
/* break-word, bukan break-all: yang terakhir memenggal "config.php" jadi "c onfig.php"
   di tengah kalimat. Yang benar-benar perlu dipenggal di mana saja cuma link panjang,
   dan itu diminta sendiri di tempatnya. */
code { font:500 0.9375rem var(--mono); background:var(--raise); border:1px solid var(--line);
       padding:0.0625rem 0.3125rem; border-radius:0.25rem; overflow-wrap:break-word; }

/* isian */
label { display:block; font-size:0.75rem; font-weight:500; margin:0 0 0.25rem;
        color:var(--muted); }
input, select, textarea { padding:0.4375rem 0.5rem; font:inherit; font-size:0.875rem;
       color:var(--ink); background:var(--raise); border:1px solid var(--line);
       border-radius:0.375rem; max-width:100%; }
input[type=text], input[type=email], input[type=password], textarea { width:100% }
textarea { min-height:3.25rem; resize:vertical }
input:focus, select:focus, textarea:focus { outline:2px solid var(--ink); outline-offset:1px }
button { padding:0.5rem 0.875rem; font:inherit; font-size:0.875rem; font-weight:500;
         cursor:pointer; background:var(--ink); color:var(--paper); border:0;
         border-radius:0.375rem; }
button.quiet { background:var(--raise); color:var(--ink); border:1px solid var(--line) }
button[disabled] { opacity:0.45; cursor:not-allowed }
.grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(11rem, 1fr)); gap:0.75rem; }
.grid input, .grid select { width:100% }
form.row { display:inline-flex; gap:0.375rem; align-items:center; margin:0 0.25rem 0.25rem 0 }

/* tabel */
table { width:100%; border-collapse:collapse }
th { text-align:left; font-size:0.75rem; font-weight:600; color:var(--muted);
     text-transform:uppercase; letter-spacing:0.04em; padding:0 0.5rem 0.5rem 0; }
td { padding:0.625rem 0.5rem 0.625rem 0; border-top:1px solid var(--line);
     vertical-align:middle; }
.scroll { overflow-x:auto }

<?php if ($center): ?>
body { min-height:100vh; display:grid; place-items:center; padding:1.5rem }
main { padding:0; width:min(24rem,100%) }
/* Satu kartu, satu tindakan: tombolnya selebar kartunya. */
.card button[type=submit] { width:100% }
<?php endif; ?>
<?= $css ?>
</style>
</head>
<body>
<?php if (!$center): ?>
<div class="top"><div class="top-in">
  <a class="logo" href="index.php">
    <span class="brand">FURA</span>
    <span class="tagline">Follow Up Report Automation</span>
  </a>
  <span class="spacer"></span>
  <button class="theme" type="button" id="themeBtn" aria-label="Ganti terang/gelap">☾</button>
  <?php if ($me): page_menu($me); endif; ?>
</div></div>
<?php endif; ?>
<main>
<?php
}

/**
 * Menu di bawah avatar.
 *
 * Dulu pintu ke halaman pengelolaan ada di footer — tempat orang mencari hal yang paling
 * tidak penting. Yang paling dibutuhkan owner di hari pertama justru yang paling
 * tersembunyi.
 */
function page_menu(array $me): void
{
    require_once __DIR__ . '/auth.php';
    $first = mb_strtoupper(mb_substr(trim((string) $me['name']), 0, 1)) ?: '?';
    ?>
  <details class="menu" id="userMenu">
    <summary title="<?= h((string) $me['name']) ?>"><?= h($first) ?></summary>
    <div class="sheet">
      <b><?= h((string) $me['name']) ?></b>
      <small><?= h((string) $me['email']) ?></small>
      <hr>
      <a href="index.php">Laporan</a>
      <?php if (is_leader($me)): ?>
        <a href="izin.php">Izin</a>
      <?php endif; ?>
      <?php if (is_manager($me)): ?>
        <a href="kelola.php">Kelola</a>
      <?php endif; ?>
      <a href="akun.php">Akun</a>
      <hr>
      <a href="api/logout.php">Keluar</a>
    </div>
  </details>
<?php
}

/** Kaki halaman: penutup tag, tombol tema, dan penutup menu saat diklik di luar. */
function page_foot(): void
{
    ?>
</main>
<script>
(function () {
  var KEY = <?= json_encode(MESSI_THEME_KEY) ?>;
  var root = document.documentElement, btn = document.getElementById("themeBtn");
  function read() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }
  function paint() {
    var t = read();
    var dark = t ? t === "dark"
                 : window.matchMedia("(prefers-color-scheme: dark)").matches;
    if (btn) { btn.textContent = dark ? "☀" : "☾";
               btn.title = dark ? "Pakai tampilan terang" : "Pakai tampilan gelap"; }
  }
  if (btn) btn.addEventListener("click", function () {
    var t = read();
    var dark = t ? t === "dark"
                 : window.matchMedia("(prefers-color-scheme: dark)").matches;
    var next = dark ? "light" : "dark";
    root.setAttribute("data-theme", next);
    try { localStorage.setItem(KEY, next); } catch (e) {}   // mode privat: tetap berganti
    paint();
  });
  paint();

  // Menu yang cuma bisa ditutup dengan tombolnya sendiri terasa macet.
  var menu = document.getElementById("userMenu");
  if (menu) {
    document.addEventListener("click", function (e) {
      if (menu.open && !menu.contains(e.target)) menu.open = false;
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && menu.open) menu.open = false;
    });
  }
})();
</script>
</body>
</html>
<?php
}
