# Aktualizacje istniejącej instalacji

Aktualizacja jest oddzielną operacją od instalacji, diagnostyki i naprawy konfiguracji. Wskazana wersja Git zostaje przypięta do konkretnego SHA. Przygotowanie kodu i zależności odbywa się w osobnym katalogu wydania; konfiguracja i storage należą do instancji, a nie do wydania.

Warunki powodzenia obejmują sprawdzenie wymagań, wyłączną blokadę aktualizacji, zweryfikowaną kopię odzyskiwania, zatrzymanie zapisów HTTP i workera, wykonanie `php bin/console migrate`, przełączenie wydania oraz gotowość bez oczekujących migracji. Wznowienie korzysta z trwałego dziennika etapów i tego samego przypiętego SHA.

DDL w MySQL/MariaDB zatwierdza transakcje niezależnie od aplikacji. Migrator musi używać blokady połączenia, trwałego dziennika kroków i jawnych reguł ponowienia; samo opakowanie DDL w transakcję nie zapewnia atomowości.

Cofnięcie kodu jest dopuszczalne tylko wtedy, gdy manifest poprzedniego wydania akceptuje bieżący schemat. Dla niezgodnego schematu potrzebne jest kontrolowane odtworzenie zweryfikowanej kopii bazy, konfiguracji i plików przy zablokowanych zapisach. Kopie odzyskiwania zawierają sekrety i wymagają ochrony przed dostępem HTTP oraz kontami aplikacji.

## Polecenia i wyniki

```bash
sudo bash install.sh --install --install-dir /var/www/openwiki
sudo bash install.sh --diagnose --install-dir /var/www/openwiki
sudo bash install.sh --repair --install-dir /var/www/openwiki
sudo bash install.sh --upgrade WERSJA_GIT --install-dir /var/www/openwiki
sudo bash install.sh --upgrade-status --install-dir /var/www/openwiki
sudo bash install.sh --resume --install-dir /var/www/openwiki
```

`WERSJA_GIT` jest istniejącym tagiem, gałęzią lub pełnym SHA w oficjalnym repozytorium. Najlepiej wybrać konkretny tag albo SHA. Gałąź jest rozwiązywana tylko raz; wznowienie korzysta z zapisanego SHA, nawet jeśli gałąź później się zmieni. Kod powrotu 0 oznacza ukończenie operacji. Upgrade zwraca `upgraded` albo `unchanged` w statusie. Ponowienie tej samej wersji sprawdza gotowość i zachowuje poprzednią kopię odzyskiwania. Błąd etapu, niezgodny schemat i równoległa aktualizacja zwracają kod różny od 0.

`--install` odmawia zmiany katalogu z `.env` lub znacznikiem instalacji. `--repair` poprawia konfigurację zarządzanych usług i uprawnienia, sprawdza gotowość, zachowuje kod oraz klucz i nie wykonuje migracji. `--diagnose`/`--status` są odczytem statusu usług, bazy, storage, schematu i aktualizacji. Brak gotowości daje niezerowy wynik. Tryb `--local` pozostaje osobnym serwerem developerskim.

## Pierwszy upgrade ze starego instalatora

Starszy `install.sh` nie zna nowych poleceń. Pobierz pełną, zaufaną kopię nowego wydania/checkoutu do osobnego katalogu i uruchom znajdujący się w niej instalator ze wskazaniem istniejącej instancji. Nie kopiuj nowego kodu ręcznie do działającej aplikacji.

```bash
git clone https://github.com/chmajster/OpenWIki.git /opt/openwiki-updater
sudo bash /opt/openwiki-updater/install.sh --upgrade WERSJA_GIT --install-dir /var/www/openwiki
```

Aktualizator wymaga Linuxa, PHP 8.2+ z rozszerzeniami aplikacji, Git, Composer, tar, flock i sha256sum oraz zarządzanych przez instalator Nginx/PHP-FPM/crona. Budowanie zasobów używa Node.js 22+ albo istniejącego skryptu pobierającego przypięty toolchain. Node.js nie obsługuje żądań aplikacji. Baza musi zawierać tabele InnoDB, bez widoków. `.env` i `storage` instancji mogą być plikami/katalogami lub mountami; zewnętrzne dowiązania są odrzucane przed zmianą danych. Dowiązania w zawartości storage są odrzucane podczas backupu.

