#!/bin/sh
# Builds the file you upload to cPanel: messi-cpanel.zip.
#
#   sh php/make-zip.sh
#
# Only what runs in production goes in. tests/ is left out on purpose — one of those
# files drops and recreates a database, which has no business sitting on a live server
# with only .htaccess standing between it and the open internet.
#
# The zip has no top folder, so extracting it inside public_html/messi puts the files
# straight there rather than in public_html/messi/messi.

set -e
cd "$(dirname "$0")"

OUT="$(cd .. && pwd)/messi-cpanel.zip"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

for f in index.php login.php lupa.php akun.php izin.php setup.php kelola.php admin.php soal.php modul.php upgrade.php \
         undang.php cek.php app.html install.sql \
         config.example.php .htaccess README.md PASANG.txt api lib cron; do
  cp -r "$f" "$STAGE/"
done

# Daftar di atas ditulis tangan, jadi halaman baru mudah terlupa — dan yang terlupa baru
# ketahuan setelah di-upload, sebagai 404 di tangan orang lain.
for f in *.php; do
  [ "$f" = "config.php" ] && continue
  if [ ! -e "$STAGE/$f" ]; then
    echo "BERHENTI: $f belum masuk daftar di make-zip.sh" >&2
    exit 1
  fi
done

# A config.php in the zip would ship a database password to whoever receives the file.
if [ -e "$STAGE/config.php" ]; then
  echo "BERHENTI: config.php ikut masuk paket. Jangan di-upload." >&2
  exit 1
fi

rm -f "$OUT"
(cd "$STAGE" && zip -r -q -X "$OUT" .)

echo "Paket siap: $OUT"
unzip -l "$OUT" | tail -1
