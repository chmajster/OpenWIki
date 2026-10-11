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
