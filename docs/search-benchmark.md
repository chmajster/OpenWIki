# Pomiar wyszukiwania na 10 000 stron

Pomiar wykonano 2026-10-12 na AMD Ryzen 7 7800X3D (16 logicznych CPU), w Dockerze
pod WSL, PHP 8.2.34 i MariaDB 11.4.13. Wersja bazowa: `97a8331`; nowy mechanizm:
PR #15. Oba przebiegi używały tej samej bazy, obrazu PHP i zestawu danych, kolejno,
bez równoległego uruchamiania własnych testów. Pozostałe usługi hosta działały
normalnie; pomiar nie jest gwarancją czasu odpowiedzi na innym serwerze.

Harness uruchamia kontroler API z rzeczywistym tokenem, autoryzacją i zapytaniami
SQL. Pomiar obejmuje jego wykonanie; transport HTTP jest wyłączony. Osobne testy
Playwright wykonują pełny przepływ HTTP. Każde zapytanie wykonano pięć razy;
podany czas jest medianą, a pamięć maksymalnym przyrostem szczytu w tych przebiegach.
Liczba SQL pochodzi z sesyjnego `Questions`, pomniejszonego o sam odczyt licznika.
Wyniki zawierają pierwsze wykonanie i jego koszt ładowania klas PHP.

| Zapytanie | Czas przed → po (ms) | SQL przed → po | Pamięć przed → po (MiB) | Total przed → po |
|---|---:|---:|---:|---:|
| `Firewall` | 666.50 → 47.17 | 606 → 17 | 4.47 → 1.90 | 0 → 100 |
| `needlecontext` | 515.70 → 43.98 | 306 → 17 | 1.78 → 0.22 | 98 → 98 |
| `IP` | 500.69 → 348.25 | 306 → 17 | 1.78 → 0.22 | 98 → 98 |
| `Zażółć` | 468.45 → 40.39 | 306 → 17 | 1.78 → 0.22 | 98 → 98 |
| `C++` | 484.08 → 340.51 | 306 → 16 | 1.78 → 0.22 | 98 → 98 |
| `foo_bar` | 476.35 → 321.62 | 306 → 16 | 1.78 → 0.22 | 98 → 98 |
| `nginx.conf` | 537.03 → 367.93 | 306 → 16 | 1.78 → 0.22 | 98 → 98 |
| `"gęślą jaźń"` | 293.90 → 331.66 | 6 → 16 | 0.04 → 0.22 | 0 → 98 |
| `router*` | 362.10 → 40.62 | 6 → 17 | 0.04 → 0.22 | 0 → 98 |
| `ordinary` | 826.39 → 158.12 | 606 → 17 | 3.51 → 18.03 | 0 → 9682 |

`Firewall` ma 220 ukrytych trafień przed 100 dostępnymi. Stary limit kandydatów
eliminuje wszystkie dostępne wyniki; nowy total obejmuje je wszystkie. Stabilna
paginacja została niezależnie sprawdzona dla 100 wyników w sześciu stronach.
Pozostałe słowa techniczne występują na 100 stronach, z których dwie są ukryte,
więc poprawny total wynosi 98. `ordinary` występuje na 9900 stronach; 218 jest
ukrytych. Nowy total 9682 jest kompletny. Stary sort nie miał rozstrzygnięcia
remisów po ID, więc zbiór obcięty do 200 kandydatów nie stanowił stabilnej paginacji.

Selektywne FULLTEXT ogranicza koszt treści i zapytań ACL. Literalny fallback
nadal skanuje tekst i jest wolniejszy niż selektywne FULLTEXT, ale obsługuje
krótkie nazwy, interpunkcję i frazy. Przed zmianą fraza i prefiks zwracały zero
wyników, dlatego ich mała pamięć i liczba SQL nie oznaczały poprawnego działania.

Szerokie zapytanie zużywa więcej pamięci: 18.03 MiB wobec 3.51 MiB, ponieważ nowy
mechanizm przechowuje metadane całego zbioru dopasowań i jego ACL, zamiast tylko
200 kandydatów. Pełne treści nie są przesyłane do PHP na liście. Koszt metadanych
rośnie z liczbą trafień; ten test weryfikuje korpus 10 000 stron, nie gwarantuje
stałej pamięci dla dowolnie dużego korpusu. Selektywne zapytania pobierają wyłącznie
metadane kandydatów i ich przodków, a fragmenty treści tylko dla 25 wyników.

Surowe przebiegi: [przed](benchmarks/search-before.json), [po](benchmarks/search-after.json).
[Procedura odtworzenia](search.md#pomiar-i-odtworzenie) używa tego samego kodu i
generatora co CI. CI publikuje własne pomiary na MySQL jako artefakt, bez twardego
progu czasowego zależnego od współdzielonego runnera.
