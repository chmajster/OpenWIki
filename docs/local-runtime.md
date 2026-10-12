# Lokalny runtime — granice odpowiedzialności

Linux/WSL obsługuje osobny wybór środowiska i akcji. Samo `--local` pozostaje
na pierwszym planie z Ctrl+C. W tle i do zarządzania:

```bash
bash install.sh --local --start --port 8090
bash install.sh --local --status
bash install.sh --local --logs
bash install.sh --local --restart
bash install.sh --local --stop
bash install.sh --local --no-start
```

Adres i port są zapamiętywane w ignorowanym `.openwiki/local/state.json`, a logi
w `server.log` obok niego. `--start` oddaje terminal po rzeczywistym sprawdzeniu
gotowości. Stop weryfikuje PID, czas startu z `/proc`, router, document root i
token środowiska; nie zabija obcego procesu po ponownym użyciu PID-u.
`--no-start` przygotowuje zależności i pozostawia własny serwer zatrzymany.
Operacje są serializowane przez `flock`; status i logi pozostają dostępne także
przy serwerze na pierwszym planie. Kolejność flag nie zmienia działania.

Tryb [Docker](docker-runtime.md) korzysta z tego samego kodu i kreatora, ale
ma odrębne dane oraz narzędzia w kontenerach. Nie przenosi lokalnej bazy.

Tryb `install.sh --local` korzysta z katalogu ustalonego przez `SCRIPT_DIR`, przed procedurami wdrożenia systemowego. Nie pobiera kodu, nie zmienia gałęzi, nie konfiguruje usług i nie przejmuje własności repozytorium.

PHP CLI obsługuje HTTP z document root `public/`. Osobny router kieruje aplikację do istniejącego front controllera i udostępnia wyłącznie bezpieczne zasoby publiczne. Zachowanie routera odpowiada [dokumentacji PHP](https://www.php.net/manual/en/features.commandline.webserver.php).

Stan zależności jest porównywany z lockfile; przygotowanie używa `composer install` oraz istniejącego procesu budowania edytora. Sprawdzane są wersje, referencje i katalogi pakietów PHP oraz wersje i integralność zależności npm. Próba esbuild wykrywa również zależności zainstalowane dla Windows używane w WSL. Przed świeżą instalacją Composer sprawdza wymagania całego lockfile, w tym rozszerzenia potrzebne zależnościom testowym (np. XML/XMLWriter z pakietu `php-xml`). Zasoby wymagają przebudowania po zmianie źródeł.

`scripts/build-assets.cjs` zapisuje atomowo ignorowany przez Git `public/assets/editor-manifest.json`. Manifest zawiera odcisk źródeł, skryptu budowania i lockfile oraz sumy wszystkich wynikowych zasobów. Brakujący lub zmieniony plik wymusza build. Kompletne zasoby mogą działać bez Node.js, również w archiwum wydania zawierającym manifest. Node.js nie obsługuje żądań HTTP.

Istniejące `.env`, klucz aplikacji, baza i znacznik instalacji pozostają własnością instancji. Proces HTTP otrzymuje lokalny URL i ustawienia ciasteczek. Nowa instancja używa obecnego kreatora MySQL/MariaDB; `setup_required` z HTTP 503 oznacza konieczność konfiguracji, a nie awarię uruchomienia. Znacznik istniejącej instalacji bez czytelnej konfiguracji lub prawidłowego klucza powoduje błąd wymagający przywrócenia konfiguracji. Nie jest oferowany reset istniejących danych.

Skrypt nadzoruje swój proces PHP, wykonuje ograniczoną próbę HTTP i pozostaje na pierwszym planie z logami. Próba identyfikuje własny serwer losowym znacznikiem oraz sprawdza rzeczywisty healthcheck; dla nowej instancji także kreator. OPcache jest wyłączony tylko w lokalnym procesie, aby odświeżenie od razu odczytywało zmiany PHP. Zatrzymanie kończy własny serwer i zwalnia port. Na Linuxie/WSL przygotowanie zależności działa we własnej grupie procesów `setsid`, dzięki czemu przerwanie obejmuje również jego procesy potomne. Zajęty port nie powoduje zabijania innych procesów.

Testy jednostkowe `LocalRuntimeTest` i `LocalRouterTest` obejmują zgodność lockfile/zasobów, walidację adresu, zajęty port, prawa zapisu, ochronę konfiguracji, traversal i symlinki. Można je uruchomić przez:

```bash
vendor/bin/phpunit --filter 'Local(Runtime|Router)Test'
```

`tests/Local/runtime.py` wymaga Linuxa/WSL, użytkownika bez roota, PHP/Composer/Node/npm oraz **odizolowanej** instancji MySQL/MariaDB. Ustaw `OPENWIKI_TEST_DB_HOST`, `OPENWIKI_TEST_DB_PORT`, `OPENWIKI_TEST_DB_USERNAME` i `OPENWIKI_TEST_DB_PASSWORD`; konto musi móc tworzyć i usuwać bazy testowe. Test tworzy własny katalog ze spacjami i losową bazę `openwiki_local_*`, obsługuje prawdziwe formularze kreatora/logowania i usuwa własne zasoby po zakończeniu. Nie używa konfiguracji bazy uruchomionej aplikacji.

```bash
python3 tests/Local/runtime.py
# WSL: kopia testowa na tym samym /mnt/c co repozytorium
python3 tests/Local/runtime.py --workspace-temp
```

Domyślnie test używa portów 8080 i 8090. Jeżeli są zajęte, można ustawić `OPENWIKI_LOCAL_TEST_START_PORT` (drugi port jest o 10 większy); obce procesy pozostają uruchomione. Weryfikacja obejmuje pełną ścieżkę z innego katalogu, lokalne zmiany Git/PHP, kreator, logowanie, API, chronione załączniki, MIME, ochronę plików, restart bez Node.js, przebudowę po zmianie źródła, uszkodzoną konfigurację, awarię bazy, Ctrl+C/SIGINT/SIGTERM, przerwanie przygotowania zależności oraz wyścig zajęcia portu po preflight.

CI uruchamia ten przepływ bez roota z MySQL 8.0, istniejące testy PHP i przeglądarkowe oraz weryfikuje archiwum PHP bez `node_modules`. Oddzielny job sprawdza świeżą instalację systemową, przypięte narzędzie Node.js, naprawę zachowującą `.env` i odinstalowanie. Weryfikacja WSL używa kopii na `/mnt/c` i MariaDB. Natywne uruchomienie bez WSL na Windows oraz pełny przepływ na macOS nie są objęte tym testem.
