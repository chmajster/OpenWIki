# Lokalny runtime — granice odpowiedzialności

Tryb `install.sh --local` korzysta z katalogu ustalonego przez `SCRIPT_DIR`, przed procedurami wdrożenia systemowego. Nie pobiera kodu, nie zmienia gałęzi, nie konfiguruje usług i nie przejmuje własności repozytorium.

PHP CLI obsługuje HTTP z document root `public/`. Osobny router kieruje aplikację do istniejącego front controllera i udostępnia wyłącznie bezpieczne zasoby publiczne. Zachowanie routera odpowiada [dokumentacji PHP](https://www.php.net/manual/en/features.commandline.webserver.php).

Stan zależności jest porównywany z lockfile; przygotowanie używa `composer install` oraz istniejącego procesu budowania edytora. Potwierdzenie przygotowania nie zastępuje sprawdzenia obecności i zgodności plików. Zasoby wymagają przebudowania po zmianie źródeł.

Istniejące `.env`, klucz aplikacji, baza i znacznik instalacji pozostają własnością instancji. Proces HTTP otrzymuje lokalny URL i ustawienia ciasteczek. Nowa instancja używa obecnego kreatora MySQL/MariaDB; `setup_required` z HTTP 503 oznacza konieczność konfiguracji, a nie awarię uruchomienia.

Skrypt nadzoruje wyłącznie swój proces PHP, wykonuje ograniczoną próbę HTTP i pozostaje na pierwszym planie z logami. Zatrzymanie kończy własny serwer i zwalnia port. Zajęty port nie powoduje zabijania innych procesów.

Weryfikacja obejmuje uruchomienie bez roota, ścieżki ze spacjami i WSL, kreator, skonfigurowaną bazę testową, routing, ochronę plików, aktualizację kodu po odświeżeniu, sygnały oraz ponowne uruchomienie bez utraty konfiguracji i danych. Instalacja systemowa zachowuje osobny test end-to-end.
