# Edytor dokumentacji OpenWiki

## Szkic i strona

Autozapis jest domyślnie włączony. Zapisuje treść i metadane po 2 sekundach bezczynności, najpóźniej po około 10 sekundach ciągłego pisania. Nie wymaga gotowego tytułu ani poprawnego docelowego slugu. Nowa strona ma stabilny identyfikator w adresie; zachowaj ten adres, aby wrócić do tego samego dokumentu.

**Ctrl/Cmd+S** oraz „Zapisz szkic teraz” natychmiast utrwalają prywatną pracę. Czytelnicy nadal widzą ostatnio zastosowaną wersję strony. „Utwórz stronę ze szkicu” lub „Zastosuj zmiany do strony” zapisuje aktualny dokument jako stronę i tworzy rewizję. Wybrany stan „Opublikowana” wymaga prawa publikacji. Metadane — tytuł, adres, rodzic, stan, tagi i opis zmiany — znajdują się w zwijanym panelu.

W panelu zapisu rozróżniane są niezapisane zmiany, zapisywanie, potwierdzenie serwera z czasem, konflikt, brak połączenia i błąd. Osobny komunikat informuje, czy najnowsza kopia została rzeczywiście zapisana lokalnie. Stan wcześniejszego żądania nie potwierdza późniejszych zmian. Zapis zachowuje kursor, zaznaczenie i przewinięcie.

## Odzyskiwanie i konflikty

Po ponownym otwarciu edytor proponuje dostępne kopie, z datą i wersją bazową. Wybierz kopię lub punkt odzyskiwania, porównaj go z dokumentem i użyj „Odtwórz”. Punkty pozwalają odzyskać treść sprzed usunięcia, także po automatycznym zapisaniu pustego dokumentu.

Bez sieci można dalej pisać. Lokalna kopia powstaje po około 350 ms; po powrocie połączenia edytor sprawdza konto i stan serwera przed wysłaniem szkicu. Gdy sesja wygasła, zaloguj się w osobnej karcie i użyj „Sprawdź konto i wznów zapis”. Po utracie uprawnień praca pozostaje lokalnie, lecz serwer nadal odmawia zapisu. Zmiana konta zatrzymuje wysyłkę starej karty. Kopie poprzedniego konta nie są proponowane nowemu użytkownikowi.

Konflikt pokazuje wersję bazową, własną i aktualną na serwerze. Możesz:

- zachować własną pracę jako osobny szkic z oryginalną bazą;
- przejąć wersję serwera, zachowując własną kopię do odzyskania;
- świadomie przygotować scalenie względem pokazanej wersji, edytować wynik i ponownie zastosować zmiany.

Samo ponowienie zapisu nie zmienia bazy. Jeżeli inna osoba zapisze kolejną wersję podczas scalania, pojawi się następny konflikt. Sygnalizacja obecności innych edytorów uzupełnia tę kontrolę.

Kopie i nieużywane pliki szkicu mają retencję 30 dni. Serwer zachowuje 20 najnowszych punktów na kontekst edycji. To mechanizm odzyskiwania bieżącej pracy; historia zastosowanych rewizji pozostaje dostępna osobno.

## Narzędzia dokumentu

Pasek obsługuje akapity, H1–H6, podstawowe formatowanie, indeksy, kontrolowaną paletę kolorów, wyróżnienie i wyrównanie. Listy mogą być zagnieżdżone; checklisty mają edytowalny stan. Wybierz cytat, separator, blok informacji/uwagi/ostrzeżenia/sukcesu, zwijaną sekcję lub dwie/trzy kolumny. Na telefonie kolumny układają się kolejno.

Wpisz `/`, aby szukać polecenia; strzałki i Enter wybierają wynik, Escape zamyka menu. `@` proponuje dostępnych użytkowników. Bez uprawnienia do katalogu użytkowników podpowiedź obejmuje tylko własne konto. Wybierak odnośników pokazuje wyłącznie dostępne strony. Makra mają formularz parametrów i korzystają z dotychczasowych kontroli dostępu przy odczycie.

Kontekstowe narzędzia zależą od zaznaczenia tekstu, tabeli, obrazu lub kodu. Uchwytem „Blok” można przeciągać bloki; przyciski i Alt+Shift+strzałka zapewniają alternatywę. Kopiowanie bloku wymaga dostępu przeglądarki do schowka. Duplikowanie, przenoszenie, usuwanie i zamiana tekstu podlegają undo/redo.

Ctrl/Cmd+K w treści Visual otwiera odnośnik. Kursor wewnątrz istniejącego linku wybiera cały link do zmiany adresu lub tekstu; przy zaznaczeniu operacja obejmuje wskazany fragment. Sama zmiana adresu zachowuje formatowanie tekstu. „Usuń odnośnik” pozostawia tekst, a undo pozwala cofnąć zmianę. Anulowanie i Escape wracają do dokumentu. W metadanych i Markdown Ctrl/Cmd+K przechodzi do wyszukiwarki aplikacji.

