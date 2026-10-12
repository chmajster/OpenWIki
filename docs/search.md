# Wyszukiwanie OpenWiki

## Cel i plan weryfikacji

Wyszukiwanie ma obejmować wszystkie dostępne trafienia, ze wspólną semantyką UI i API,
stabilną paginacją oraz bez ujawniania prywatnych dokumentów przez liczniki i podpowiedzi.

Analiza wersji bazowej `97a8331` wykazała ograniczenia SQL przed ACL (150 wyników
w UI, 200 w API), ponowne obcinanie listy, pobieranie pełnego tekstu i powtarzanie
zapytań ACL. Istniejący indeks FULLTEXT nie jest używany. Przebudowa indeksu ładuje
cały zbiór stron do pamięci. Treść opublikowana i robocza mają oddzielne rewizje;
wyszukiwanie musi zachować tę granicę dostępu.

Weryfikacja obejmie wszystkie typy wyników, zmianę ACL, polskie i techniczne nazwy,
rzeczywisty formularz w przeglądarce oraz pomiary czasu, zapytań i pamięci na
powtarzalnym zbiorze około 10 000 stron. Wyniki pomiarów zostaną zapisane po
wykonaniu tego samego scenariusza dla wersji bazowej i implementacji.
