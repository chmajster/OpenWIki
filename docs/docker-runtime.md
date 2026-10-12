# Docker — bieżąca kopia projektu

Wymagane są Linux/WSL, Bash, standardowe narzędzia OS (w tym `flock`), Docker
Engine albo Docker Desktop z integracją WSL oraz Docker Compose v2. Użytkownik
musi mieć dostęp do Dockera i prawo zapisu kopii projektu. PHP, Composer, Node,
npm, Nginx i MariaDB działają w kontenerach.

```bash
bash ./install.sh --docker
bash ./install.sh --docker --host 127.0.0.1 --port 8090
bash "/ścieżka ze spacjami/OpenWIki/install.sh" --port 8090 --docker
```

Domyślny adres to `http://127.0.0.1:8080`; wskazany port daje
`http://127.0.0.1:8090`. Host może być IPv4, IPv6 lub `localhost`, port 1–65535.
Pierwszy start udostępnia `/install`. Kreator ma gotowy host `db`, port 3306,
bazę i konto aplikacji. Hasło pochodzi z konfiguracji serwera, nie z formularza.
Standardowe konto startowe `admin/admin` wymaga zmiany hasła po zalogowaniu.

| Polecenie | Działanie |
|---|---|
| `--docker` | Przygotowanie zależności/zasobów, start w tle, kontrola gotowości |
| `--docker --start` | Przywrócenie usług i sprawdzenie zależności/zasobów |
| `--docker --stop` | Zatrzymanie własnego projektu z zachowaniem danych |
| `--docker --restart` | Odtworzenie usług z zapamiętaną konfiguracją |
| `--docker --status` | Kontenery, HTTP, baza, aplikacja i heartbeat workera |
| `--docker --logs` | Ostatnie 100 linii logów właściwego projektu |
| `--docker --rebuild` | Odświeżenie obrazów i zasobów; odtworzenie usług |
| `--docker --no-start` | Przygotowanie środowiska z usługami zatrzymanymi |

Każde polecenie ma jedną akcję. `--local` i `--docker` się wykluczają; kolejność
flag nie zmienia znaczenia. Adres można zmienić przy przygotowaniu, starcie,
restarcie lub rebuildzie. Status, logi i stop korzystają z zapisanego adresu.
Zajęty port nie zatrzymuje obcych procesów. Operacje tej samej kopii są
serializowane przez `flock`. Hash rzeczywistej ścieżki wyznacza projekt Compose,
więc różne kopie o tej samej nazwie katalogu mają odrębne kontenery i bazy.

## Montowania i zależności

