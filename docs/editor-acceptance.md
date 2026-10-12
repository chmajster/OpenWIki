# Weryfikacja edytora dokumentacji

Edytor korzysta z Tiptap/ProseMirror, a źródło strony i protokół szkicu opisuje
[model zapisu](editor-architecture.md). Rozbudowa narzędzi musi zachować ten protokół:
autozapis utrwala prywatną pracę, a zastosowanie zmian tworzy rewizję przez
wspólny `PageWriteService`.

## Przepływy wymagające sprawdzenia

- Utworzenie dokumentu, użycie narzędzi Visual, zastosowanie zmian i ponowne
  otwarcie: nagłówki, tabele, listy zagnieżdżone, checklisty, kod, callouty i linki.
- Wklejenie HTML z zewnętrznego dokumentu: zachowanie dozwolonej struktury,
  usunięcie aktywnej treści i kontrola metadanych obrazów.
- Wyszukanie strony i wybór makra: aktualne uprawnienia, parametry i działający
  odczyt po zapisie. Podgląd Markdown przechodzi przez renderer PHP używany przy
  zapisie.
- Obsługa klawiatury: zaznaczenie i fokus po użyciu narzędzia, undo/redo,
  zamykanie dialogów i zapis szkicu. Ten sam formularz działa na telefonie.
- Odczyt starych stron i rewizji: bez zmiany snapshotu; przekształcenia poza
  wspieranym modelem wymagają porównania i decyzji użytkownika.
- Błąd zapisu, konflikt i pisanie podczas autozapisu: odzyskiwalna treść oraz
  niezmieniona wersja bazowa do czasu świadomego rozwiązania konfliktu.
- Wydanie: statyczne zasoby zbudowane w CI, gotowa paczka obsługiwana przez PHP
  bez procesu Node.js obsługującego żądania.

## Testy

Testy w `tests/Browser` uruchamiają rzeczywisty formularz, PHP i MySQL/MariaDB.
`editor.spec.cjs`, `advanced-editor.spec.cjs` i `durable-editor.spec.cjs`
sprawdzają narzędzia, zgodność i odzyskiwanie. `editor-performance.spec.cjs`
zapisuje pomiary oraz obrazy dla widoków desktopowych i mobilnych.
Testy PHP weryfikują niezależnie sanitizer, renderer, konwersję, rewizje,
uprawnienia i wspólne skutki domenowe zapisu.

```bash
npm ci
npm run check:js
npm run build
vendor/bin/phpunit
npm run test:browser
```

Testy integracyjne i przeglądarkowe wymagają osobnej bazy wskazanej przez
`OPENWIKI_TEST_DB_*`. Nie należy uruchamiać ich jednocześnie: przygotowanie
fixture zmienia tymczasowo konfigurację tej samej aplikacji.
