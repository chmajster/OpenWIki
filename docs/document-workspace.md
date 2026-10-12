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
