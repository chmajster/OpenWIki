#!/usr/bin/env bash
# Sourced only by install.sh --local, after validating mutually exclusive modes.

local_dependency_hint() {
  local system_id="" system_like=""
  if [[ -r /etc/os-release ]]; then
    system_id=$( . /etc/os-release; printf '%s' "${ID:-}" )
    system_like=$( . /etc/os-release; printf '%s' "${ID_LIKE:-}" )
  fi
  case "$system_id $system_like" in
    *ubuntu*|*debian*) warn "Przygotowanie PHP/Composer: sudo apt-get install php-cli php-mysql php-mbstring php-xml php-curl php-ldap php-zip php-gd composer curl unzip" ;;
    *fedora*|*rhel*|*rocky*|*almalinux*|*centos*) warn "Przygotowanie PHP/Composer: sudo dnf install php-cli php-mysqlnd php-mbstring php-xml php-curl php-ldap php-zip php-gd composer curl unzip" ;;
    *arch*) warn "Przygotowanie PHP/Composer: sudo pacman -S php php-gd composer curl unzip; w php.ini włącz pdo_mysql, dom, curl, ldap, zip, gd i mbstring." ;;
    *)
      if [[ $(uname -s) == Darwin ]]; then
        warn "Przygotowanie PHP/Composer: brew install php composer; sprawdź php --ini i wymagane rozszerzenia."
      else
        warn "Zainstaluj PHP 8.2+ i Composer (https://getcomposer.org/download/); rozszerzenia sprawdzisz przez php --ini oraz php -m. W Windows użyj WSL lub PHP CLI dostępnego w PATH."
      fi ;;
  esac
  warn "Budowanie edytora wymaga Node.js 22+ i npm. Na Linuxie istniejący 'bash scripts/build-editor.sh' może przygotować zasoby przypiętym narzędziem bez zmiany globalnej instalacji Node.js."
}

local_stop_server() {
  if [[ -n "${LOCAL_PREP_PID:-}" ]]; then
    local target="$LOCAL_PREP_PID" step
    [[ "${LOCAL_PREP_GROUP:-0}" != 1 ]] || target="-$LOCAL_PREP_PID"
    kill -TERM -- "$target" 2>/dev/null || true
    for step in {1..30}; do
      kill -0 -- "$target" 2>/dev/null || break
      sleep 0.1
    done
    kill -0 -- "$target" 2>/dev/null && kill -KILL -- "$target" 2>/dev/null || true
    wait "$LOCAL_PREP_PID" 2>/dev/null || true
    LOCAL_PREP_PID=""
  fi
  if [[ -n "${LOCAL_SERVER_PID:-}" ]]; then
    kill -TERM "$LOCAL_SERVER_PID" 2>/dev/null || true
    local attempt
    for attempt in {1..30}; do
      kill -0 "$LOCAL_SERVER_PID" 2>/dev/null || break
      sleep 0.1
    done
    kill -0 "$LOCAL_SERVER_PID" 2>/dev/null && kill -KILL "$LOCAL_SERVER_PID" 2>/dev/null || true
    wait "$LOCAL_SERVER_PID" 2>/dev/null || true
    LOCAL_SERVER_PID=""
  fi
}

local_run_preparation() {
  local status=0
  LOCAL_PREP_GROUP=0
  if command -v setsid >/dev/null; then
    setsid "$@" &
    LOCAL_PREP_GROUP=1
  else
    "$@" &
  fi
  LOCAL_PREP_PID=$!
  if wait "$LOCAL_PREP_PID"; then status=0; else status=$?; fi
  LOCAL_PREP_PID=""
  return "$status"
}

