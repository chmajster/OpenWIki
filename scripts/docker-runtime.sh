#!/usr/bin/env bash
# Sourced by install.sh after environment/action validation.

docker_compose() {
  docker compose --project-name "$COMPOSE_PROJECT_NAME" --project-directory "$SCRIPT_DIR" \
    --env-file "$DOCKER_STATE/compose.env" -f "$SCRIPT_DIR/docker/compose.yml" "$@"
}

docker_save_settings() {
  local temporary="$DOCKER_STATE/compose.env.tmp-$$"
  printf '%s\n' "OPENWIKI_STATE_ID=$OPENWIKI_STATE_ID" "COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME" \
    "OPENWIKI_HOST=$OPENWIKI_HOST" "OPENWIKI_PORT=$OPENWIKI_PORT" "OPENWIKI_UID=$OPENWIKI_UID" \
    "OPENWIKI_GID=$OPENWIKI_GID" "OPENWIKI_DB_PASSWORD=$OPENWIKI_DB_PASSWORD" \
    "OPENWIKI_DB_ROOT_PASSWORD=$OPENWIKI_DB_ROOT_PASSWORD" > "$temporary"
  chmod 600 "$temporary"
  mv -- "$temporary" "$DOCKER_STATE/compose.env"
}

docker_validate_address() {
  [[ "$OPENWIKI_PORT" =~ ^[0-9]{1,5}$ ]] && ((10#$OPENWIKI_PORT >= 1 && 10#$OPENWIKI_PORT <= 65535)) || fail "Port musi mieścić się w zakresie 1–65535."
  OPENWIKI_PORT=$((10#$OPENWIKI_PORT))
  [[ "$OPENWIKI_HOST" == localhost ]] && OPENWIKI_HOST=127.0.0.1
  local address="$OPENWIKI_HOST" segment count=0 octets=() segments=()
  if [[ "$address" == *.* ]]; then
    local ipv4="${address##*:}"
    [[ "$ipv4" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "Nieprawidłowy adres IPv4: $OPENWIKI_HOST."
    IFS=. read -r -a octets <<< "$ipv4"
    [[ ${#octets[@]} == 4 ]] || fail "Nieprawidłowy adres IPv4: $OPENWIKI_HOST."
    for segment in "${octets[@]}"; do
      [[ "$segment" =~ ^(0|[1-9][0-9]{0,2})$ ]] && ((10#$segment <= 255)) || fail "Nieprawidłowy adres IPv4: $OPENWIKI_HOST."
    done
    [[ "$address" == *:* ]] || return 0
    address="${address%:*}:0:0"
  fi
  [[ "$address" =~ ^[a-fA-F0-9:]+$ && "$address" == *:* && "$address" != *:::* ]] || fail "Host musi być poprawnym adresem IPv4, IPv6 albo localhost."
  [[ "$address" != :* || "$address" == ::* ]] || fail "Nieprawidłowy adres IPv6: $OPENWIKI_HOST."
  [[ "$address" != *: || "$address" == *:: ]] || fail "Nieprawidłowy adres IPv6: $OPENWIKI_HOST."
  IFS=: read -r -a segments <<< "$address"
  for segment in "${segments[@]}"; do
    [[ -n "$segment" ]] || continue
    [[ "$segment" =~ ^[a-fA-F0-9]{1,4}$ ]] || fail "Nieprawidłowy adres IPv6: $OPENWIKI_HOST."
    count=$((count + 1))
  done
  if [[ "$address" == *::* ]]; then
    [[ "${address#*::}" != *::* && "$count" -lt 8 ]] || fail "Nieprawidłowy adres IPv6: $OPENWIKI_HOST."
  else
    [[ "$address" != :* && "$address" != *: && "$count" == 8 ]] || fail "Nieprawidłowy adres IPv6: $OPENWIKI_HOST."
  fi
}

docker_summary() {
  info "Katalog projektu: $SCRIPT_DIR"
  info "Tryb: Docker; projekt Compose: $COMPOSE_PROJECT_NAME"
  info "Adres aplikacji: $OPENWIKI_URL"
  info "Stan aplikacji: $1"
  [[ "$1" != setup ]] || info "Dokończ konfigurację: $OPENWIKI_URL/install (baza jest przygotowana)."
  info "Logi: bash \"$SCRIPT_DIR/install.sh\" --docker --logs"
  info "Zatrzymanie: bash \"$SCRIPT_DIR/install.sh\" --docker --stop"
}

docker_check_port() {
  local id owner owned_binding
  while read -r id; do
    [[ -n "$id" ]] || continue
    owner=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "$id")
    [[ "$owner" == "$COMPOSE_PROJECT_NAME" ]] || fail "Port $OPENWIKI_PORT jest publikowany przez inny kontener. Wybierz --port; obcy kontener nie został zatrzymany."
  done < <(docker ps --filter "publish=$OPENWIKI_PORT" --format '{{.ID}}')
  # Native listeners, when ss is available. Docker performs the definitive bind again at startup.
  owned_binding=$(docker_compose port web 80 2>/dev/null || true)
  if command -v ss >/dev/null && [[ "$owned_binding" != *":$OPENWIKI_PORT" ]]; then
    if [[ -n $(ss -H -ltn "sport = :$OPENWIKI_PORT") ]]; then
      fail "Port $OPENWIKI_PORT jest zajęty. Wybierz --port; żaden obcy proces nie został zatrzymany."
    fi
  fi
}

docker_prepare() {
  # Docker otherwise creates nested volume mountpoints in the bind as root.
  # Existing host dependencies stay untouched and are hidden by the container volumes.
  [[ ! -L "$SCRIPT_DIR/vendor" && ! -L "$SCRIPT_DIR/node_modules" ]] || fail "Hostowe vendor/node_modules nie mogą być dowiązaniami symbolicznymi. Nie zmieniono ich zawartości."
  mkdir -p -- "$SCRIPT_DIR/vendor" "$SCRIPT_DIR/node_modules"
  info "[1/4] Przygotowanie obrazu PHP i prywatnych wolumenów zależności."
  if [[ "$RUNTIME_ACTION" == rebuild ]]; then
    docker_compose build --pull php
    docker_compose pull db web assets
  else
    docker_compose build php
  fi
  docker_compose run --rm --no-deps permissions
  docker_compose run --rm --no-deps php php -r 'require "/app/app/Install/LocalRuntime.php"; \OpenWiki\Install\LocalRuntime::validateAddress($argv[1], $argv[2], 1);' "$OPENWIKI_HOST" "$OPENWIKI_PORT"
  info "[2/4] Zależności PHP według composer.lock w wolumenie vendor."
  docker_compose run --rm --no-deps php sh -c 'composer validate --strict --no-check-publish && (php scripts/local-runtime.php php-ready || composer install --prefer-dist --no-interaction --no-dev) && composer check-platform-reqs --no-dev && php scripts/local-runtime.php php-ready'
  info "[3/4] Manifest i zasoby edytora."
  if [[ "$RUNTIME_ACTION" == rebuild ]] || ! docker_compose run --rm --no-deps php php scripts/local-runtime.php assets-ready; then
    docker_compose run --rm --no-deps assets
    docker_compose run --rm --no-deps php php scripts/local-runtime.php assets-ready || fail "Zasoby nie są zgodne z manifestem po buildzie."
  else
    ok "Zasoby aktualne; Node nie jest potrzebny do obsługi aplikacji."
  fi
  ok "Przygotowano środowisko bez zmiany Git i lokalnej konfiguracji."
}

docker_start() {
  info "[4/4] Uruchomienie PHP, WWW, bazy i workera."
  if ! docker_compose up -d --wait --wait-timeout 120 db php web worker; then
    local failed_state
    failed_state=$(docker_compose exec -T php php scripts/container-runtime.php state 2>/dev/null) || failed_state=process_error
    docker_summary "$failed_state"
    fail "Usługi nie wystartowały. Sprawdź port $OPENWIKI_PORT i --docker --logs. Obce procesy nie zostały zatrzymane; dane i wolumeny zachowano."
  fi
  local deadline=$((SECONDS + 60)) state=pending
  while ((SECONDS < deadline)); do
    state=$(docker_compose exec -T php php scripts/container-runtime.php state) || state=http_error
    [[ "$state" == ready || "$state" == setup ]] && break
    sleep 1
  done
  [[ "$state" == ready || "$state" == setup ]] || { docker_summary "$state"; fail "Aplikacja nie jest gotowa: $state. Sprawdź logi; konfiguracja i dane zachowane."; }
  ok "HTTP i baza dostępne; stan: $state."
  docker_summary "$state"
}

docker_mode() {
  command -v docker >/dev/null || fail "Brak Docker. Zainstaluj Docker Engine lub Docker Desktop z integracją WSL."
  docker compose version >/dev/null 2>&1 || fail "Brak Docker Compose v2 (docker compose)."
  docker info >/dev/null 2>&1 || fail "Docker nie jest dostępny. Uruchom Docker Engine/Desktop i sprawdź prawa dostępu."
  DOCKER_STATE="$SCRIPT_DIR/.openwiki/docker"
  local state_path
  for state_path in "$SCRIPT_DIR/.openwiki" "$DOCKER_STATE" "$DOCKER_STATE/compose.env" "$DOCKER_STATE/runtime" "$DOCKER_STATE/runtime/.env" "$DOCKER_STATE/runtime/storage"; do
    [[ ! -L "$state_path" ]] || fail "Stan Dockera nie może być dowiązaniem symbolicznym: $state_path. Nie zmieniono danych."
  done
  local fingerprint key value saved_fingerprint="" saved_host="" saved_port=""
  fingerprint=$(printf '%s' "$SCRIPT_DIR" | sha256sum); fingerprint=${fingerprint%% *}
  export OPENWIKI_STATE_ID="$fingerprint" COMPOSE_PROJECT_NAME="openwiki-${fingerprint:0:20}"
  export OPENWIKI_PROJECT_DIR="$SCRIPT_DIR" OPENWIKI_RUNTIME_DIR="$DOCKER_STATE/runtime"
  export OPENWIKI_UID="$(id -u)" OPENWIKI_GID="$(id -g)"
  export OPENWIKI_HOST="$LOCAL_HOST" OPENWIKI_PORT="$LOCAL_PORT"
  docker_validate_address
  unset OPENWIKI_DB_PASSWORD OPENWIKI_DB_ROOT_PASSWORD
  if [[ -f "$DOCKER_STATE/compose.env" ]]; then
    while IFS='=' read -r key value; do
      case "$key" in
        OPENWIKI_STATE_ID) saved_fingerprint="$value" ;;
        OPENWIKI_HOST) saved_host="$value" ;;
        OPENWIKI_PORT) saved_port="$value" ;;
        OPENWIKI_DB_PASSWORD|OPENWIKI_DB_ROOT_PASSWORD)
          [[ "$value" =~ ^[a-f0-9]{64}$ ]] || fail "Uszkodzona konfiguracja sekretów Dockera. Przywróć compose.env; nie generowano nowych haseł."
          export "$key=$value" ;;
      esac
    done < "$DOCKER_STATE/compose.env"
    [[ "$saved_fingerprint" == "$fingerprint" && -n "$saved_host" && -n "$saved_port" ]] || fail "Stan Dockera pochodzi z innego katalogu lub jest uszkodzony. Nie połączono starego znacznika instalacji z nową bazą."
    [[ "$LOCAL_HOST_SPECIFIED" == 1 ]] || OPENWIKI_HOST="$saved_host"
    [[ "$LOCAL_PORT_SPECIFIED" == 1 ]] || OPENWIKI_PORT="$saved_port"
    [[ -n "${OPENWIKI_DB_PASSWORD:-}" && -n "${OPENWIKI_DB_ROOT_PASSWORD:-}" ]] || fail "Niekompletne sekrety compose.env. Przywróć konfigurację istniejącej bazy."
  elif [[ "$RUNTIME_ACTION" == status || "$RUNTIME_ACTION" == stop ]]; then
    info "Tryb Docker nie został jeszcze przygotowany w $SCRIPT_DIR."; return 0
  elif [[ "$RUNTIME_ACTION" == logs ]]; then
    fail "Brak przygotowanej instancji Docker. Uruchom --docker."
  else
    [[ ! -d "$DOCKER_STATE/runtime" ]] || fail "Istnieją dane runtime bez compose.env. Przywróć konfigurację; nie utworzono nowej bazy ani haseł."
    ! docker volume inspect "${COMPOSE_PROJECT_NAME}_database" >/dev/null 2>&1 || fail "Istnieje wolumen bazy bez compose.env. Przywróć konfigurację; nie generowano nowych haseł."
    umask 077
    mkdir -p -- "$DOCKER_STATE"
    export OPENWIKI_DB_PASSWORD="$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')"
    export OPENWIKI_DB_ROOT_PASSWORD="$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')"
  fi
  docker_validate_address
  local browse_host="$OPENWIKI_HOST"
  [[ "$browse_host" != 0.0.0.0 ]] || browse_host=127.0.0.1
  [[ "$browse_host" != :: ]] || browse_host=::1
  [[ "$browse_host" != *:* ]] || browse_host="[$browse_host]"
  export OPENWIKI_URL="http://$browse_host:$OPENWIKI_PORT" OPENWIKI_COOKIE="OPENWIKI_DOCKER_${fingerprint:0:20}"
  if [[ "$RUNTIME_ACTION" == status || "$RUNTIME_ACTION" == stop || "$RUNTIME_ACTION" == logs ]]; then
    [[ "$OPENWIKI_HOST" == "$saved_host" && "$OPENWIKI_PORT" == "$saved_port" ]] || fail "Adres zmieniaj przy --docker, --start, --restart, --rebuild lub --no-start."
  fi
  if [[ "$RUNTIME_ACTION" == status ]]; then
    docker_compose ps --all
    local state=stopped worker=stopped
    if [[ -n $(docker_compose ps -q --status running php) ]]; then
      state=$(docker_compose exec -T php php scripts/container-runtime.php state) || state=http_error
      worker=$(docker_compose exec -T php php scripts/container-runtime.php worker-status) || worker=unavailable
      local diagnostics
      diagnostics=$(docker_compose exec -T php php scripts/container-runtime.php diagnostics) || diagnostics=unavailable
      info "Diagnostyka HTTP/bazy/aplikacji/workera: $diagnostics"
    fi
    docker_summary "$state"; info "Zadania okresowe: $worker"; return 0
  fi
  if [[ "$RUNTIME_ACTION" == logs ]]; then docker_compose logs --no-color --tail 100 db php web worker; return; fi
  command -v flock >/dev/null || fail "Brak flock (util-linux), potrzebnego do bezpiecznego zarządzania instancją."
  umask 077
  exec 9>"$DOCKER_STATE/operation.lock"
  flock -n 9 || fail "Inna operacja zarządza tą instancją. Poczekaj na jej zakończenie."
  if [[ "$RUNTIME_ACTION" == stop ]]; then
    docker_compose stop db php web worker
    ok "Instancja Docker zatrzymana; dane i wolumeny zachowane."; docker_summary stopped; return
  fi
  # Persist first-time credentials before any fallible port/build operation.
  # Existing settings are updated only after a successful port preflight.
  [[ -f "$DOCKER_STATE/compose.env" ]] || docker_save_settings
  docker_check_port
  if [[ ! -d "$OPENWIKI_RUNTIME_DIR" ]] && docker volume inspect "${COMPOSE_PROJECT_NAME}_database" >/dev/null 2>&1; then
    fail "Brak katalogu runtime dla istniejącego wolumenu bazy. Przywróć konfigurację, klucz i storage z kopii."
  fi
  mkdir -p -- "$OPENWIKI_RUNTIME_DIR/storage"
  docker_save_settings
  docker_compose config --quiet
  if [[ "$RUNTIME_ACTION" == prepare || "$RUNTIME_ACTION" == rebuild || "$RUNTIME_ACTION" == no-start ]]; then
    docker_compose stop php web worker
    docker_prepare
  elif ! docker image inspect "${COMPOSE_PROJECT_NAME}-php:local" >/dev/null 2>&1; then
    docker_prepare
  elif ! docker_compose run --rm --no-deps php sh -c 'php scripts/local-runtime.php php-ready && php scripts/local-runtime.php assets-ready'; then
    docker_compose stop php web worker
    docker_prepare
  fi
  if [[ "$RUNTIME_ACTION" == no-start ]]; then
    docker_compose stop db php web worker
    ok "Środowisko przygotowane; usługi pozostają zatrzymane."; docker_summary stopped; return
  fi
  if [[ "$RUNTIME_ACTION" == restart || "$RUNTIME_ACTION" == rebuild ]]; then
    docker_compose up -d --force-recreate db php web worker
  fi
  docker_start
}
