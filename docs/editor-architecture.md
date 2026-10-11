# Model edytora i trwałego zapisu

OpenWiki używa Tiptap/ProseMirror do operacji na dokumencie, zaznaczenia i historii undo/redo. Backend PHP pozostaje właścicielem autoryzacji, przetwarzania treści i rewizji. Node.js służy wyłącznie do kompilacji zasobów.

## Granice odpowiedzialności

- Dokument ma wersjonowaną kopertę źródłową: numer schematu, format oraz jedno źródło. HTML i tekst wyszukiwarki dla Markdown są pochodnymi. Zmiana formatu jest operacją konwersji z porównaniem i zachowaniem oryginału.
- Stan roboczy należy do konta, przestrzeni, dokumentu i kontekstu edycji. Wersja szkicu jest niezależna od bazowej rewizji strony.
- Autozapis utrwala niekompletną pracę. Nie modyfikuje artykułu, hierarchii, ACL, zdarzeń publikacji ani pełnych rewizji strony.
- Jawne zastosowanie zmian korzysta ze wspólnego PageWriteService. Kontrola bazowej wersji, metadane, audyt i zdarzenia pozostają w jednej transakcji.
- Identyfikator operacji rozróżnia ponowienie od kolejnego zapisu. Potwierdzenie dotyczy konkretnego stanu, a późniejsze zmiany pozostają oczekujące.
- Lokalne odzyskiwanie i serwerowe punkty odzyskiwania uzupełniają się. Brak dostępu do magazynu musi być widoczny; zamknięcie karty nie jest podstawowym mechanizmem zapisu.
- Pliki stanu roboczego są prywatne dla właściciela szkicu. Przeniesienie do załączników strony następuje przy jawnym zastosowaniu zmian, po kontroli uprawnień.

## Zgodność

Otwarcie starszej strony lub rewizji nie jest zapisem. Migracja dodaje wersję źródła bez zmiany treści historycznej. Edytor porównuje bezpieczny HTML z reprezentacją schematu i wymaga decyzji dla różnic strukturalnych.

Właściwości formatowania są kontrolowanymi atrybutami. Dowolny CSS, aktywny HTML oraz wykonywanie kodu dokumentacji są wykluczone. Mermaid przechowuje źródło; podgląd jest odizolowany od dokumentu aplikacji.

## Dokumentacja komponentów