local_mode() {
  LOCAL_SERVER_PID=""
  LOCAL_PREP_PID=""
  trap 'fail "Uruchomienie lokalne przerwane w linii $LINENO. Sprawdź komunikat powyżej."' ERR
  trap local_stop_server EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM

  command -v php >/dev/null || { local_dependency_hint; fail "Brak PHP CLI w PATH."; }
  php -r 'exit(PHP_SAPI === "cli" && version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' || {
    local_dependency_hint; fail "OpenWiki wymaga PHP CLI 8.2+.";
  }
  [[ -f "$SCRIPT_DIR/composer.lock" && -f "$SCRIPT_DIR/public/index.php" ]] || fail "Niekompletny projekt w $SCRIPT_DIR. Pobierz pełne repozytorium lub wydanie."
  php "$SCRIPT_DIR/scripts/local-runtime.php" address "$LOCAL_HOST" "$LOCAL_PORT"
  LOCAL_PORT=$((10#$LOCAL_PORT))
  # Before dependency installation or storage creation, and again implicitly at PHP startup.
  php "$SCRIPT_DIR/scripts/local-runtime.php" port "$LOCAL_HOST" "$LOCAL_PORT"

  local missing
  missing=$(php -r '
    $config = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $extensions = ["pdo_mysql", "session", "fileinfo"];
    foreach ($config["require"] as $name => $version) if (str_starts_with($name, "ext-")) $extensions[] = substr($name, 4);
    echo implode(" ", array_filter(array_unique($extensions), static fn($name) => !extension_loaded($name)));
  ' "$SCRIPT_DIR/composer.json")
  [[ -z "$missing" ]] || { local_dependency_hint; fail "Brak wymaganych rozszerzeń PHP: $missing. Sprawdź php --ini oraz php -m."; }
  php "$SCRIPT_DIR/scripts/local-runtime.php" storage
  cd -- "$SCRIPT_DIR"

  command -v composer >/dev/null || { local_dependency_hint; fail "Brak Composer w PATH."; }
  local_run_preparation composer validate --strict --no-check-publish
  if php scripts/local-runtime.php php-ready; then
    ok "Zależności PHP są zgodne z composer.lock; pomijam ponowną instalację."
  else
    info "Przygotowuję zależności PHP z composer.lock."
    local_run_preparation composer install --prefer-dist --no-interaction
    php scripts/local-runtime.php php-ready || fail "Zależności PHP są niekompletne. Sprawdź wynik Composer."
  fi
  local_run_preparation composer check-platform-reqs --no-dev

  if php scripts/local-runtime.php assets-ready; then
    ok "Zasoby edytora są aktualne; pomijam npm ci i build."
  else
    command -v node >/dev/null && command -v npm >/dev/null || { local_dependency_hint; fail "Brak Node.js/npm potrzebnych do zbudowania edytora."; }
    node -e 'if (Number(process.versions.node.split(".")[0]) < 22) process.exit(1)' || {
      local_dependency_hint; fail "Budowanie edytora wymaga Node.js 22+. Dostępna wersja: $(node --version).";
    }
    if ! node scripts/check-local-node.cjs; then
      info "Przygotowuję zależności edytora z package-lock.json."
      local_run_preparation npm ci --include=dev --no-audit --no-fund
    fi
    info "Buduję zasoby edytora."
    local_run_preparation npm run build
    php scripts/local-runtime.php assets-ready || fail "Niekompletne lub niespójne zasoby edytora po buildzie."
  fi

  local browse_host="$LOCAL_HOST" bind_host="$LOCAL_HOST" local_url token session_name php_version deadline state exit_status=0
  [[ "$browse_host" != 0.0.0.0 ]] || browse_host=127.0.0.1
  [[ "$browse_host" != :: ]] || browse_host=::1
  [[ "$bind_host" != *:* ]] || bind_host="[$bind_host]"
  [[ "$browse_host" != *:* ]] || browse_host="[$browse_host]"
  local_url="http://$browse_host:$LOCAL_PORT"
  token=$(php -r 'echo bin2hex(random_bytes(16));')
  session_name=$(php -r 'echo "OPENWIKI_LOCAL_" . substr(hash("sha256", $argv[1]), 0, 16);' "$local_url")
  php_version=$(php -r 'echo PHP_VERSION;')
  info "Katalog projektu: $SCRIPT_DIR"
  info "PHP CLI: $php_version"
  info "Nasłuch: $bind_host:$LOCAL_PORT. Logi serwera są wyświetlane w tym terminalu."
  (
    unset PHP_CLI_SERVER_WORKERS
    export APP_URL="$local_url" SESSION_SECURE_COOKIE=false SESSION_COOKIE_NAME="$session_name" OPENWIKI_LOCAL_PROBE_TOKEN="$token"
    exec php -d opcache.enable_cli=0 -d upload_max_filesize=50M -d post_max_size=64M \
      -d "session.save_path=$SCRIPT_DIR/storage/temp/local-sessions" \
      -S "$bind_host:$LOCAL_PORT" -t "$SCRIPT_DIR/public" "$SCRIPT_DIR/scripts/local-router.php"
  ) &
  LOCAL_SERVER_PID=$!
  deadline=$((SECONDS + 10))
  state=pending
  while ((SECONDS < deadline)); do
    if ! kill -0 "$LOCAL_SERVER_PID" 2>/dev/null; then
      wait "$LOCAL_SERVER_PID" || exit_status=$?
      LOCAL_SERVER_PID=""
      fail "Serwer PHP nie wystartował (kod $exit_status). Sprawdź log powyżej: port $LOCAL_PORT mógł zostać zajęty podczas przygotowania. Żaden obcy proces nie został zatrzymany."
    fi
    state=$(php scripts/local-runtime.php probe "$local_url" "$token")
    [[ "$state" == pending ]] || break
    sleep 0.2
  done
  case "$state" in
    setup)
      ok "Serwer lokalny działa. Dokończ konfigurację:"
      printf '%s/install\n' "$local_url"
      info "Kreator wymaga dostępnego serwera MySQL/MariaDB i danych konta z odpowiednimi uprawnieniami." ;;
    ready) ok "Serwer lokalny działa. Aplikacja, baza i katalog danych są dostępne." ;;
    database_error) fail "Serwer odpowiada, ale /health zgłasza problem z bazą. Sprawdź dostępność MySQL/MariaDB oraz istniejące DB_* w .env; konfiguracja i dane nie zostały zmienione." ;;
    storage_error) fail "Serwer odpowiada, ale /health zgłasza problem z katalogiem danych storage/." ;;
    wrong_server) fail "Port $LOCAL_PORT obsługuje inny serwer. Wybierz inny --port; obcy proces pozostaje uruchomiony." ;;
    installer_error) fail "Serwer odpowiada setup_required, ale kreator /install nie jest dostępny. Sprawdź log aplikacji powyżej." ;;
    *) fail "Serwer nie przeszedł testu HTTP /health w ciągu 10 sekund (stan: $state). Sprawdź log aplikacji i konfigurację." ;;
  esac
  info "Adres aplikacji: $local_url"
  info "Zatrzymanie: Ctrl+C. Zmiany PHP są widoczne po odświeżeniu; źródła JS przebuduj przez npm run build."
  if wait "$LOCAL_SERVER_PID"; then exit_status=0; else exit_status=$?; fi
  LOCAL_SERVER_PID=""
  [[ "$exit_status" == 0 ]] || fail "Serwer PHP zakończył działanie z kodem $exit_status."
}
