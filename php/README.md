# MESSI di cPanel

Versi PHP + MySQL dari aplikasi di `web/`. Halamannya **sama persis** — `app.html`
adalah salinan `web/messi.html` tanpa satu baris pun diubah, jadi 81 pemeriksaan browser
di `web/tests/` tetap menggambarkan apa yang dipakai squad. Yang ditulis ulang cuma
bagian belakangnya: dulu data disimpan platform artifact, sekarang MySQL.

## Kenapa PHP

Karena cPanel sudah menyediakannya, dan tidak ada yang perlu di-install, di-build, atau
dijaga tetap hidup. Satu folder di-upload, satu database dibuat, satu cron dipasang.

## Pasang, sekali saja

1. **Buat database.** cPanel → *MySQL® Databases*. Buat database, buat user, kaitkan
   user ke database dengan *All Privileges*. Catat tiga nama yang muncul — semuanya
   berawalan nama akun cPanel, misalnya `lightree_messi`.

2. **Buat tabelnya.** cPanel → *phpMyAdmin* → pilih database → tab *Import* → pilih
   `install.sql` → *Go*. Harus muncul enam tabel.

3. **Upload.** cPanel → *File Manager* → masuk ke `public_html` → buat folder `messi` →
   upload seluruh isi folder `php/` ke situ.

4. **Isi konfigurasi.** Salin `config.example.php` jadi `config.php`, lalu isi nama
   database, user, password, dan `base_url` (alamat lengkap ke folder ini, tanpa garis
   miring di akhir). Untuk `cron_key`, pakai teks acak panjang.

   Lebih aman lagi: simpan `config.php` di luar `public_html`, lalu beri tahu aplikasi
   di mana file itu lewat variabel `MESSI_CONFIG_FILE`.

5. **Buat akun admin pertama.** Buka `https://alamat-kamu/messi/setup.php`, isi nama,
   email dan password. Halaman itu menolak jalan begitu sudah ada akun — tapi lebih
   rapi kalau langsung dihapus.

6. **Pasang cron.** cPanel → *Cron Jobs* → *Once Per Hour*:

   ```
   /usr/local/bin/php /home/AKUN/public_html/messi/cron/tick.php >/dev/null 2>&1
   ```

   Ganti `AKUN` dengan nama akun cPanel. Kalau hosting hanya menyediakan cron lewat URL,
   pakai `https://alamat-kamu/messi/cron/tick.php?key=CRON_KEY`.

7. **Tambah orang.** Masuk sebagai admin → menu *Squad* → *Tambah orang*. Tanggal
   *Mulai lapor* menentukan sejak kapan seseorang dihitung — orang baru tidak akan
   pernah ditandai bolos untuk hari sebelum dia bergabung.

## Telegram (boleh dilewati)

Tanpa Telegram aplikasinya tetap jalan; bedanya orang harus ingat sendiri untuk membuka
halamannya. Dengan Telegram, tiap pagi jam 09:00 bot mengirim link yang langsung masuk —
satu ketuk, tidak perlu password.

1. Chat `@BotFather` di Telegram → `/newbot` → salin tokennya ke `telegram_token` di
   `config.php`.
2. Daftarkan webhook-nya, sekali:
   `https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://alamat-kamu/messi/api/telegram.php?key=CRON_KEY`
3. Di halaman *Squad*, klik **Kode Telegram** untuk orang yang bersangkutan, lalu suruh
   dia kirim `/mulai KODE` ke bot. Kodenya berlaku 60 menit.

WhatsApp sengaja tidak ada. WhatsApp pribadi tidak punya API resmi, dan library yang
mengaku punya bekerja dengan cara yang melanggar ketentuan layanan — risikonya nomor
perusahaan sendiri yang diblokir. Lihat `docs/09-integrations.md`.

## Apa yang dikerjakan cron

Dijalankan tiap jam, dan aman kalau jalan dua kali atau terlewat satu jam:

| Jam | Yang terjadi |
|-----|--------------|
| tiap jam | hari yang lewat tanpa laporan ditandai *tidak lapor*; janji yang lewat tanggal ditandai *tidak ditepati*; token kedaluwarsa dibuang |
| 09:00 | laporan hari ini dibuka, bot mengirim link ke tiap orang |
| 17:00 | pengingat, hanya ke yang belum lapor |
| 18:00 | rekap hari itu ke leader |

Setiap pengiriman dicatat di tabel `job_log`, jadi "kenapa saya tidak dapat pesan?"
selalu ada jawabannya.

## Susunan file

```
index.php          halaman aplikasi + jembatan ke API
app.html           salinan persis web/messi.html
login.php          masuk: password, atau link sekali pakai dari bot
setup.php          akun admin pertama (hapus setelah dipakai)
admin.php          daftar squad: tambah orang, ganti password, kode Telegram
api/data.php       ambil semua data
api/save.php       simpan satu dokumen
api/logout.php     keluar
api/telegram.php   webhook bot
cron/tick.php      mesinnya
lib/engine.php     aturan: tanggal, hitungan, lampu, validasi, format laporan
lib/bootstrap.php  konfigurasi + koneksi database
lib/auth.php       siapa yang sedang bertanya
lib/repo.php       baca/tulis, dengan pemeriksaan yang tidak bisa dilewati browser
install.sql        enam tabel
tests/             123 pemeriksaan
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

Semua tanggal disimpan UTC; hari kalender Jakarta disimpan terpisah di `cycles.day`,
karena hari itulah — bukan detiknya — yang membuat sebuah laporan unik.

## Menjalankan tes

```sh
sh php/tests/run_all.sh                                   # yang tidak butuh apa-apa
MESSI_TEST_SOCKET=/var/run/mysqld/mysqld.sock \
  sh php/tests/run_all.sh                                 # semuanya
```

| Berkas | Isinya |
|--------|--------|
| `tests/test_engine.php` | 55 pemeriksaan aturan, dicocokkan dengan `tests/test_core.py` |
| `tests/test_repo.php` | 44 pemeriksaan terhadap MySQL sungguhan |
| `tests/test_live.py` | 24 pemeriksaan lewat browser terhadap aplikasi yang benar-benar jalan |
| `tests/check_sync.php` | memastikan `app.html` belum menyimpang dari `web/messi.html` |

Kalau `app.html` dan `web/messi.html` berbeda, samakan dengan
`cp web/messi.html php/app.html` — sumbernya tetap satu.

Aturan yang sama ada di tiga tempat (Python, JavaScript, PHP), dan itu dua tempat
terlalu banyak. Selama belum bisa disatukan, `tests/test_core.py` yang jadi acuan:
kalau ketiganya berbeda pendapat, yang Python benar.
