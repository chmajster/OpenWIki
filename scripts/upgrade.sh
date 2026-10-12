#!/usr/bin/env bash
# Sourced by install.sh. Persistent checkpoints are committed by upgrade-tool.php.

upgrade_tool() { php "$SCRIPT_DIR/scripts/upgrade-tool.php" "$1" "$INSTALL_DIR" "${@:2}"; }
upgrade_get() { upgrade_tool get "$1"; }
upgrade_done() { [[ $'\n'"$(upgrade_get completed)"$'\n' == *$'\n'"$1"$'\n'* ]]; }
upgrade_complete() { upgrade_tool complete "$1"; }
upgrade_begin() { UPGRADE_STAGE="$1"; upgrade_tool set stage "$1"; upgrade_tool set status running; }

upgrade_failure() {
  local code=$? line=${1:-unknown}
  trap - ERR EXIT
  [[ $code != 0 ]] || return 0
  upgrade_tool set status failed || true
  upgrade_tool set error "Stage $UPGRADE_STAGE failed at installer line $line (exit $code). See private log; use --resume." || true
  warn "Aktualizacja nie powiodła się: $UPGRADE_STAGE. Diagnostyka: --upgrade-status; wznowienie: --resume."
  [[ ! -f "$INSTALL_DIR/.openwiki/maintenance" ]] || warn "Zapisy pozostają zablokowane. Nie cofaj kodu bez kontroli zgodności schematu."
  if [[ ${UPGRADE_MANAGED_HOST:-1} == 1 && -n ${PHP_FPM_SERVICE:-} && -f "$INSTALL_DIR/.openwiki/maintenance" ]]; then
    # OpenWiki remains blocked at Nginx; other users of the system pool can run.
    systemctl start "$PHP_FPM_SERVICE" || true
  fi
  exit "$code"
}

upgrade_switch() {
  local target="$1"
  [[ "$target" == "$INSTALL_DIR/.openwiki/releases/"* && -f "$target/bin/console" ]] || fail "Niepoprawny katalog wydania."
  rm -f "$INSTALL_DIR/.openwiki/current.next"
  ln -s "$target" "$INSTALL_DIR/.openwiki/current.next"
  mv -Tf "$INSTALL_DIR/.openwiki/current.next" "$INSTALL_DIR/current"
  APP_DIR="$INSTALL_DIR/current"
}

upgrade_pause() {
  touch "$INSTALL_DIR/.openwiki/maintenance"
  chmod 0644 "$INSTALL_DIR/.openwiki/maintenance"
  if [[ ${UPGRADE_MANAGED_HOST:-1} == 1 ]]; then
    # Older releases do not implement the write gate. Stop their PHP process and
    # pause only OpenWiki's cron entry before capturing the database/storage.
    [[ ! -f "$CRON_FILE" ]] || mv "$CRON_FILE" "$INSTALL_DIR/.openwiki/cron.paused"
    if systemctl is-active --quiet "$PHP_FPM_SERVICE"; then
      configure_nginx
      systemctl stop "$PHP_FPM_SERVICE"
    fi
    local attempt pid command busy worker_directory worker_binary
    for attempt in {1..60}; do
      busy=0
      while read -r pid command; do
        [[ "$command" == *php* && "$command" == *'bin/console'* ]] || continue
        worker_binary=$(readlink -f "/proc/$pid/exe" 2>/dev/null || true)
        [[ "${worker_binary##*/}" =~ ^php([0-9]+(\.[0-9]+)*)?$ ]] || continue
        worker_directory=$(readlink -f "/proc/$pid/cwd" 2>/dev/null || true)
        if [[ "$command" == *"$INSTALL_DIR"* || "$worker_directory" == "$INSTALL_DIR" || "$worker_directory" == "$INSTALL_DIR/.openwiki/releases/"* ]]; then busy=1; fi
      done < <(ps -eo pid=,args=)
      [[ $busy == 1 ]] || break
      sleep 1
    done
    [[ $busy == 0 ]] || fail "Worker nadal pracuje. Zatrzymaj go i użyj --resume."
  else
    # Unmanaged mode is only safe with code that already participates in the gate.
    [[ -f "$APP_DIR/app/Upgrade/WriteGate.php" ]] || fail "Starszy runtime wymaga zarządzanego hosta i zatrzymania PHP-FPM/workerów."
  fi
  exec 8<"$INSTALL_DIR/.openwiki/requests.lock"
  flock -w 60 -x 8 || fail "Nie zakończyły się aktywne żądania/worker. Wznów po ich zakończeniu."
  export OPENWIKI_UPGRADE_OWNER
  OPENWIKI_UPGRADE_OWNER=$(cat "$INSTALL_DIR/.openwiki/owner")
}

