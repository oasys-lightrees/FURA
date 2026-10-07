# FURA di cPanel

Versi PHP + MySQL dari aplikasi di `web/`. Halamannya **sama persis** — `app.html`
adalah salinan `web/messi.html` tanpa satu baris pun diubah, jadi 245 pemeriksaan browser
di `web/tests/` tetap menggambarkan apa yang dipakai tim. Yang ditulis ulang cuma
bagian belakangnya: dulu data disimpan platform artifact, sekarang MySQL.

## Kenapa PHP

Karena cPanel sudah menyediakannya, dan tidak ada yang perlu di-install, di-build, atau
dijaga tetap hidup. Satu folder di-upload, satu database dibuat, satu cron dipasang.

## Paket siap-upload

```sh
sh php/make-zip.sh          # -> messi-cpanel.zip
```

Isinya hanya yang jalan di produksi; `tests/` sengaja tidak ikut, karena salah satunya
menghapus dan membuat ulang database. Zip-nya tidak punya folder induk, jadi di-extract
di dalam `public_html/messi` langsung jadi isinya, bukan `messi/messi`.

Langkah lengkap versi non-teknis ada di **`PASANG.txt`** di dalam paket.

## Butuh PHP 8

cPanel sering masih default ke PHP 7, dan kode ini butuh 8.0 ke atas. Kalau versinya
kurang, tiap halaman menampilkan penjelasan dan cara menggantinya
(*Software → Select PHP Version*) — bukan layar putih kosong.

## Pasang, sekali saja

0. **Pastikan PHP 8.** cPanel → *Select PHP Version* → 8.1 atau 8.2.

   Setelah upload, `cek.php` memeriksa sisanya sendiri: ekstensi, database, tabel,
   HTTPS, apakah `base_url` cocok dengan alamat yang sedang dibuka, apakah hosting
   boleh menghubungi Google Chat, dan kapan cron terakhir jalan. Buka lagi tiap selesai
   satu langkah sampai hijau semua.

1. **Buat database.** cPanel → *MySQL® Databases*. Buat database, buat user, kaitkan
   user ke database dengan *All Privileges*. Catat tiga nama yang muncul — semuanya
   berawalan nama akun cPanel, misalnya `lightree_messi`.

2. **Buat tabelnya.** cPanel → *phpMyAdmin* → pilih database → tab *Import* → pilih
   `install.sql` → *Go*. Harus muncul sembilan tabel.

3. **Upload.** cPanel → *File Manager* → masuk ke `public_html` → buat folder `messi` →
   upload seluruh isi folder `php/` ke situ.

4. **Isi konfigurasi.** Salin `config.example.php` jadi `config.php`, lalu isi nama
   database, user, password, dan `base_url` (alamat lengkap ke folder ini, tanpa garis
   miring di akhir). Untuk `cron_key`, pakai teks acak panjang.

   Lebih aman lagi: simpan `config.php` di luar `public_html`, lalu beri tahu aplikasi
   di mana file itu lewat variabel `MESSI_CONFIG_FILE`.

5. **Buat akun pemilik.** Buka `https://alamat-kamu/messi/setup.php`, isi nama, email
   dan password. Setelah jadi kamu langsung masuk dan mendarat di `kelola.php`, yang
   menuntun tiga langkah penyiapan — pertanyaan, Google Chat, undang orang — lalu
   berhenti menuntun begitu ketiganya beres. Halaman setup menolak jalan begitu sudah
   ada akun, tapi lebih rapi kalau langsung dihapus.

6. **Pasang cron.** cPanel → *Cron Jobs* → *Once Per Hour*:

   ```
   /usr/local/bin/php /home/AKUN/public_html/messi/cron/tick.php >/dev/null 2>&1
   ```

   Ganti `AKUN` dengan nama akun cPanel. Kalau hosting hanya menyediakan cron lewat URL,
   pakai `https://alamat-kamu/messi/cron/tick.php?key=CRON_KEY`.

7. **Undang orang.** Masuk sebagai admin → menu avatar di kanan atas → *Kelola* →
   *Orang & tim*. Satu orang lewat formulirnya, atau seluruh tim sekaligus lewat kotak
   tempelan (satu baris satu orang, `Nama <email>` atau alamat polos). Yang keluar link
   undangan, bukan password. Tanggal *Mulai lapor* menentukan sejak kapan seseorang
   dihitung — orang baru tidak akan pernah ditandai bolos untuk hari sebelum dia
   bergabung.

## Google Chat (boleh dilewati)

Tanpa ini aplikasinya tetap jalan; bedanya orang harus ingat sendiri membuka
halamannya. Dengan ini, space squad dapat tiga pesan tiap hari kerja.

1. Buka space tim → klik nama space → *Apps & integrations* → *Webhooks* →
   *Add webhooks* → beri nama → salin URL-nya.
2. Tempel di *Kelola → Pertanyaan → Space Google Chat tim ini* (atau ke `chat_webhook`
   di `config.php` kalau cuma ada satu tim), lalu klik **Simpan lalu kirim pesan tes**.
   Alamat yang salah tempel dikatakan gagal seketika — bukan besok jam buka, lewat
   keluhan "botnya mati".

