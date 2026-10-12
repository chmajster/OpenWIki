#!/bin/sh
set -eu
umask 0077
for directory in /runtime/storage /runtime/storage/attachments /runtime/storage/backups /runtime/storage/cache /runtime/storage/logs /runtime/storage/temp /runtime/storage/sessions; do
  mkdir -p "$directory"
  [ -w "$directory" ] || { printf '[FAIL] Brak prawa zapisu: %s\n' "$directory" >&2; exit 1; }
done
exec docker-php-entrypoint "$@"
