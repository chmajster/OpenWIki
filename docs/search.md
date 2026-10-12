# Wyszukiwanie OpenWiki

UI `/search` i `GET /api/v1/search` używają tego samego serwisu, walidacji i kolejności
wyników. Nie ma limitu kandydatów przed ACL. Licznik obejmuje wszystkie dostępne
trafienia, a `page` i `per_page` (1–100, domyślnie 25) wybierają rzeczywistą stronę
tego zbioru. Kolejność: dokładny tytuł, początek tytułu, fragment tytułu, pozostałe
dopasowania; dalej data aktualizacji, typ, ID, wariant i numer rewizji. Przy stabilnych
danych strony wyników nie nakładają się. Zmiana treści między żądaniami może zmienić
kolejność; nie jest to zamrożony eksport zbioru.

## Dostęp i typy

Zachowane typy: `page`, `space`, `user`, `comment`, `attachment`, `tag`, `all`.
Filtry: przestrzeń, autor, tag, daty utworzenia/aktualizacji oraz `visibility`:

- `all`: publikacje i dostępna treść robocza;
- `published`: opublikowane dokumenty i ich powiązane wyniki;
- `working`: dostępne wersje robocze, załączniki i tagi.

Publikacja N i prywatny head N+1 są oddzielnymi wynikami, z `working`, `revision`
i stabilnym `result_key`. Publikacja ma własną datę rewizji. Czytelnik nie otrzymuje
tytułu, treści, tagów ani nowych wersji plików z N+1. Załączniki publikacji pochodzą
z zamrożonego snapshotu, włącznie z plikami później usuniętymi z wersji roboczej.
Identyczne wersje załącznika nie powielają wyników. Dawne publikacje bez workflow
zachowują zgodny odczyt bieżących plików. Szkice odzyskiwania edytora nie stanowią
samodzielnych dokumentów do globalnego wyszukiwania.

Liczniki tagów dotyczą różnych dostępnych stron; `working_page_count` wskazuje
strony z dostępnym tagiem roboczym. Link takiego tagu prowadzi do filtrowanego
wyszukiwania, obejmującego również wersje robocze. Komentarze zachowują dotychczasową
widoczność swojej strony. Katalog użytkowników jest dostępny po zalogowaniu; gość
nie otrzymuje tych wyników. API zachowuje wymaganie tokena `search:read` i `page.view`.
Spójność UI/API dotyczy tego samego użytkownika, zapytania i filtrów.

Każde wywołanie ocenia bieżące przestrzenie, grupy, role, allow/deny i dziedziczenie.
ACL i dane przodków są pobierane zbiorczo, wyłącznie jako metadane. Nie istnieje
cache wyników ani ACL między żądaniami. Ścieżka pomija niedostępnych przodków,
zachowując dostępne dziecko. Fragmenty tekstu do 260 znaków są pobierane dopiero
dla wyświetlanych wyników, wokół dopasowania, i escapowane przed renderowaniem.
Podpowiedzi używają tych samych kontroli, mają debounce, anulowanie starszych żądań
i obsługę strzałek, Enter oraz Escape bez kasowania zapytania. Błąd podpowiedzi
pozostawia działające wyszukiwanie formularzem.

## FULLTEXT i fallback

Indeks `ft_pages_search(title, content_text)` obsługuje publikacje i samodzielne
szkice. Migracja 012 dodaje `ft_revision_search(title, content_text)` dla prywatnych
headów. Rewizje historyczne nie stają się wynikami tylko dlatego, że zawiera je
fizyczny indeks. Snapshoty pozostają niezmienione.

Zwykłe słowa używają trybu boolean: wymagane są wszystkie słowa, z dopasowaniem
początku słowa. `router` i `router*` znajdują np. `router` i `routers`. Dopasowania
tytułów, przestrzeni, autorów i tagów uzupełniają FULLTEXT przez materializowaną
unię identyfikatorów. Unia zapobiega wielokrotnemu wykonaniu MATCH przez MariaDB
dla każdego wiersza pod warunkiem OR.