Po pierwszym przełączeniu `install.sh` instancji korzysta z zachowanej kopii narzędzi odzyskiwania, a `php bin/console` deleguje do aktywnego wydania. Narzędzia pozostają dostępne również po restore starszego kodu. Przed kolejnym upgrade do wydania zmieniającego protokół aktualizacji użyj aktualizatora z tego wydania.

## Etapy i kontrola zapisów

1. Preflight sprawdza pierwotną konfigurację, APP_KEY, bazę, rozszerzenia, storage i zapas miejsca na backup oraz wydanie.
2. Blokada `.openwiki/upgrade.lock` odrzuca drugi proces. Ref zostaje przypięty do SHA w `.openwiki/status.json`.
3. Maintenance blokuje ruch OpenWiki w Nginx. Cron OpenWiki jest wstrzymywany, PHP-FPM zatrzymywany, a istniejący worker ma czas na zakończenie. Nowe wydania dodatkowo trzymają współdzieloną blokadę przez całe żądanie/operację CLI; aktualizator czeka na blokadę wyłączną.
4. Tworzona jest prywatna kopia całej bazy, dokładnego `.env` z APP_KEY i storage ze znacznikiem instalacji. Zwykłe archiwa `storage/backups` pozostają osobno. Każdy plik ma rozmiar i SHA-256 w manifeście; zamknięte archiwum jest ponownie odczytywane i weryfikowane, a jego hash zapisywany w dzienniku.
5. Kod, zależności Composer i zasoby są przygotowywane w `.openwiki/releases/SHA`. Manifest integralności obejmuje kod, vendor i zbudowane zasoby. `.env` i storage wydania wskazują na zachowane dane instancji. Poprzednie faktycznie wdrożone pliki są zachowywane jako wydanie odzyskiwania.
6. Uruchamiane jest `php bin/console migrate` przygotowanego wydania. Wymagana jest zgodność schematu bez oczekujących, nieznanych lub niedokończonych migracji.
7. `current` jest przełączany atomowo. Nginx używa rzeczywistej ścieżki wydania, co zapobiega obsługiwaniu starego kodu przez cache PHP. Aktualizowane są tylko ścieżka aplikacji i dyrektywy maintenance; istniejące ustawienia TLS, nagłówki, limity i harmonogram crona pozostają zachowane.
8. Gotowość jest sprawdzana przed otwarciem ruchu, a następnie przez HTTP. `cron:run` musi zakończyć się powodzeniem jako użytkownik runtime. Dopiero potem status staje się `completed`.

Maintenance zaczyna się przed backupem, aby baza i pliki przedstawiały stan bez równoległych zapisów. Trwa również podczas przygotowania zależności. Zatrzymanie domyślnej puli PHP-FPM może chwilowo dotyczyć innych aplikacji korzystających z tej puli. W razie błędu pula jest uruchamiana ponownie, ale OpenWiki pozostaje zablokowane przez Nginx i swój znacznik maintenance. Workery uruchamiane poza zarządzanym cronem powinny korzystać z `php /ścieżka/instancji/bin/console cron:run`, aby uczestniczyły w blokadzie.

## Diagnostyka i wznowienie

Status pokazuje operację, przypięty SHA, ukończone etapy i błąd. Szczegóły znajdują się w prywatnym `.openwiki/logs/ID.log` (0600, katalog 0700). Status w `/admin/system` nie pokazuje sekretów ani treści logu. Przerwanie sygnałem zapisuje błąd; po zabiciu procesu lub restarcie hosta pozostały status `running` także można wznowić.

Usuń przyczynę błędu, np. przywróć sieć, wymagane rozszerzenie, miejsce na dysku lub działanie zależności, następnie wykonaj `--resume`. Nie edytuj przypiętego SHA ani rozpoczętych planów migracji. Zweryfikowany backup nie jest zastępowany. Nieukończone archiwum bez potwierdzenia jest zachowywane z przyrostkiem `.incomplete-*`, a backup wykonywany ponownie przed jakąkolwiek migracją. Przygotowane wydanie jest sprawdzane przez jego manifest. Wznowienie po przełączeniu ponownie sprawdza migracje i gotowość. Wznowienie restore kontynuuje restore.