„Znajdź i zamień” wyszukuje dosłowny tekst w Visual. Panel nagłówków przenosi do wybranego miejsca; licznik pokazuje słowa i znaki. Tryb skupienia ukrywa otoczenie dokumentu i zachowuje informację o zapisie.

## Tabele

Wybierz rozmiar tabeli i nagłówek w oknie wstawiania. Następnie dodawaj, usuwaj i przesuwaj wiersze/kolumny, zmieniaj nagłówki, tło i wyrównanie komórki oraz szerokości kolumn. Kilka komórek zaznaczysz przeciągnięciem; narzędzia pozwalają scalać i rozdzielać komórki z zachowaniem zawartości. Tab/Shift+Tab przenosi między komórkami.

Tekst skopiowany z arkusza, rozdzielony tabulatorami i wierszami, wkleja się jako tabela lub do istniejącej tabeli. Wklejanie jest ograniczone do 50 wierszy i 30 kolumn. Szerokie tabele przewijają się w swoim obszarze, również na telefonie.

## Kod i Mermaid

Kod w linii i bloki nie wykonują poleceń. Języki obejmują Bash, PowerShell, Python, PHP, JavaScript, SQL, JSON, YAML, XML i tekst. Opcje numerów linii oraz zawijania zachowują się po zapisie. Kopiowanie zwraca sam kod, bez numerów i elementów interfejsu. Spacje, tabulatory i znaki specjalne pozostają treścią.

Diagram Mermaid przechowuje edytowalne źródło. Podgląd jest odizolowany; błąd składni wyświetla komunikat i zachowuje źródło. Aktywny HTML, akcje `click`, konfiguracja diagramu i zewnętrzne odwołania są odrzucane. Podgląd przyjmuje do 20 000 znaków.

## Obrazy, załączniki i wklejanie

Użyj „Obraz”, „Dodaj plik”, schowka lub przeciągania. Kolejka pokazuje postęp, błąd, ponowienie i usunięcie z kolejki. Anulowanie zatrzymuje oczekujący upload; już przesłane prywatne, nieużywane pliki usuwa retencja. Pliki nowej strony są prywatne do świadomego zastosowania zmian. Po zastosowaniu dostęp przechodzi przez ACL strony.

Kliknij obraz, aby zmienić alt, podpis, wyrównanie, wymiary i proporcje albo otworzyć chroniony podgląd. PNG/JPEG/GIF/WebP mają bezpiecznie generowane miniatury. Załącznik prezentuje nazwę, typ, rozmiar i odnośnik pobierania. Limit pojedynczego pliku to 50 MiB; kolejka lokalna i prywatne pliki konta mają limity 50 plików/100 MiB.

Wklejanie z zewnętrznych dokumentów zachowuje dozwoloną strukturę i usuwa aktywny HTML oraz dowolny CSS. Nadmiernie duża lub głęboka struktura jest odrzucana przed zastąpieniem zaznaczenia.

Przekroczenie limitu wklejanej tabeli albo tekstu również pozostawia bieżący dokument i zaznaczenie bez zmian. Komunikat przy narzędziach bloku wyjaśnia odmowę; można wkleić mniejszy fragment.

## Visual, Markdown i starsze dokumenty

Aktywny format ma jedno źródło. Przełączenie konwertuje aktualny dokument, a podgląd Markdown używa serwerowego renderera zapisu. Standardowe tabele, listy, kod i linki mają wspólny zakres. Zaawansowane scalanie/tło/szerokości tabel, kolumny, zwijane sekcje i część formatowania nie mają pełnego odpowiednika w zwykłym Markdown.

Konwersja stratna pokazuje porównanie i wymaga decyzji. Oryginał pozostaje w kopii konwersji, szkicu i zapisanej rewizji. Można wrócić do źródła sprzed konwersji. Gdy starszy HTML zawiera strukturę poza modelem edytora, porównanie przekształcenia wymaga akceptacji; zachowanie oryginału pozwala edytować metadane bez przepisywania treści. Sam odczyt strony lub historycznej rewizji nie zapisuje transformacji.

## Klawiatura i telefon

| Skrót | Działanie |
| --- | --- |
| Ctrl/Cmd+S | Zapis najnowszego szkicu |
| Ctrl/Cmd+Z / Ctrl/Cmd+Shift+Z | Cofnięcie / ponowienie |
| Ctrl/Cmd+K | Odnośnik |
| Ctrl/Cmd+F | Znajdź i zamień w Visual |
| Alt+Shift+↑/↓ | Przenieś blok |
| Tab/Shift+Tab | Poziom listy lub komórka tabeli |
| Ctrl/Cmd+Enter | Wyjdź z bloku kodu |
| Escape | Zamknij okno lub menu |

Na telefonie drzewo jest zwijanym panelem. Pasek narzędzi zawija się, metadane można zwinąć, a tryb skupienia ogranicza otoczenie. Pola i przyciski mają widoczny fokus.
