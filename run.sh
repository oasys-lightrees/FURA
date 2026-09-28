#!/usr/bin/env bash
# Starts PostgreSQL, applies the schema, seeds, and serves MESSI on :8000.
# No Docker required — Postgres runs from its own data directory under /var/tmp.
set -euo pipefail

PGBIN=${PGBIN:-/usr/lib/postgresql/16/bin}
PGROOT=${PGROOT:-/var/tmp/messi-pg}
export MESSI_DSN=${MESSI_DSN:-"postgresql://postgres@/messi?host=$PGROOT/sock"}
cd "$(dirname "$0")"

if [ ! -d "$PGROOT/data" ]; then
  echo "==> initialising postgres in $PGROOT"
  mkdir -p "$PGROOT/data" "$PGROOT/sock"
  chown -R postgres:postgres "$PGROOT"; chmod 755 "$PGROOT"; chmod 700 "$PGROOT/data"
  su postgres -c "PATH=$PGBIN:\$PATH initdb -D $PGROOT/data -U postgres --auth=trust" >/dev/null
fi

if ! su postgres -c "PATH=$PGBIN:\$PATH pg_ctl -D $PGROOT/data status" >/dev/null 2>&1; then
  echo "==> starting postgres"
  su postgres -c "PATH=$PGBIN:\$PATH pg_ctl -D $PGROOT/data -o \"-k $PGROOT/sock -h ''\" -l $PGROOT/data/log start -w" >/dev/null
fi

su postgres -c "PATH=$PGBIN:\$PATH psql -h $PGROOT/sock -U postgres -tAc \"SELECT 1 FROM pg_database WHERE datname='messi'\"" \
  | grep -q 1 || su postgres -c "PATH=$PGBIN:\$PATH createdb -h $PGROOT/sock -U postgres messi"

export PYTHONPATH="$PWD/core:$PWD/app"
python3 -m cli initdb >/dev/null && echo "==> schema ready"
python3 -m cli seed
python3 -m cli tick

echo "==> http://127.0.0.1:8000  (nicho@lightrees.com / messi)"
exec python3 -m uvicorn main:app --app-dir app --host 127.0.0.1 --port "${PORT:-8000}"
