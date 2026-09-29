#!/usr/bin/env bash
set -Eeuo pipefail

APP_NAME="OpenWiki"
REPO_URL="https://github.com/chmajster/OpenWIki.git"
GIT_REF="${OPENWIKI_GIT_REF:-main}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
INSTALL_DIR="${OPENWIKI_INSTALL_DIR:-/var/www/openwiki}"
DB_NAME="${OPENWIKI_DB_NAME:-openwiki}"
DB_USER="${OPENWIKI_DB_USER:-openwiki}"
WEB_USER=""
PHP_FPM_SERVICE=""
NGINX_SITE="openwiki"
CRON_FILE="/etc/cron.d/openwiki"
MODE="install"
NO_COLOR="${NO_COLOR:-0}"

if [[ -t 1 && "${NO_COLOR:-0}" != "1" ]]; then
  C_OK='\033[0;32m'; C_INFO='\033[0;36m'; C_WARN='\033[1;33m'; C_FAIL='\033[0;31m'; C_RESET='\033[0m'
else
  C_OK=''; C_INFO=''; C_WARN=''; C_FAIL=''; C_RESET=''
fi

ok()   { printf "%b[ OK ]%b %s\n" "$C_OK" "$C_RESET" "$*"; }
info() { printf "%b[INFO]%b %s\n" "$C_INFO" "$C_RESET" "$*"; }
warn() { printf "%b[WARN]%b %s\n" "$C_WARN" "$C_RESET" "$*" >&2; }
fail() { printf "%b[FAIL]%b %s\n" "$C_FAIL" "$C_RESET" "$*" >&2; exit 1; }

trap 'fail "Instalacja przerwana w linii $LINENO."' ERR

usage() {
  cat <<EOF
OpenWiki installer

Użycie:
  sudo ./install.sh [opcje]

Opcje:
  --install              Instalacja lub naprawa OpenWiki (domyślnie)
  --status               Status aplikacji i usług
  --uninstall            Usuń konfigurację usług i pliki OpenWiki
  --install-dir PATH     Katalog instalacji (domyślnie: /var/www/openwiki)
  --no-color             Wyłącz kolory
  --help                 Pomoc

Zmienne środowiskowe:
  OPENWIKI_INSTALL_DIR
  OPENWIKI_DB_NAME
  OPENWIKI_DB_USER
  OPENWIKI_GIT_REF       Branch/tag pobierany z Git (domyślnie: main)
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --install) MODE="install" ;;
    --status) MODE="status" ;;
    --uninstall) MODE="uninstall" ;;
    --install-dir) shift; [[ $# -gt 0 ]] || fail "Brak wartości dla --install-dir"; INSTALL_DIR="$1" ;;
    --no-color) NO_COLOR=1; C_OK=''; C_INFO=''; C_WARN=''; C_FAIL=''; C_RESET='' ;;
    --help|-h) usage; exit 0 ;;
    *) fail "Nieznana opcja: $1" ;;
  esac
  shift
done