- [Rozszerzenia i model Tiptap](https://tiptap.dev/docs/editor/core-concepts/extensions)
- [Tabele Tiptap](https://tiptap.dev/docs/editor/extensions/nodes/table)
- [Konfiguracja bezpieczeństwa Mermaid](https://mermaid.js.org/config/schema-docs/config.html)

Dobór rozszerzeń wykorzystuje istniejący model HTML, selekcje komórek i historię ProseMirror. Pozwala rozwijać narzędzia bez powrotu do ręcznej edycji DOM i execCommand.

## Źródło, projekcje i migracja

`pages.document_json` i `page_revisions.document_json` przechowują `{version: 1, format: "visual"|"markdown", source: string}`. Koperta jest jedynym źródłem. W Visual źródłem jest bezpieczny HTML reprezentujący wspierane węzły/atrybuty modelu ProseMirror; w Markdown oryginalny tekst. `content_html`, `content_markdown` i tekst wyszukiwarki pozostają projekcjami zgodnymi ze starszymi klientami. Pole `document` API ma pierwszeństwo nad przesłanymi projekcjami i jest ściśle walidowane. `ContentService::storedSource` nakłada źródło przy odczycie repozytorium bez zapisu do bazy.

Migracja `006_durable_editor` dodaje kopertę, osobną wersję szkicu oraz tabele potwierdzeń operacji, punktów odzyskiwania i prywatnych plików. Nie zmienia wcześniejszych kolumn ani zawartości rewizji. Dla starszego wpisu oznaczonego Markdown z brakującym/pustym źródłem i niepustym HTML wybiera Visual z istniejącym HTML, zachowując wcześniejsze kolumny bez zmian. To jawny adapter chroniący jedyną istniejącą treść. Test migracji sprawdza MySQL/MariaDB, ponowne uruchomienie, właściciela i oryginalną bazę starego autosave.

Odczyt stron i rewizji ponownie bezpiecznie przetwarza źródło; nie stosuje nowych limitów zapisu do historycznych danych. Nie utrwala zmian. Visual ocenia zgodność bezpiecznego HTML ze schematem przed dopuszczeniem transformacji. Alias znaczników, sekcje tabel, pojedyncze akapity komórek/list i nieistotne odstępy są równoważne; utrata struktury lub metadanych wymaga decyzji. Konwersje formatów przechowują oryginał jako `conversion_backup_json` w rewizji. Markdown podglądu, zapisu, importu i API przechodzi przez ten sam CommonMark/GFM z wyłączonym aktywnym HTML.

## Protokół szkicu i publikacji

Nowy dokument ma klucz `new:UUID` w URL. Istniejący ma `page:ID`. Każda karta otrzymuje osobny `editor_id`; zakres serwera to przestrzeń/użytkownik/dokument/kontekst. Lokalny klucz obejmuje dodatkowo identyfikator instancji. Konto nie może odzyskać lub wysłać kopii innego użytkownika.

`generation`/`draft_sequence` identyfikuje zmianę klienta, `draft_version` wersję CAS szkicu, a `base_version` opublikowaną rewizję. Przed wysyłką klient zamraża payload i UUID operacji. Dla jednego kontekstu jest najwyżej jedno żądanie; utrata odpowiedzi powoduje ponowienie identycznej operacji przed wysłaniem późniejszego stanu. Serwer blokuje wiersz użytkownika, sprawdza potwierdzenie i hash, a następnie CAS i monotoniczną sekwencję. Powtórzenie zwraca oryginalne potwierdzenie. Inna treść pod tym UUID jest błędem 422. Starsze potwierdzenie nie zmienia stanu późniejszych generacji.

Lokalne zapisanie co około 350 ms czeka na zakończenie transakcji IndexedDB. Małe kopie mają fallback localStorage. Warunkowe usunięcie kopii oraz sprawdzenie limitów i zapis plików/kopii odbywają się w pojedynczych transakcjach readwrite, także między instancjami/kartami. Brak magazynu nie blokuje dokumentu, lecz usuwa deklarację lokalnej ochrony.

Publikacja korzysta z osobnego UUID i PageWriteService, który współdzielą UI, API, import oraz restore. Transakcja serializuje wersję/hierarchię przestrzeni, waliduje uprawnienia i bazę, tworzy rewizję, materializuje pliki, synchronizuje metadane/backlinks i kolejkuje audyt/zdarzenia zgodnie z istniejącym modelem. Potwierdzenie operacji jest w tej samej transakcji. Nie tworzy kolejnej rewizji po utracie odpowiedzi. Przy ponowieniu sprawdzane są aktualne uprawnienia do zapisanej strony. Po potwierdzeniu nowej strony klient przełącza tożsamość i chronione URL plików przed wysyłką następnego szkicu. Pisanie w trakcie zwykłej publikacji pozostaje oczekujące w edytorze; ponowienie niejednoznacznej publikacji najpierw uzgadnia wynik, następnie jawnie zapisuje nadal aktualny stan.

409 nie zmienia oryginalnej bazy. Interfejs trzech wersji umożliwia oddzielny kontekst szkicu, przejęcie serwera albo przygotowanie scalenia. Dopiero świadoma decyzja ustawia nową bazę; późniejsza zmiana serwera powoduje kolejny konflikt. Blokada/presence jest informacyjna. Wznowienie po 401/403/419 lub powrocie sieci najpierw pobiera kontekst i aktualny token CSRF, bez automatycznego przepinania bazy. Konto zmienione w innej karcie blokuje wysyłkę.

## Endpointy i ACL

| Endpoint | Zadanie i zabezpieczenie |
| --- | --- |
| GET `/spaces/{key}/editor-drafts` | Szkice/punkty/kontekst, właściciel + aktualny ACL dokumentu/przestrzeni |
| POST `/spaces/{key}/editor-drafts` | Niekompletny szkic, konto edytora + ACL + CSRF + CAS |
| POST `/spaces/{key}/editor-preview` i `editor-convert` | Wspólny renderer/konwersja, ACL + CSRF |
| GET `/spaces/{key}/editor-pages` | Ograniczona wyszukiwarka stron z ACL każdej strony |
| GET `/spaces/{key}/editor-users` | ACL kontekstu + `user.view`; bez katalogu wyłącznie własny użytkownik |
| POST `/spaces/{key}/editor-files` | Prywatny plik, właściciel/kontekst + `attachment.upload` + CSRF + receipt/hash |
| GET `/editor-files/{id}/{mode}` | Właściciel + ACL kontekstu; po adopcji przekierowanie przez ACL załącznika |
| GET `/spaces/{key}/pages/{slug}/revisions/{number}` | Bezpieczny, niezmienny odczyt konkretnej rewizji, aktualny ACL strony |

Makra zachowują ACL odczytu; profil użytkownika dodatkowo wymaga `user.view` lub własnej nazwy. HTML-linki i składnia `[[...]]` zasilają backlinks. Pliki szkicu mają niejawne klucze fizycznego magazynu; sam URL lub identyfikator nie nadaje dostępu. Adopcja odnajduje rzeczywiste odnośniki/obrazy w DOM, a nie literalny tekst dokumentacji w bloku kodu. Nie można użyć pliku innego użytkownika/dokumentu.

## Bezpieczeństwo modelu i podglądu

Backend udostępnia frontendowi wspólną allowlistę znaczników/atrybutów i kontrolowanych wartości. Rozszerzenia nie zapisują dowolnego CSS. Wklejanie bezpiecznie przetwarza strukturę przed transakcją dokumentu, backend przetwarza ją ponownie. Bardzo duże/głębokie wklejanie jest odrzucane przed zastąpieniem zaznaczenia. Podświetlanie highlight.js, numery linii i kontrolki pozostają widokiem, nie źródłem.

Mermaid 11.17.2 ładuje się tylko w statycznym `diagram.html`, w iframe `sandbox="allow-scripts"` bez uprawnień same-origin. CSP blokuje połączenia, obiekty, formularze i zewnętrzne zasoby. Renderowanie odrzuca HTML, dyrektywy konfiguracji/frontmatter, `click`, protokoły/odwołania zewnętrzne oraz zbyt duże grafy. Wynik SVG jest dodatkowo czyszczony i wyświetlany jako obraz danych wewnątrz izolowanej ramki. Wiadomości sprawdzają okno nadawcy i sekwencję. Błąd pozostawia źródło. Zależność KaTeX jest przypięta do 0.18.2 przez override; `npm audit` jest sprawdzany podczas weryfikacji.

## Limity i retencja

| Zasób | Limit |
| --- | --- |
| Źródło zapisywanego dokumentu | 2 000 000 znaków |
| Struktura zapisywanego HTML | 100 poziomów / 50 000 elementów |
| Zakodowany payload szkicu | 12 000 000 bajtów |
| Aktywne szkice konta na serwerze | 100 |
| Punkty odzyskiwania | 20 na kontekst; lista 20 najnowszych dla dokumentu |
| Lokalne kopie IndexedDB / fallback | 100 w każdym magazynie przeglądarki |
| Plik | 50 MiB, dodatkowe istniejące limity/polityki AttachmentService |
| Lokalna kolejka / prywatne pliki konta | 50 plików / 100 MiB |
| Wklejenie arkusza | 50 wierszy / 30 kolumn / 200 000 znaków |
| Mermaid | 20 000 znaków / 500 krawędzi |

Lokalne kopie i kolejki wygasają po 30 dniach. `cron:run` czyści szkice/punkty/receipts starsze niż 30 dni oraz nieużywane prywatne pliki. Sprzątanie plików ponownie blokuje/sprawdza rekord, chroni adoptowane załączniki i wszystkie ich wersje, nie podąża za symlinkami i sprawdza referencje osieroconych bajtów partiami po 500. Aktywny szkic aktualizuje użycie rzeczywistych odnośników do plików. Limit aktywnych kontekstów nie obejmuje już potwierdzonych szkiców. Obecność odpytywana co 30 s pomija ukryte karty, offline, zatrzymaną autoryzację i trwający zapis.

## Build, CI i wydanie

`npm ci && npm run build` wymaga Node.js 22+. Linuxowy `scripts/build-editor.sh` może pobrać oficjalne binaria 22.23.3 x64/arm64 z nodejs.org i zweryfikować przypięty SHA-256; narzędzie pozostaje w prywatnym katalogu tymczasowym i jest usuwane po zakończeniu. Nie zmienia globalnej instalacji Node.js. Wersje Node są opisane w [dokumentacji wydań](https://nodejs.org/en/about/previous-releases), a sumy w [oficjalnym manifeście](https://nodejs.org/dist/v22.23.3/SHASUMS256.txt).

Build tworzy trzy ignorowane bundle: `wiki-editor.js`, `document-reader.js`, `diagram.js`. Statyczny `diagram.html` jest źródłem. CI sprawdza PHP/JS/Bash/Composer, cały PHPUnit i prawdziwe formularze Playwright, archiwum runtime oraz świeżą instalację/naprawę/odinstalowanie. Osobny krok wykonuje rzeczywisty build przypiętym narzędziem. Release dla tagu `v*` pakuje vendor bez dev, wszystkie cztery zasoby i aplikację PHP, bez node_modules, .env i testów. Test paczki uruchamia renderer PHP bez procesu Node.js. Instalator korzysta z gotowych zasobów kompletnej paczki albo buduje checkout źródeł.

## Weryfikacja wydajności i obrazu

`tests/Browser/editor-performance.spec.cjs` używa rzeczywistego dokumentu 107 056 znaków z tabelą, kodem PHP i chronionym obrazem PNG, trzech wejść do edytora, pisania, Ctrl+S, zastosowania zmian i dokładnego odczytu API. Pomiar event→requestAnimationFrame ocenia czas do kolejnej klatki, a nie laboratoryjne opóźnienie sprzętowej klawiatury.

W wykonanym lokalnym pełnym przebiegu: Windows 11 (10.0.26200), Ryzen 7 7800X3D, Node.js 24.21.0, Chromium 156.0.8078.4, PHP 8.2.12 i izolowana MariaDB. Wejścia do edytora: 290/240/244 ms (mediana 244 ms); input→klatka: mediana 9 ms, maksimum 15 ms; zapis szkicu: 121 ms; zastosowanie i ponowny odczyt: 280 ms. Wyniki zależą od hosta i są pomiarami tego przebiegu, nie gwarancją dla każdej instalacji.

CI publikuje aktualne surowe pomiary w `editor-performance.json` oraz zrzuty w artefakcie `editor-verification` (7 dni). Zrzuty obejmują edytor/odczyt przy 360/768/1440 px, duży dokument, offline, błąd uprawnień i porównanie odzyskiwania. Pliki generowane nie trafiają do repozytorium. Testy używają realnego PHP, bazy, logowania i formularza; fault injection celowo zrywa odpowiedzi lub podstawia błędy HTTP, bez zastępowania właściwych operacji domenowych atrapą.
