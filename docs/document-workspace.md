# Środowisko tworzenia dokumentacji

## Założenia

Ekran tworzenia i edycji strony korzysta z istniejącego Tiptap/ProseMirror,
koperty dokumentu, prywatnych szkiców i transakcyjnego zapisu PHP. Przebudowa
interfejsu nie zmienia znaczenia wersji bazowej ani potwierdzeń autozapisu.

Układ dokumentu obejmuje pasek działań, zwijane drzewo przestrzeni, tytuł,
kompaktowe narzędzia oraz przeglądarkę szablonów. Publikacja wymaga osobnego
potwierdzenia lokalizacji i dostępu; zamknięcie edytora zachowuje prywatny szkic.

## Źródła projektu interakcji

- [Tworzenie i publikowanie](https://support.atlassian.com/confluence-cloud/docs/create-edit-and-publish-a-page/)
- [Narzędzia formatowania](https://support.atlassian.com/confluence-cloud/docs/add-formatting-to-your-page/)
- [Przeglądarka szablonów](https://support.atlassian.com/confluence-cloud/docs/create-a-page-from-a-template/)

Materiały Atlassian opisują pływający pasek zaznaczenia, przypinany toolbar,
szablony po prawej i okno publikacji. Implementacja używa nazw, palety i ikon
OpenWiki oraz zachowuje jego polityki ACL, recenzji i bezpiecznej treści.

## Implementacja i wdrożenie

Pozostaje istniejący Tiptap/ProseMirror. Wspólny model HTML i koperty dokumentu
pozwala zachować stare strony, załączniki, makra i historię bez drugiego formatu
lub biblioteki edytora. Wszystkie operacje bloków korzystają z komend/transakcji
i undo/redo. DOM paneli i podglądu jest oddzielony od źródła dokumentu.

| Obszar | Pliki |
| --- | --- |
| Workspace i kompaktowe kontrolki | `resources/views/pages/editor.php`, `resources/views/partials/editor-header.php`, `editor-icons.php`, `layouts/app.php`, `public/assets/document-workspace.css` |
| Panele, tytuł, szablony, publikacja i podgląd | `resources/js/document-workspace.js`, `wiki-editor.js`, `workspace-tools.js`, `rich-editor.js` |
| Drzewo i chronione media | `resources/js/documentation-tree.js`, `upload-queue.js`, `app/Spaces/DocumentationTreeService.php` |
| Wspólny widok odczytu/podglądu | `resources/js/reader-format.js`, `document-reader.js`, `public/assets/app.css` |
| Autoryzacja i zapis | `app/Wiki/PageWorkspaceService.php`, `PageWriteService.php`, `PageTemplateService.php`, `app/Http/Controllers/PageController.php`, `PageDraftController.php`, `routes/web.php` |
| Weryfikacja i paczka | `tests/Browser/document-workspace.spec.cjs`, `workspace-helpers.cjs`, istniejące specyfikacje edytora, `tests/Integration/PageSavingTest.php`, `.github/workflows/ci.yml` |

Zmiana nie dodaje migracji schematu. Szablony startowe uzupełniają katalog;
istniejące szablony oraz snapshoty rewizji zachowują treść. Emoji jest tekstem
tytułu, obraz nagłówkowy używa istniejącego figure i kontrolowanej klasy.
Nowy dokument pozostaje prywatnym szkicem do potwierdzenia. Wybór innej
przestrzeni nie zmienia źródłowej tożsamości odzyskiwania ani prywatnych plików.
Zapis serializuje obie przestrzenie i sprawdza ACL ponownie, zachowując receipt
operacji i potwierdzając tylko wysłaną generację. Późniejsze zmiany nadal
wymagają zapisu. Podgląd makr używa autoryzowanego kontekstu wybranej przestrzeni.
Lista lokalizacji publikacji pobiera reguły edycji zbiorczo, a wspólna polityka
przestrzeni rozstrzyga allow/deny, role, grupy, własność i archiwum. Liczba zapytań
ACL tego wybieraka nie rośnie z liczbą przestrzeni. Zapis ponownie sprawdza dostęp.

W checkout źródłowym uruchom `npm ci && npm run build`. Paczka wydania zawiera
zbudowane bundle i CSS workspace. PHP 8.2+ obsługuje wszystkie żądania bez procesu
Node.js. Instalator i release zachowują dotychczasową obsługę gotowych zasobów.

## Weryfikacja wizualna

Zrzuty Playwright znajdują się w `storage/temp/workspace-{szerokość}x{wysokość}-{light,dark,publication}.png`.
Obejmują 1920×1080, 1440×900 i 390×844. CI publikuje je jako artefakt
`editor-verification` na siedem dni. Zrzuty nie trafiają do źródeł aplikacji.

Porównanie z materiałami Atlassian obejmuje pełnoekranowy pasek, szeroki obszar
pisania z tytułem, narzędzia bez ramek formularza, szablony po prawej, drzewo po
lewej i okno publikacji. Po oględzinach poprawiono kontrast jasnego motywu,
odstępy kontrolek, miniatury i przyciski szablonów, położenie uchwytów bloku,
menu przy dolnej krawędzi oraz układ telefonu. Telefon ma ukrywane panele,
zawijany toolbar i przewijane okno potwierdzenia bez poziomego przewijania strony.

## Testy i pomiar

`vendor/bin/phpunit` obejmuje autoryzację, bazową wersję, receipt, historię,
adopcję plików i dokładne potwierdzanie szkiców. Nowe scenariusze w
`PageSavingTest` sprawdzają publikację między przestrzeniami, podgląd makr,
zachowanie źródeł szablonów, ukrytych rodziców i odmowę sfałszowanej lokalizacji.
Na Windows dwa testy systemowe są pomijane (kontrola PID na Linux oraz tworzenie
symlinku); aktualny pełny wynik PHPUnit jest zapisany w PR i jego checks.
Końcowe trzy scenariusze podglądu/zapisu dodatkowo zaliczają 36 asercji.
Linuxowe testy instalacji, aktualizacji MySQL/MariaDB i runtime są osobnymi
zadaniami CI.

`npx playwright test` obsługuje rzeczywiste formularze i PHP/MySQL. Nowy plik
`document-workspace.spec.cjs` sprawdza zamknięcie/odzyskanie szkicu bez utworzenia
artykułu, osiem szablonów i ponowne otwarcie, podgląd i powrót do zastąpionej
treści, `/` i `+`, operacje bloków z undo, podstronę z drzewa, chroniony obraz
oraz cel publikacji. Dotychczasowe specyfikacje nadal obejmują konflikty,
niejednoznaczne odpowiedzi, błędy sieci i HTTP, wiele kart/kont, metadane,
backlinks, tabele, kod, makra, pliki, recenzję, ACL i telefon. Po zmianie UI
testy otwierają i zatwierdzają okno publikacji zamiast omijać go skryptem DOM.
Ślady sieci i zrzuty są artefaktami CI. Wynik końcowego przebiegu jest zapisany
w [PR #17](https://github.com/chmajster/OpenWIki/pull/17) i jego checks.

Przeciąganie uchwytu jest sprawdzane rzeczywistymi zdarzeniami myszy, z kontrolą
undo/redo i kolejności po publikacji. Zaznaczenie całego bloku nie uruchamia
paska tekstowego zasłaniającego jego menu.

Pomiar `editor-performance.spec.cjs` na Windows 11, Ryzen 7 7800X3D,
Node 24.21.0 i Chromium 156.0.8078.4 dotyczył źródła 107 054 znaków z tabelą,
kodem i chronionym PNG. Zestaw PHPUnit równolegle używał osobnej bazy testowej.

| Pomiar | Wynik |
| --- | --- |
| Trzy otwarcia edytora | 1537 / 3092 / 3228 ms |
| Mediana input → następna klatka | 11 ms |
| Najdłuższy pomiar input → klatka | 18 ms |
| Zapis prywatnego szkicu | 1584 ms |
| Potwierdzenie i odczyt strony | 5654 ms |

To pomiar rzeczywistego scenariusza na tym stanowisku, obejmujący lokalny PHP
i kontener MySQL; nie jest gwarancją opóźnienia produkcyjnego. Dane JSON są
w `storage/temp/editor-performance.json`. `npm run build`, `npm run check:js`
i walidacja Composer uzupełniają testy formularza. Paczka runtime była
rozpakowana i sprawdzona rendererem PHP oraz kontrolą obecności bundle/CSS,
bez `node_modules`, `.env` ani procesu Node obsługującego żądania.