Hanya dua pesan, dan keduanya cuma mengantar orang ke website. **Rekap tidak dikirim
ke chat.** Atasan membacanya di tab *Squad*, yang bisa membuka hari mana saja dan
membuka laporan utuh siapa pun — dua hal yang tidak bisa dilakukan sebuah pesan chat.

**Sebuah webhook memposting ke satu space, bukan japri.** Itu satu fakta yang
membentuk semuanya: pesan pagi membawa alamat biasa, bukan link sekali-pakai yang
langsung memasukkan — di space bersama, link seperti itu memasukkan *siapa pun yang
bisa membaca space itu*. Orang login dengan password, dan sesinya 30 hari, jadi
praktis sebulan sekali. Untuk yang terkunci, *Orang & tim* punya tombol *Link masuk*
yang menghasilkan link 60 menit sekali-pakai, untuk dikirim japri. Dan orangnya sendiri
bisa menitipkan pesan lewat *Lupa password?* di halaman masuk: permintaannya muncul di
atas halaman itu dengan namanya. Tidak ada email yang dikirim sistem ini — kalimat di
halaman itu mengatakannya terus terang, karena email yang dijanjikan lalu tidak datang
lebih buruk daripada tidak dijanjikan.

WhatsApp tetap tidak ada: WhatsApp pribadi tidak punya API resmi, dan library yang
mengaku punya berisiko nomor perusahaan sendiri diblokir. Lihat `docs/09-integrations.md`.

## Apa yang dikerjakan cron

Dijalankan tiap jam, dan aman kalau jalan dua kali atau terlewat satu jam:

| Jam | Yang terjadi |
|-----|--------------|
| tiap jam | hari yang lewat tanpa laporan ditandai *tidak lapor*; janji yang lewat tanggal ditandai *tidak ditepati*; token kedaluwarsa dibuang |
| 09:00 | laporan dibuka; satu pesan ke space, menyebut janji yang jatuh tempo hari ini |
| 17:00 | pengingat, menyebut siapa yang belum lapor — tidak dikirim kalau semua sudah |
| *seketika* | ada yang menulis permintaan bantuan — tidak menunggu cron |

Setiap pengiriman dicatat di tabel `job_log`, jadi "kenapa tidak ada pesan?" selalu
ada jawabannya. URL webhook-nya membawa kunci sendiri, jadi tidak pernah ikut tercatat.

## Susunan file

```
index.php          halaman aplikasi + jembatan ke API
app.html           salinan persis web/messi.html
login.php          masuk: password, atau link sekali pakai dari admin
lupa.php           "saya lupa" — menitipkan pesan, karena belum ada pengiriman email
setup.php          akun pemilik pertama (hapus setelah dipakai)
kelola.php         satu pintu untuk yang mengelola, plus tuntunan penyiapan
cek.php            apakah hosting ini sanggup, dan apa yang masih kurang
admin.php          orang & tim: undang satu atau sekaligus, peran, link masuk
api/data.php       ambil semua data
api/save.php       simpan satu dokumen
api/logout.php     keluar
cron/tick.php      titik masuk cron
lib/tick.php       mesinnya: buka hari, tandai yang bolos, patahkan janji, kirim pesan
lib/engine.php     aturan: tanggal, hitungan, lampu, validasi, format laporan
lib/bootstrap.php  konfigurasi + koneksi database
lib/auth.php       siapa yang sedang bertanya
lib/repo.php       baca/tulis, dengan pemeriksaan yang tidak bisa dilewati browser
lib/chat.php       Google Chat
lib/require-php8.php  penjaga versi PHP, dibaca paling awal
lib/layout.php     satu kerangka halaman: satu CSS, satu kepala, satu mode gelap
soal.php           halaman admin: pertanyaan, ambang, jam, channel — per tim
upgrade.php        menyusulkan database lama ke bentuk versi ini
undang.php         yang diundang membuat passwordnya sendiri
lib/schema.php     langkah pemutakhiran, tiap langkah memeriksa dirinya sendiri
install.sql        sembilan tabel
PASANG.txt         langkah pemasangan, bahasa non-teknis
make-zip.sh        bikin messi-cpanel.zip
tests/             530 pemeriksaan (tidak ikut ke server)
```

## Yang diputuskan server, bukan browser

Halaman mengirim dokumen utuh, dan tidak satu pun dipercaya begitu saja. Yang menilai
seseorang dihitung ulang di sini, dari jam servernya sendiri:

- **Hari.** Laporan hanya bisa diisi untuk hari ini, dan hanya untuk diri sendiri.
- **Telat.** Ditentukan jam server, bukan jam laptop pelapor.
- **Ditepati atau tidak.** Halaman boleh bilang "selesai"; yang menentukan kept atau
  broken adalah tanggal janji dibanding tanggal hari ini.
- **Angkanya masuk akal.** Yang gantung tidak mungkin lebih banyak dari yang aktif —
  diperiksa lagi di server, sebelum deklarasi ditanyakan.