upgrade_runtime() {
  if [[ ${UPGRADE_MANAGED_HOST:-1} == 1 ]]; then
    chown -R "$WEB_USER:$WEB_USER" "$INSTALL_DIR/storage"
    secure_env_permissions
    # Start FPM to recreate its socket; the maintenance marker still denies traffic.
    systemctl start "$PHP_FPM_SERVICE"
    configure_selinux
    configure_nginx
    configure_cron
  fi
}

upgrade_launchers() {
  local directory="$INSTALL_DIR/.openwiki" updater="$INSTALL_DIR/.openwiki/updater"
  if [[ "$(readlink -f "$SCRIPT_DIR")" != "$updater" ]]; then
    mkdir -p "$updater"
    # Preserve the recovery tooling even when restored legacy code predates it.
    tar -C "$SCRIPT_DIR" --exclude='./.openwiki' --exclude='./storage' --exclude='./.env' --exclude='./.git' --exclude='./node_modules' --exclude='./vendor' --exclude='./current' --exclude='./test-results' --exclude='./.phpunit.cache' --exclude='./tests' --exclude='./.github' --exclude='./AGENTS.md' -cf - . | tar -C "$updater" -xf -
  fi
  upgrade_tool launchers "$APP_DIR"
  printf '#!/usr/bin/env bash\nexport OPENWIKI_INSTALL_DIR=%q\nexec bash %q "$@"\n' "$INSTALL_DIR" "$updater/install.sh" > "$INSTALL_DIR/install.sh.next"
  chmod 0755 "$INSTALL_DIR/install.sh.next"
  mv -f "$INSTALL_DIR/install.sh.next" "$INSTALL_DIR/install.sh"
}

upgrade_open_and_verify() {
  upgrade_tool ready "$APP_DIR"
  upgrade_runtime
  rm "$INSTALL_DIR/.openwiki/maintenance"
  flock -u 8
  unset OPENWIKI_UPGRADE_OWNER
  if [[ ${UPGRADE_MANAGED_HOST:-1} == 1 ]]; then
    if ! curl --fail --silent --show-error --max-time 15 http://127.0.0.1/health >/dev/null ||
       ! runuser -u "$WEB_USER" -- php "$APP_DIR/bin/console" cron:run; then
      upgrade_pause
      return 1
    fi
  else
    if ! php "$APP_DIR/bin/console" readiness || ! php "$APP_DIR/bin/console" cron:run; then
      upgrade_pause
      return 1
    fi
  fi
}