Krótkie słowa poza zakresem tokenów serwera, domyślne stopwords, nazwy techniczne
(`IP`, `C++`, `foo_bar`, `nginx.conf`), frazy w cudzysłowie i znaki specjalne używają
literalnego LIKE. `%`, `_` i `!` są escapowane, a `*` bez słowa jest znakiem
literalnym. Fallback szuka całego ciągu; cytowana fraza wymaga ciągłego dopasowania.
Własna lista stopwords serwera również wybiera fallback. Nie są wymagane uprawnienia
PROCESS ani dostęp do administracyjnych tabel InnoDB. Domyślną listę i zachowanie
tokenów opisują [MySQL](https://dev.mysql.com/doc/refman/8.4/en/fulltext-boolean.html)
i [MariaDB](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/optimization-and-indexes/full-text-indexes/full-text-index-overview).

## Przebudowa i wznowienie

Zwykły zapis, publikacja, import i restore aktualizują indeksy SQL automatycznie
w transakcji zapisu treści. Naprawa po starszym imporcie lub ręcznej korekcie danych:

```sh
php bin/console search:index
php bin/console search:status
php bin/console cron:run
# Alternatywnie pojedyncza partia albo oczekiwanie w konsoli:
php bin/console search:work
php bin/console search:index --wait
```

Administrator z `settings.manage` ma te same kontrolki i postęp w `/admin/system`.
Zadanie jest trwałe w `settings.search.rebuild`. Powtórzenie żądania dołącza do
aktywnego zadania. Worker blokuje jego wiersz i przetwarza do 200 stron oraz około
4 MiB źródła w partii; pojedynczy większy dokument jest przetwarzany osobno.
Zmiany tekstu i cursor są zatwierdzane razem. Awaria cofa całą partię. Stan `failed`
zachowuje ostatni zatwierdzony cursor; ponowne `search:index` wznawia pracę.

Wiersze stron są blokowane na czas partii, więc naprawa nie zastępuje równoległego
zapisu starszym tekstem. Historyczne snapshoty nie są przepisywane. Zadanie kończy
się na zapamiętanym maksymalnym ID; nowe poprawne zapisy są indeksowane normalnie.
Końcowy total uwzględnia faktycznie przeskanowane wiersze, również gdy inne operacje
trwale usunęły część zbioru w czasie przebudowy. Szczegół błędu trafia do logu PHP,
a panel pokazuje komunikat bez SQL i treści dokumentu.

## Pomiar i odtworzenie

[Raport pomiarów](search-benchmark.md) porównuje bazowe `97a8331` i nowy mechanizm.
Zbiór zawiera 10 000 stron o stałych tytułach, slugach i datach, około 5 KiB tekstu
każda, 220 początkowych ukrytych trafień oraz 100 dostępnych wyników `Firewall`.
Wymaga pustej, dedykowanej bazy `openwiki_search_*test` i zmiennych
`OPENWIKI_TEST_DB_HOST`, `PORT`, `DATABASE`, `USERNAME`, `PASSWORD`.

```sh
mkdir -p storage/temp/search-baseline
git archive 97a833147bb003d8b92ff95cb5675c78ce690ab5 | tar -x -C storage/temp/search-baseline
php tests/Search/benchmark.php --seed
php tests/Search/benchmark.php --baseline > storage/temp/search-before.json
php tests/Search/benchmark.php > storage/temp/search-after.json
python3 tests/Search/report.py storage/temp/search-before.json storage/temp/search-after.json
```

Harness wywołuje rzeczywisty kontroler API z tokenem i SQL, bez transportu HTTP.
Nie modyfikuje klucza, `.env` ani znacznika instalacji instancji; korzysta z własnego
tymczasowego runtime. CI wykonuje ten sam scenariusz na MySQL i publikuje surowe
pomiary jako artefakt `search-benchmark`. Testy przeglądarkowe niezależnie sprawdzają
pełny przepływ HTTP/formularza, desktop, telefon, klawiaturę, ACL i zgodność API.
