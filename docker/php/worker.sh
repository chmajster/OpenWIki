#!/bin/sh
set -eu
interval=${OPENWIKI_WORKER_INTERVAL:-60}
case "$interval" in ''|*[!0-9]*) echo '[FAIL] Nieprawidłowy interwał workera.' >&2; exit 1;; esac
[ "$interval" -ge 1 ] && [ "$interval" -le 3600 ] || exit 1
trap 'exit 0' TERM INT
while :; do
  state=$(php /app/scripts/container-runtime.php state)
  if [ "$state" = ready ]; then
    if flock -n /runtime/storage/worker.lock php /app/bin/console cron:run; then
      php /app/scripts/container-runtime.php heartbeat ready
    else
      php /app/scripts/container-runtime.php heartbeat failed
      echo '[WARN] Zadania okresowe nie zakończyły się pomyślnie.' >&2
    fi
  else
    php /app/scripts/container-runtime.php heartbeat "$state"
    echo "[INFO] Worker oczekuje na gotowość aplikacji: $state"
  fi
  sleep "$interval" &
  wait $! || true
done