- **Hari bolos.** Dihitung dari `joined_on` di database, bukan dari apa pun yang
  dikirim browser.
- **Siapa boleh melihat apa.** Leader menerima laporan timnya sendiri dan tidak pernah
  menerima laporan tim lain — dibatasi di query, bukan disembunyikan di halaman, karena
  yang sudah sampai di browser tidak bisa ditarik kembali. Owner dan admin menerima
  semuanya, dan memilih tim mana yang dibaca.
- **Owner dan admin.** Owner mengangkat admin; admin tidak bisa menyentuh owner maupun
  sesama admin. Kalau bisa, satu admin tinggal menurunkan yang lain dan "admin" berhenti
  berarti apa pun.
- **Password tidak pernah diketik admin.** Menambah orang menghasilkan link undangan
  sekali pakai; orangnya yang membuat passwordnya. Yang lupa password dapat link yang
  sama, bukan password baru dari atasannya.
- **Pertanyaannya sendiri.** Channel, ambang gantung, jam buka/tutup, kalimat tiap
  pertanyaan dan pernyataannya disimpan sebagai satu dokumen JSON di tabel `settings`,
  diubah admin lewat `soal.php`, lalu dipakai halaman maupun server. Yang tidak masuk
  akal dirapikan saat disimpan *dan* saat dibaca — baris di database bisa lebih tua
  daripada kode yang membacanya, dan satu ambang yang hilang tidak boleh berarti
  halaman kosong.
- **Satu janji per rencana.** Laporan boleh berisi sampai lima rencana, masing-masing
  dengan tanggalnya sendiri, dan server membuat satu janji untuk tiap baris. Saat laporan
  diperbaiki, barisnya dicocokkan **lewat teks aksinya**, bukan lewat urutan — kalau lewat
  urutan, menghapus baris pertama akan menggeser semuanya dan tanggal janji orang
  tertukar. Baris yang dibuang dibatalkan (`cancelled`), tidak dihapus; janji yang sudah
  ditutup tidak pernah disentuh lagi.

Semua tanggal disimpan UTC; hari kalender Jakarta disimpan terpisah di `cycles.day`,
karena hari itulah — bukan detiknya — yang membuat sebuah laporan unik.

## Menjalankan tes

```sh
sh php/tests/run_all.sh                                   # yang tidak butuh apa-apa
MESSI_TEST_SOCKET=/var/run/mysqld/mysqld.sock \
  sh php/tests/run_all.sh                                 # semuanya
```

Tanpa database, empat berkas yang membutuhkannya menulis DILEWATI dan run-nya tetap
sukses. Begitu databasenya ditunjuk, mereka wajib jalan: database yang disebut tapi
tidak bisa dihubungi dihitung **gagal**, bukan dilewati — kalau tidak, suite yang tidak
pernah jalan terbaca persis seperti suite yang lolos. Baris terakhir run-nya
menyebutkan mana yang barusan terjadi.

| Berkas | Isinya |
|--------|--------|
| `tests/test_engine.php` | 99 — aturan, dicocokkan dengan `tests/test_core.py` |
| `tests/test_repo.php` | 111 — terhadap MySQL sungguhan, di database yang dia buat sendiri |
| `tests/test_cron.php` | 66 — seminggu penuh jam demi jam, jam dibekukan, pesan bot ditangkap |
| `tests/test_live.py` | 64 — browser terhadap aplikasi yang benar-benar jalan |
| `tests/test_admin.py` | 124 — pemasangan pertama, undangan satu dan borongan, lupa password, link sekali pakai, mengubah pertanyaan, uji kirim webhook |
| `tests/test_schema.php` | 42 — pemasangan lama di-upgrade, lalu dibandingkan kolom demi kolom dengan yang baru |
| `tests/test_upgrade_path.py` | 24 — aplikasi sungguhan di atas database versi lama: masih bisa masuk, setiap halaman lain menyebut apa yang kurang, lalu satu tombol menghidupkannya |
| `tests/check_sync.php` | memastikan `app.html` belum menyimpang dari `web/messi.html` |

Yang dicoba juga: laporan atas nama orang lain, permintaan tanpa token, cron tanpa kunci,
email berisi SQL, nama berisi `<script>`, cookie yang dipakai lagi setelah keluar, dan
pemain yang mencoba menaikkan perannya sendiri. Semuanya ditolak.

Satu hal yang tidak bisa diuji di sini: aturan `.htaccess`. `php -S` tidak membacanya,
jadi yang terbukti adalah PHP-nya dijalankan, bukan dikirim sebagai teks — lapisan
`.htaccess` baru bisa dipastikan di Apache yang sesungguhnya.

Kalau `app.html` dan `web/messi.html` berbeda, samakan dengan
`cp web/messi.html php/app.html` — sumbernya tetap satu.

Aturan yang sama ada di tiga tempat (Python, JavaScript, PHP), dan itu dua tempat
terlalu banyak. Selama belum bisa disatukan, `tests/test_core.py` yang jadi acuan:
kalau ketiganya berbeda pendapat, yang Python benar.