PHP pracuje w `/app` z [bind mount RW](https://docs.docker.com/engine/storage/bind-mounts/)
dokładnie z katalogu skryptu. Nginx używa tego samego `public/` z montowaniem
tylko do odczytu. OPcache sprawdza timestamp przy każdym żądaniu; PHP nie wymaga
rebuilda po edycji. Nie ma operacji Git, kopiowania aplikacji do wdrożenia ani
konfiguracji usług na hoście.

`vendor/` i `node_modules/` są zasłonięte osobnymi wolumenami kontenera.
Hostowe zależności pozostają nietknięte; brakujące katalogi pod montowania
tworzy użytkownik hosta. PHP, Composer i builder używają jego UID/GID.
Kontener uprawnień zmienia właściciela tylko prywatnych wolumenów zależności
i cache, bez `chmod 777` i bez chown repozytorium.

Composer korzysta z `composer.lock`. Node 22 wykonuje `npm ci --include=dev`
i `npm run build`. Wynik trafia do rzeczywistego `public/assets/`. Manifest
sprawdza źródła, lockfile i sumy wyników. Ponowne `--docker` wykrywa zmianę
źródeł edytora; `--rebuild` wymusza budowanie i odświeża obrazy. Node nie
obsługuje żądań gotowej aplikacji PHP.

## Trwałość

```text
.openwiki/docker/compose.env       tożsamość projektu, adres, hasła bazy
.openwiki/docker/runtime/.env      konfiguracja i APP_KEY kreatora
.openwiki/docker/runtime/storage/  marker, załączniki, sesje, cache, logi, backupy
```

Stan jest ignorowany przez Git. PHP i worker montują cały katalog `/runtime`,
co umożliwia atomowy zapis `.env` przez rename. `OPENWIKI_CONFIG_DIR` oraz
`OPENWIKI_STORAGE_DIR` wybierają te lokalizacje w usługach aplikacji. Bez nich
pozostają dotychczasowe `.env` i `storage/` lokalnej/systemowej instalacji.

MariaDB 11.4 ma osobne konto aplikacji, hasła tworzone tylko raz i wolumen
`<projekt>_database`; jej port nie jest publikowany. Stop, restart i rebuild
nie usuwają wolumenów. Nie usuwaj katalogu stanu, zachowując bazę: klucz,
poświadczenia i załączniki stanowią z nią jedną instancję. Utrata konfiguracji
jest błędem wymagającym odtworzenia, a nie zaproszeniem do świeżej instalacji.
Kopia stanu z innego katalogu jest odrzucana. Pliki sekretów mają tryb 600;
na dysku Windows stosowane są również uprawnienia jego systemu plików.

**Docker tworzy osobną instancję danych.** Zmiana flagi środowiska nie przenosi
lokalnej bazy ani załączników. Przeniesienie wymaga kontrolowanego backup/restore.

## Gotowość, zadania i odzyskiwanie

Compose czeka na bazę i procesy przez [zależności zdrowia usług](https://docs.docker.com/compose/how-tos/startup-order/).
WWW używa `/health/live`, aby kreator był dostępny przed końcem instalacji.
Końcowy test sprawdza rzeczywistą bazę, `/health` i `/install`: 503
`setup_required` oznacza działający kreator. Po instalacji wymagane są baza,
storage i zgodny schemat. Błąd HTTP, konfiguracji, bazy, storage lub migracji
nie jest sukcesem uruchomienia.

Worker czeka na gotowość, następnie co 60 sekund wykonuje istniejące
`php bin/console cron:run`. Sekwencyjne wykonanie i `flock` zapobiegają
nakładaniu zadań. Trwały heartbeat pozwala wykryć błąd lub zatrzymany worker.
Nie powstają cron ani systemd na hoście.

Po błędzie pobierania/builda popraw przyczynę i powtórz `--docker`; zapisane
hasła pozostają te same. Brakujący klucz lub dane runtime wymagają przywrócenia
konfiguracji tej instancji. Rebuild nie pobiera Git ani nie migruje danych
automatycznie. Dla `schema_error` sprawdź migracje w kontenerze PHP, którego
nazwa jest w statusie:

```bash
docker exec -it NAZWA_KONTENERA_PHP php bin/console db:status
```

Zmianę schematu przeprowadzaj z backupem i kontrolą zapisów zgodnie z
[procedurą aktualizacji](upgrades.md). Kontener używa tego samego migratora
`php bin/console migrate`. Nie publikuj `docker compose config` w logach
diagnostycznych: zawiera także hasła.

## Test odizolowany

```bash
python3 tests/Docker/runtime.py
# WSL: kopia testowa na /mnt/c
python3 tests/Docker/runtime.py --workspace-temp
```

Test tworzy własne kopie ze spacjami, projekty i bazy. PATH instalatora zawiera
Docker i narzędzia OS, bez PHP/Composer/Node/npm. Sprawdza kreator, logowanie,
formularz strony, upload, API, worker, ochronę plików, RW, zmiany PHP,
powtórzenie, rebuild, klucz i dane, zarządzanie, zajęty port oraz drugą kopię
o tej samej nazwie. Usuwa tylko własne projekty i wolumeny. CI wykonuje ten sam
test. Bazowy port zmienia `OPENWIKI_DOCKER_TEST_PORT` (domyślnie 18390).

CI włącza również rzeczywistą przeglądarkę dla kreatora na desktopie i telefonie.
Aby wykonać te dodatkowe sprawdzenia lokalnie, przygotuj narzędzia testowe:

```bash
npm ci --include=dev
npx playwright install chromium
OPENWIKI_DOCKER_BROWSER_TEST=1 python3 tests/Docker/runtime.py --workspace-temp
```

Node i przeglądarka służą tu wyłącznie testom; instalator nadal działa z PATH
bez hostowego PHP, Composer, Node i npm.