`GET /health/live` sprawdza proces PHP. `GET /health` i `php bin/console readiness` sprawdzają dostępność bazy i storage oraz zgodność schematu. Dostępna baza z oczekującą migracją nie oznacza gotowej aplikacji. Publiczne odpowiedzi nie zawierają danych połączenia. Podczas maintenance nowe wydanie nadal udostępnia te odczyty diagnostyczne.

## Migracje MySQL/MariaDB

Migrator używa nazwanego `GET_LOCK` powiązanego z bazą, zwalnianego po zakończeniu lub utracie połączenia. Nie wolno uruchamiać go wewnątrz istniejącej transakcji. `schema_migration_steps` przechowuje wersję, indeks kroku, hash planu i stan; `schema_migrations` pozostaje listą ukończonych wersji.

Kroki DDL dodające kolumny/indeksy mają postwarunki w `information_schema`. Po przerwaniu pomiędzy zatwierdzonym DDL a potwierdzeniem kroku migrator rozpoznaje istniejący, zgodny obiekt. Niezgodny obiekt blokuje dalszą pracę. DML i jego potwierdzenie są zatwierdzane razem. Niepewny, niepowtarzalny DDL bez postwarunku jest blokowany zamiast wykonywany ponownie. Utrata potwierdzenia całej wersji nie powoduje powtarzania ukończonych kroków.

Nowa migracja powinna zawierać jawne postwarunki DDL oraz test przerwania. Nie zmieniaj planu już rozpoczętej migracji. Renderowanie i odczyt statusu nie tworzą tabel ani nie uruchamiają migracji.

## Cofnięcie i restore

```bash
sudo bash install.sh --rollback --install-dir /var/www/openwiki
sudo bash install.sh --restore NAZWA_BAZY --install-dir /var/www/openwiki
```

Rollback sprawdza wymagane migracje poprzedniego kodu, wszystkie dotknięte kroki i jawne `compatible_extra_migrations` z `release.json`. Domyślnie dodatkowa migracja jest niezgodna, również gdy DDL wykonano, ale nie zapisano jeszcze wersji. Deklarowanie zgodności wymaga sprawdzenia odczytu, zapisu i workera na danym schemacie. Odmowa rollback nie zmienia działającego kodu ani danych.

Restore wymaga dokładnej nazwy bazy instancji i zweryfikowanego archiwum tej aktualizacji. Przy zatrzymanych zapisach najpierw powstaje dodatkowa kopia stanu sprzed restore (`ID-before-restore.tar`), następnie przywracana jest cała dedykowana baza OpenWiki, konfiguracja i pliki ze snapshotu. Niezarejestrowane późniejsze pliki nie są kasowane; późniejsze dane są również zachowane w dodatkowym archiwum. Po sprawdzeniu zgodności przełączany jest poprzedni kod i weryfikowana gotowość oraz worker. Operację można wznowić po przerwaniu. Nie usuwa ona automatycznie archiwów ani starych wydań.

Kopie `.openwiki/backups` zawierają hasła, klucze i prywatne dane. Pozostają dostępne tylko administratorowi hosta i nie są widoczne w mechanizmie pobierania zwykłych backupów. Zachowaj je poza hostem zgodnie z polityką backupów instancji. Ręczne cofnięcie checkoutu po niezgodnej migracji omija tę procedurę i nie jest bezpiecznym rollbackiem.

## Weryfikacja

CI wykonuje upgrade schematu 004 z prawdziwymi kontami, uprawnieniami, stroną, rewizją, szkicem i binarnym załącznikiem na MySQL 8 i MariaDB 11.4. Sprawdza błędy pobierania, zależności i DDL, równoległe aktualizacje, oczekiwanie na żądanie, wznowienie po przełączeniu, no-op, odmowę rollback i restore. Formularze logowania i zapisu strony, istniejący token API, chroniony załącznik oraz worker są wykonywane rzeczywiście. Osobny job aktualizuje historyczny kod OpenWiki ze schematem 004 przez zarządzane Nginx/PHP-FPM i sprawdza zachowanie danych i klucza.
