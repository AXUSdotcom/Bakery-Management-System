#!/bin/sh
set -e

echo "Waiting for database at ${DB_HOST:-127.0.0.1}:${DB_PORT:-3306}..."
i=0
until php -r '
  $h = getenv("DB_HOST") ?: "127.0.0.1";
  $p = getenv("DB_PORT") ?: 3306;
  $c = @fsockopen($h, (int) $p, $errno, $errstr, 2);
  exit($c ? 0 : 1);
'; do
  i=$((i + 1))
  if [ "$i" -ge 30 ]; then
    echo "Database never became reachable — starting anyway; migrate.php will retry on next boot."
    break
  fi
  sleep 2
done

php database/migrate.php || echo "Schema/seed step skipped (already applied, or DB not ready yet)."

exec "$@"
