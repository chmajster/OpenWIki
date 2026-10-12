# Uruchamianie bieżącej kopii — założenia i weryfikacja

Instalator systemowy i jego aktualizacje zarządzają wdrożeniem. Uruchamianie
bieżącej kopii projektu jest osobną operacją: nie zmienia Git ani katalogu kodu.
Środowisko (`--local`, `--docker`) i akcja muszą być wybierane niezależnie.

Dotychczasowy lokalny runtime sprawdza Composer, manifest zasobów, port i rzeczywisty
healthcheck. Korzysta z routera ograniczonego do `public/` oraz kreatora WWW.
Jego stan PID i konfiguracja adresu wymagają rozszerzenia do zarządzania w tle.

Docker ma własny katalog `.openwiki/docker/` i wolumen bazy. Kod jest montowany RW
z rzeczywistego katalogu skryptu, PHP pracuje w `/app`, a runtime używa osobnych
ścieżek konfiguracji i storage. Montowany jest katalog konfiguracji, co umożliwia
atomowy zapis `.env` przez kreator. Zależności PHP i natywne zależności Node mają
osobne wolumeny; wynik budowania zasobów trafia do używanego katalogu `public/assets`.
Node służy przygotowaniu, nie obsłudze żądań.

Weryfikacja wymaga odizolowanej kopii ze spacjami w ścieżce: kreator, logowanie,
strona, załącznik, ponowienie, restart, trwałość klucza i danych, dwustronny bind RW,
zmiana PHP, zmiana źródeł edytora, zajęty port, zarządzanie procesami i worker.
Kontrola lokalnej instancji obejmuje zachowanie jej konfiguracji i storage.
