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