upgrade_mode() {
  local directory="$INSTALL_DIR/.openwiki" state sha previous stage log
  [[ -f "$INSTALL_DIR/.env" && -f "$INSTALL_DIR/storage/installed.lock" ]] || fail "Upgrade wymaga istniejącej instalacji. Użyj --install."
  for command in php git tar flock sha256sum composer; do command -v "$command" >/dev/null || fail "Brak narzędzia: $command"; done
  for state in "$directory" "$directory/releases" "$directory/backups" "$directory/logs" "$directory/requests.lock" "$directory/upgrade.lock" "$directory/owner" "$directory/status.json"; do
    [[ ! -L "$state" ]] || fail "Metadane aktualizacji nie mogą być dowiązaniem symbolicznym."
  done
  mkdir -p "$directory/releases" "$directory/backups" "$directory/logs"
  chmod 0755 "$directory" "$directory/releases"
  chmod 0700 "$directory/backups" "$directory/logs"
  touch "$directory/requests.lock"
  chmod 0644 "$directory/requests.lock"
  exec 9>"$directory/upgrade.lock"
  flock -n -x 9 || fail "Inna aktualizacja jest w toku."
  APP_DIR="$INSTALL_DIR"
  [[ ! -L "$INSTALL_DIR/current" ]] || APP_DIR=$(readlink -f "$INSTALL_DIR/current")
  UPGRADE_STAGE=preflight
  trap 'upgrade_failure $LINENO' EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
  if [[ "$MODE" == upgrade ]]; then
    if ! upgrade_tool init "$GIT_REF"; then
      trap - EXIT
      fail "Wznów poprzednią operację przez --resume."
    fi
    upgrade_tool preflight "$APP_DIR"
  elif [[ "$MODE" == resume ]]; then
    state=$(upgrade_get status)
    [[ "$state" == running || "$state" == failed ]] || fail "Brak aktualizacji do wznowienia (status: $state)."
    upgrade_tool preflight "$APP_DIR"
    if [[ "$(upgrade_get operation)" == restore || "$(upgrade_get operation)" == rollback ]]; then
      MODE=$(upgrade_get operation)
      RESTORE_DATABASE=$(upgrade_get restore_database)
    fi
  fi
  log="$directory/logs/$(upgrade_get id).log"
  # Only this private file receives detailed dependency/migration errors.
  touch "$log"; chmod 0600 "$log"
  exec > >(tee -a "$log") 2>&1
  [[ -f "$directory/owner" ]] || (umask 077; php -r 'echo bin2hex(random_bytes(32));' > "$directory/owner")

  if [[ "$MODE" == rollback || "$MODE" == restore ]]; then
    previous=$(upgrade_get previous)
    [[ -n "$previous" && -d "$previous" ]] || fail "Nie ma poprzedniego wydania do odzyskania."
    UPGRADE_STAGE="$MODE"
    if [[ "$MODE" == rollback ]]; then
      if ! upgrade_tool compatible "$previous"; then
        trap - EXIT
        fail "Poprzedni kod nie jest zgodny ze schematem. Użyj --restore z potwierdzeniem nazwy bazy."
      fi
    elif [[ "$RESTORE_DATABASE" != "$(upgrade_tool database-name "$APP_DIR")" ]]; then
      trap - EXIT
      fail "Nazwa bazy nie potwierdza restore. Nie zmieniono działającej instancji."
    fi
    upgrade_tool set operation "$MODE"
    upgrade_tool set restore_database "$RESTORE_DATABASE"
    upgrade_begin "$MODE"
    upgrade_pause
    if [[ "$MODE" == restore ]]; then
      upgrade_tool restore "$APP_DIR" "$RESTORE_DATABASE"
    fi
    upgrade_tool compatible "$previous"
    upgrade_switch "$previous"
    upgrade_launchers
    upgrade_open_and_verify
    if [[ "$MODE" == restore ]]; then upgrade_tool set status restored; else upgrade_tool set status rolled_back; fi
    upgrade_tool set operation upgrade
    ok "Odzyskiwanie zakończone; gotowość i worker sprawdzone."
    return 0
  fi

  upgrade_begin fetch
  sha=$(upgrade_get sha)
  if [[ -z "$sha" ]]; then
    [[ -d "$directory/source.git" ]] || git init --bare "$directory/source.git"
    git --git-dir="$directory/source.git" fetch --no-tags --depth=1 -- "$REPO_URL" "$(upgrade_get ref)"
    sha=$(git --git-dir="$directory/source.git" rev-parse --verify 'FETCH_HEAD^{commit}')
    [[ "$sha" =~ ^[a-f0-9]{40}$ ]] || fail "Nieprawidłowy SHA wydania."
    upgrade_tool set sha "$sha"
  fi
  if [[ "$MODE" == upgrade && ! -f "$directory/maintenance" && -f "$APP_DIR/.release-sha" && "$(cat "$APP_DIR/.release-sha")" == "$sha" ]]; then
    php "$APP_DIR/bin/console" readiness
    upgrade_tool unchanged
    ok "Wskazane wydanie już działa; bez zmian."
    return 0
  fi
  stage="$directory/releases/$sha"
  upgrade_begin pause
  upgrade_pause
  upgrade_complete paused

  upgrade_begin backup
  if ! upgrade_done backup; then upgrade_tool snapshot "$APP_DIR"; upgrade_complete backup; fi
  upgrade_tool verify "$APP_DIR"

  upgrade_begin prepare
  if ! upgrade_done prepared; then
    if [[ ! -f "$stage/.code-ready" ]]; then
      [[ "$stage" == "$directory/releases/$sha" && ! -L "$stage" ]] || fail "Niepoprawny katalog przygotowania."
      rm -rf -- "$stage"
      mkdir "$stage"
      git --git-dir="$directory/source.git" archive "$sha" | tar -x -C "$stage"
      touch "$stage/.code-ready"
    fi
    # Never install dependencies into live code or replace the instance configuration.
    [[ -f "$stage/bin/console" && -f "$stage/composer.lock" ]] || fail "Niekompletne wydanie."
    ln -sfn "$INSTALL_DIR/.env" "$stage/.env"
    # The tracked storage skeleton has no instance data.
    if [[ ! -L "$stage/storage" ]]; then
      [[ "$(readlink -f "$stage/storage")" == "$stage/storage" ]] || fail "Nieprawidłowy storage przygotowanego wydania."
      rm -rf -- "$stage/storage"
      ln -s "$INSTALL_DIR/storage" "$stage/storage"
    fi
    (cd "$stage" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --no-progress && composer check-platform-reqs --no-dev)
    bash "$stage/scripts/build-editor.sh"
    php "$stage/scripts/local-runtime.php" php-ready
    php "$stage/scripts/local-runtime.php" assets-ready
    upgrade_tool manifest "$stage"
    rm -rf -- "$stage/node_modules"
    printf '%s\n' "$sha" > "$stage/.release-sha"
    # Integrity receipt also covers vendor/assets. Shared storage and .env are excluded.
    (cd "$stage" && find . -type f ! -path './node_modules/*' ! -name '.release-files' -print0 | sort -z | xargs -0 sha256sum > .release-files)
    upgrade_complete prepared
  fi
  (cd "$stage" && sha256sum --check --status .release-files)

  upgrade_begin previous
  previous=$(upgrade_get previous)
  if [[ -z "$previous" ]]; then
    if [[ "$APP_DIR" == "$directory/releases/"* ]]; then previous="$APP_DIR";
    else
      previous="$directory/releases/previous-$(upgrade_get id)"
      mkdir -p "$previous"
      # Capture the actual deployed code, dependencies and assets, not a Git ref
      # that could differ from the installation. Secrets/storage remain shared.
      tar -C "$INSTALL_DIR" --exclude='./.openwiki' --exclude='./storage' --exclude='./.env' --exclude='./.git' --exclude='./node_modules' --exclude='./current' -cf - . | tar -C "$previous" -xf -
      ln -sfn "$INSTALL_DIR/.env" "$previous/.env"
      ln -sfn "$INSTALL_DIR/storage" "$previous/storage"
    fi
    upgrade_tool set previous "$previous"
  fi

  upgrade_begin migrate
  # migrate itself safely resumes incomplete DDL and DML. Never infer success
  # solely from a checkpoint or a child process having started.
  php "$stage/bin/console" migrate
  upgrade_tool ready "$stage"
  upgrade_complete migrated
  upgrade_begin switch
  if [[ ! -L "$INSTALL_DIR/current" || "$(readlink -f "$INSTALL_DIR/current")" != "$stage" ]]; then upgrade_switch "$stage"; fi
  APP_DIR="$INSTALL_DIR/current"
  upgrade_launchers
  upgrade_complete switched
  upgrade_begin readiness
  upgrade_open_and_verify
  upgrade_complete ready
  upgrade_tool set status completed
  upgrade_tool set result upgraded
  rm -f "$directory/cron.paused"
  ok "Aktualizacja do $sha zakończona; schemat, gotowość i worker sprawdzone."
}