validate_install_dir() {
  [[ "$INSTALL_DIR" == /* ]] || fail "Katalog instalacji musi być ścieżką absolutną."

  local normalized
  normalized="$(readlink -m -- "$INSTALL_DIR")"
  case "$normalized" in
    /|/bin|/boot|/dev|/etc|/home|/lib|/lib32|/lib64|/media|/mnt|/opt|/proc|/root|/run|/sbin|/srv|/sys|/tmp|/usr|/var|/var/www)
      fail "Niebezpieczny katalog instalacji: $normalized"
      ;;
  esac

  [[ "$normalized" != *$'\n'* && "$normalized" != *$'\r'* ]] ||
    fail "Katalog instalacji zawiera niedozwolone znaki."
  INSTALL_DIR="$normalized"
}

require_root() {
  [[ $EUID -eq 0 ]] || fail "Uruchom jako root: sudo ./install.sh"
}

detect_os() {
  [[ -r /etc/os-release ]] || fail "Brak /etc/os-release."
  # shellcheck disable=SC1091
  . /etc/os-release
  OS_ID="${ID:-}"
  OS_LIKE="${ID_LIKE:-}"

  if [[ "$OS_ID" =~ ^(ubuntu|debian)$ || "$OS_LIKE" == *debian* ]]; then
    PKG_FAMILY="apt"
    WEB_USER="www-data"
  elif [[ "$OS_ID" =~ ^(rhel|rocky|almalinux|centos|fedora)$ || "$OS_LIKE" == *rhel* || "$OS_LIKE" == *fedora* ]]; then
    PKG_FAMILY="dnf"
    WEB_USER="apache"
  else
    fail "Nieobsługiwany system: ${PRETTY_NAME:-$OS_ID}. Obsługiwane: Debian/Ubuntu, RHEL/Rocky/Alma/Fedora."
  fi
}

install_packages() {
  info "[1/8] Instalacja zależności"
  if [[ "$PKG_FAMILY" == "apt" ]]; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y
    apt-get install -y git curl ca-certificates nginx mariadb-server composer \
      php-cli php-fpm php-mysql php-mbstring php-xml php-curl php-ldap php-zip php-gd
  else
    if ! command -v php >/dev/null 2>&1 || ! php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' >/dev/null 2>&1; then
      if dnf -q module list php:8.2 >/dev/null 2>&1; then
        dnf -y module reset php >/dev/null
        dnf -y module enable php:8.2 >/dev/null
      fi
    fi
    dnf install -y git curl ca-certificates nginx mariadb-server composer policycoreutils-python-utils \
      php-cli php-fpm php-mysqlnd php-mbstring php-xml php-curl php-ldap php-zip php-gd
  fi

  command -v php >/dev/null || fail "PHP nie został zainstalowany."
  command -v nginx >/dev/null || fail "Nginx nie został zainstalowany."
  command -v mariadb >/dev/null || command -v mysql >/dev/null || fail "Klient MariaDB/MySQL nie został zainstalowany."

  php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' ||
    fail "OpenWiki wymaga PHP >= 8.2. Zainstalowana wersja: $(php -r 'echo PHP_VERSION;')"

  local missing=()
  for ext in pdo pdo_mysql mbstring openssl dom phar curl ldap zip gd; do
    php -m | grep -qi "^$ext$" || missing+=("$ext")
  done
  [[ ${#missing[@]} -eq 0 ]] || fail "Brak rozszerzeń PHP: ${missing[*]}"
  ok "Zależności są dostępne."
}

find_php_fpm_service() {
  systemctl list-unit-files --type=service --no-legend 2>/dev/null |
    awk '{print $1}' |
    grep -E '^(php-fpm|php[0-9]+(\.[0-9]+)?-fpm)\.service$' |
    head -n1 || true
}

detect_php_runtime_user() {
  local conf user
  local configs=()

  if [[ "$PKG_FAMILY" == "apt" ]]; then
    configs=(/etc/php/*/fpm/pool.d/www.conf)
  else
    configs=(/etc/php-fpm.d/www.conf)
  fi

  for conf in "${configs[@]}"; do
    [[ -f "$conf" ]] || continue
    user="$(awk -F= '/^[[:space:]]*user[[:space:]]*=/{gsub(/[[:space:]]/, "", $2); print $2; exit}' "$conf")"
    if [[ -n "$user" ]] && id "$user" >/dev/null 2>&1; then
      WEB_USER="$user"
      return 0
    fi
  done

  id "$WEB_USER" >/dev/null 2>&1 || fail "Nie znaleziono użytkownika runtime PHP-FPM."
}

secure_env_permissions() {
  [[ -f "$INSTALL_DIR/.env" ]] || return 0
  chown root:"$WEB_USER" "$INSTALL_DIR/.env"
  chmod 0640 "$INSTALL_DIR/.env"
}

start_services() {
  info "[2/8] Uruchamianie usług"
  systemctl enable --now mariadb
  systemctl enable --now nginx

  PHP_FPM_SERVICE="$(find_php_fpm_service)"
  [[ -n "$PHP_FPM_SERVICE" ]] || fail "Nie znaleziono usługi PHP-FPM."
  systemctl enable --now "$PHP_FPM_SERVICE"
  detect_php_runtime_user
  ok "MariaDB, Nginx i PHP-FPM działają (runtime: $WEB_USER)."
}

deploy_code() {
  info "[3/8] Pobieranie aplikacji"
  if [[ -d "$INSTALL_DIR/.git" ]]; then
    git -C "$INSTALL_DIR" fetch --prune origin
    git -C "$INSTALL_DIR" checkout "$GIT_REF"
    git -C "$INSTALL_DIR" pull --ff-only origin "$GIT_REF"
  elif [[ -f "$SCRIPT_DIR/composer.json" && -d "$SCRIPT_DIR/app" && -d "$SCRIPT_DIR/public" ]]; then
    mkdir -p "$INSTALL_DIR"
    if [[ "$(readlink -f "$SCRIPT_DIR")" != "$(readlink -f "$INSTALL_DIR")" ]]; then
      cp -a "$SCRIPT_DIR/." "$INSTALL_DIR/"
    fi
  else
    [[ ! -e "$INSTALL_DIR" || -z "$(ls -A "$INSTALL_DIR" 2>/dev/null || true)" ]] ||
      fail "Katalog $INSTALL_DIR istnieje i nie jest pusty."
    git clone --branch "$GIT_REF" --depth 1 "$REPO_URL" "$INSTALL_DIR"
  fi

  cd "$INSTALL_DIR"
  composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
  mkdir -p storage/{attachments,backups,cache,temp,logs}
  chown -R root:"$WEB_USER" "$INSTALL_DIR"
  find "$INSTALL_DIR" -type d -exec chmod 0755 {} \;
  find "$INSTALL_DIR" -type f -exec chmod 0644 {} \;
  chown -R "$WEB_USER":"$WEB_USER" "$INSTALL_DIR/storage"
  find "$INSTALL_DIR/storage" -type d -exec chmod 0770 {} \;
  find "$INSTALL_DIR/storage" -type f -exec chmod 0660 {} \;
  chmod 0755 "$INSTALL_DIR/bin/console"
  [[ -f "$INSTALL_DIR/install.sh" ]] && chmod 0755 "$INSTALL_DIR/install.sh"
  secure_env_permissions
  ok "Kod aplikacji przygotowany w $INSTALL_DIR."
}

read_value() {
  local __var="$1" prompt="$2" default="${3:-}" value
  if [[ -n "$default" ]]; then
    read -r -p "$prompt [$default]: " value
    value="${value:-$default}"
  else
    read -r -p "$prompt: " value
  fi
  printf -v "$__var" '%s' "$value"
}

read_secret() {
  local __var="$1" prompt="$2" value
  read -r -s -p "$prompt: " value
  printf '\n'
  printf -v "$__var" '%s' "$value"
}

configure_database() {
  info "[4/8] Konfiguracja bazy danych"

  if [[ -f "$INSTALL_DIR/storage/installed.lock" ]]; then
    [[ -f "$INSTALL_DIR/.env" ]] || fail "Wykryto installed.lock bez .env. Napraw konfigurację przed ponownym uruchomieniem."
    ok "Wykryto istniejącą instalację. Dane logowania DB pozostają bez zmian."
    return 0
  fi

  read_value DB_NAME_INPUT "Nazwa bazy danych" "$DB_NAME"
  read_value DB_USER_INPUT "Użytkownik bazy danych" "$DB_USER"
  DB_NAME="$DB_NAME_INPUT"
  DB_USER="$DB_USER_INPUT"

  while true; do
    read_secret DB_PASSWORD "Hasło użytkownika bazy (pozostaw puste, aby wygenerować)"
    if [[ -z "$DB_PASSWORD" ]]; then
      DB_PASSWORD="$(php -r 'echo bin2hex(random_bytes(18));')"
      break
    fi
    [[ ${#DB_PASSWORD} -ge 12 ]] && break
    warn "Hasło bazy powinno mieć co najmniej 12 znaków."
  done

  [[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || fail "Nieprawidłowa nazwa bazy."
  [[ "$DB_USER" =~ ^[A-Za-z0-9_.-]+$ ]] || fail "Nieprawidłowa nazwa użytkownika bazy."

  local sql_pass
  sql_pass="${DB_PASSWORD//\\/\\\\}"
  sql_pass="${sql_pass//\'/\'\'}"

  mariadb --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$sql_pass';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$sql_pass';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
  ok "Baza $DB_NAME i użytkownik $DB_USER są gotowe."
}

run_app_installer() {
  info "[5/8] Instalacja OpenWiki"
  if [[ -f "$INSTALL_DIR/storage/installed.lock" ]]; then
    [[ -f "$INSTALL_DIR/.env" ]] || fail "Wykryto installed.lock bez .env. Instalacja jest niespójna."
    secure_env_permissions
    ok "OpenWiki jest już zainstalowane. Pomijam ponowną inicjalizację aplikacji."
    return 0
  fi

  read_value INSTANCE_NAME "Nazwa instancji" "OpenWiki"
  read_value APP_URL "URL aplikacji" "http://$(hostname -f 2>/dev/null || hostname)"
  read_value APP_TIMEZONE "Strefa czasowa" "Europe/Warsaw"
  while true; do
    read_value ADMIN_USERNAME "Login administratora" "admin"
    [[ "$ADMIN_USERNAME" =~ ^[A-Za-z0-9._-]{3,100}$ ]] && break
    warn "Login administratora musi mieć 3-100 znaków: litery, cyfry, kropka, _ lub -."
  done

  while true; do
    read_value ADMIN_EMAIL "E-mail administratora" ""
    php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_EMAIL) ? 0 : 1);' "$ADMIN_EMAIL" && break
    warn "Podaj poprawny adres e-mail administratora."
  done

  while true; do
    read_secret ADMIN_PASSWORD "Hasło administratora (min. 12 znaków)"
    [[ ${#ADMIN_PASSWORD} -ge 12 ]] && break
    warn "Hasło administratora musi mieć co najmniej 12 znaków."
  done

  read_value ADMIN_FIRST_NAME "Imię administratora" ""
  read_value ADMIN_LAST_NAME "Nazwisko administratora" ""

  printf '%s\n' \
    "$INSTANCE_NAME" \
    "$APP_URL" \
    "$APP_TIMEZONE" \
    "127.0.0.1" \
    "3306" \
    "$DB_NAME" \
    "$DB_USER" \
    "$DB_PASSWORD" \
    "n" \
    "$ADMIN_USERNAME" \
    "$ADMIN_EMAIL" \
    "$ADMIN_PASSWORD" \
    "$ADMIN_FIRST_NAME" \
    "$ADMIN_LAST_NAME" | php "$INSTALL_DIR/bin/console" install

  secure_env_permissions
  chown -R "$WEB_USER":"$WEB_USER" "$INSTALL_DIR/storage"
  ok "Instalator aplikacji zakończony."
}

detect_fpm_socket() {
  local sockets=(
    /run/php/php*-fpm.sock
    /run/php-fpm/www.sock
    /var/run/php-fpm/www.sock
  )
  local s
  for s in "${sockets[@]}"; do
    for candidate in $s; do
      [[ -S "$candidate" ]] && { printf '%s' "$candidate"; return 0; }
    done
  done
  return 1
}

configure_selinux() {
  [[ "$PKG_FAMILY" == "dnf" ]] || return 0
  command -v getenforce >/dev/null 2>&1 || return 0
  [[ "$(getenforce)" != "Disabled" ]] || return 0

  info "[SELinux] Konfiguracja dostępu aplikacji"
  if command -v semanage >/dev/null 2>&1; then
    semanage fcontext -a -t httpd_sys_rw_content_t "$INSTALL_DIR/storage(/.*)?" 2>/dev/null ||
      semanage fcontext -m -t httpd_sys_rw_content_t "$INSTALL_DIR/storage(/.*)?"
  fi
  command -v restorecon >/dev/null 2>&1 && restorecon -RF "$INSTALL_DIR"
  command -v setsebool >/dev/null 2>&1 && setsebool -P httpd_can_network_connect_db 1
  ok "SELinux przygotowany dla storage i połączenia z bazą."
}

nginx_server_names() {
  local names="_ localhost 127.0.0.1"
  local host fqdn ip app_url app_host

  host="$(hostname 2>/dev/null || true)"
  fqdn="$(hostname -f 2>/dev/null || true)"
  ip="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"

  [[ "$host" =~ ^[A-Za-z0-9.-]+$ ]] && names+=" $host"
  [[ "$fqdn" =~ ^[A-Za-z0-9.-]+$ ]] && names+=" $fqdn"
  [[ "$ip" =~ ^[0-9.]+$ ]] && names+=" $ip"

  if [[ -f "$INSTALL_DIR/.env" ]]; then
    app_url="$(php -r '$v=parse_ini_file($argv[1], false, INI_SCANNER_RAW); echo $v["APP_URL"] ?? "";' "$INSTALL_DIR/.env" 2>/dev/null || true)"
    app_url="${app_url%\"}"
    app_url="${app_url#\"}"
    app_host="$(php -r 'echo (string) (parse_url($argv[1], PHP_URL_HOST) ?: "");' "$app_url" 2>/dev/null || true)"
    [[ "$app_host" =~ ^[A-Za-z0-9.-]+$ ]] && names+=" $app_host"
  fi

  printf '%s' "$names"
}

configure_nginx() {
  info "[6/8] Konfiguracja Nginx"
  local socket
  socket="$(detect_fpm_socket)" || fail "Nie znaleziono socketu PHP-FPM."

  local conf server_names
  server_names="$(nginx_server_names)"
  if [[ "$PKG_FAMILY" == "apt" ]]; then
    conf="/etc/nginx/sites-available/$NGINX_SITE"
  else
    conf="/etc/nginx/conf.d/$NGINX_SITE.conf"
  fi

  cat > "$conf" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $server_names;
    root $INSTALL_DIR/public;
    index index.php;

    client_max_body_size 64m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTP_PROXY "";
        fastcgi_pass unix:$socket;
    }

    location ~ /\. {
        deny all;
    }
}
NGINX

  if [[ "$PKG_FAMILY" == "apt" ]]; then
    ln -sfn "$conf" "/etc/nginx/sites-enabled/$NGINX_SITE"
    rm -f /etc/nginx/sites-enabled/default
  fi

  nginx -t
  systemctl reload nginx
  ok "Nginx wskazuje na $INSTALL_DIR/public."
}

configure_cron() {
  info "[7/8] Konfiguracja zadań cyklicznych"
  cat > "$CRON_FILE" <<EOF
* * * * * $WEB_USER cd "$INSTALL_DIR" && /usr/bin/php bin/console cron:run >/dev/null 2>&1
EOF
  chmod 0644 "$CRON_FILE"
  ok "Cron OpenWiki skonfigurowany."
}

verify_installation() {
  info "[8/8] Weryfikacja"
  cd "$INSTALL_DIR"
  php bin/console system:check
  php bin/console db:status
  if command -v runuser >/dev/null 2>&1; then
    runuser -u "$WEB_USER" -- test -r "$INSTALL_DIR/.env"
    runuser -u "$WEB_USER" -- test -w "$INSTALL_DIR/storage"
    runuser -u "$WEB_USER" -- php "$INSTALL_DIR/bin/console" db:status
    ok "Runtime $WEB_USER ma dostęp do konfiguracji, storage i bazy."
  else
    warn "Brak runuser — pominięto weryfikację uprawnień jako $WEB_USER."
  fi
  nginx -t
  systemctl is-active --quiet nginx
  systemctl is-active --quiet mariadb
  curl --fail --silent --show-error --max-time 10 http://127.0.0.1/health >/dev/null
  ok "Healthcheck HTTP zakończony powodzeniem."
  printf '\n'
  ok "OpenWiki jest zainstalowane."
  info "Katalog: $INSTALL_DIR"
  info "Adres: http://$(hostname -I 2>/dev/null | awk '{print $1}' || echo localhost)/"
}

status_mode() {
  detect_os
  info "OpenWiki status"
  [[ -d "$INSTALL_DIR" ]] && ok "Katalog: $INSTALL_DIR" || warn "Brak katalogu: $INSTALL_DIR"
  [[ -f "$INSTALL_DIR/storage/installed.lock" ]] && ok "installed.lock obecny" || warn "Brak installed.lock"
  systemctl is-active --quiet nginx && ok "nginx active" || warn "nginx inactive"
  systemctl is-active --quiet mariadb && ok "mariadb active" || warn "mariadb inactive"
  PHP_FPM_SERVICE="$(find_php_fpm_service)"
  if [[ -n "$PHP_FPM_SERVICE" ]] && systemctl is-active --quiet "$PHP_FPM_SERVICE"; then
    ok "$PHP_FPM_SERVICE active"
  else
    warn "PHP-FPM inactive lub niewykryty"
  fi
  if [[ -f "$INSTALL_DIR/bin/console" ]]; then
    (cd "$INSTALL_DIR" && php bin/console system:check) || true
  fi
}

uninstall_mode() {
  require_root
  detect_os
  warn "Usunięcie OpenWiki skasuje pliki aplikacji. Baza danych pozostanie bez zmian."
  read -r -p "Wpisz DELETE aby kontynuować: " confirmation
  [[ "$confirmation" == "DELETE" ]] || fail "Anulowano."

  rm -f "$CRON_FILE"
  if [[ "$PKG_FAMILY" == "dnf" ]] && command -v semanage >/dev/null 2>&1; then
    semanage fcontext -d "$INSTALL_DIR/storage(/.*)?" 2>/dev/null || true
  fi
  if [[ "$PKG_FAMILY" == "apt" ]]; then
    rm -f "/etc/nginx/sites-enabled/$NGINX_SITE" "/etc/nginx/sites-available/$NGINX_SITE"
    if [[ -f /etc/nginx/sites-available/default ]]; then
      ln -sfn /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default
    fi
  else
    rm -f "/etc/nginx/conf.d/$NGINX_SITE.conf"
  fi
  nginx -t && systemctl reload nginx || true
  rm -rf "$INSTALL_DIR"
  ok "Pliki i konfiguracja OpenWiki zostały usunięte. Baza danych nie została usunięta."
}

validate_install_dir

case "$MODE" in
  status) status_mode; exit 0 ;;
  uninstall) uninstall_mode; exit 0 ;;
esac

require_root
detect_os
install_packages
start_services
deploy_code
configure_database
run_app_installer
configure_selinux
configure_nginx
configure_cron
verify_installation
