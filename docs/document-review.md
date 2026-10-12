# Recenzja dokumentów i aktualność wiedzy

## Cel i zasady danych

Opublikowana rewizja jest niezależna od roboczej głowy dokumentu. Recenzja
dotyczy konkretnego numeru rewizji; zapis kolejnej rewizji unieważnia możliwość
publikacji wcześniejszej akceptacji jako akceptacji nowych zmian.

Workflow przestrzeni jest opcjonalny. Autor zapisuje i zgłasza zmiany,
recenzent podejmuje decyzję z komentarzem, a publikujący udostępnia dokładnie
zatwierdzoną rewizję. Domyślnie autor nie zatwierdza własnej rewizji.

Odczyty, historia, wyszukiwanie, makra, eksport i załączniki muszą stosować tę
samą granicę pomiędzy treścią opublikowaną a roboczą. Przywrócenie i import
przechodzą wspólną usługę zapisu oraz politykę obiegu.

## Plan weryfikacji

Migracja istniejącej historii bez modyfikowania snapshotów; testy domenowe
konkretnych rewizji i odmów uprawnień; rzeczywisty przepływ formularzy autora,
recenzenta, publikującego i czytelnika, obejmujący konflikty i ponowne otwarcie.

## Praca z obiegiem

W ustawieniach przestrzeni osoba z `space.manage_access` może włączyć recenzję
oraz zezwolić recenzentowi na zatwierdzanie własnych zmian. Włączenie ani
wyłączenie tej polityki nie publikuje istniejących szkiców.

Autor potrzebuje `page.create` / `page.edit`, recenzent `page.review`, a
publikujący `page.publish`. Każda decyzja wymaga także właściwego ACL strony
i dostępu do zmian w przestrzeni. Role Reviewer i Publisher nie przyznają
edycji treści. Uprawnienie globalne nie omija deny ACL. Superadministrator
również przestrzega wyłączonej możliwości zatwierdzania własnej rewizji.

Po zapisie wybierz „Obieg i aktualność” albo „Obieg dokumentów” w menu konta.
Przekaż szkic do recenzji. Recenzent zatwierdza lub odrzuca go, podając
komentarz. Publikujący publikuje wskazaną zatwierdzoną rewizję. Zapis podczas
recenzji lub po akceptacji rozpoczyna nowy obieg, a stary formularz decyzji
otrzymuje konflikt. Odrzuconą rewizję można ponownie zgłosić lub poprawić.

Wyłączony workflow zachowuje bezpośredni zapis opublikowanej treści przez
osobę z `page.publish`. Zapis jako szkic zachowuje poprzednią publikację.
Przywrócenie tworzy kolejną rewizję; odtworzenie snapshotu z opublikowanym
lub zarchiwizowanym statusem wymaga odpowiednio `page.publish` / `page.archive`.
W przestrzeni z recenzją odtworzona treść pozostaje szkicem. Import tworzy
szkice. Kopia do przestrzeni z recenzją wymaga nowego obiegu. Kopiowanie
opublikowanego dokumentu korzysta z opublikowanej treści i plików; przenoszenie
zachowuje roboczą treść i rozpoczyna nowy obieg dla nowej rewizji.

Właściciel merytoryczny jest wskazywany aktywnym kontem w metadanych edytora.
Przypisanie właściciela nie nadaje uprawnień ACL. Termin przeglądu i właściciel
są zapisane w rewizji i objęte odzyskiwaniem lokalnym oraz autosave. Osoba
z `page.verify` i zgodą ACL może potwierdzić aktualność wskazanej opublikowanej
rewizji, wyznaczając przyszły termin. Potwierdzenie nie akceptuje zmian roboczych.
Lista „Dokumenty po terminie przeglądu” obejmuje tylko dostępne publikacje
z terminem wcześniejszym niż bieżąca data UTC.

## Granice odczytu i integracje

`pages.version` jest monotonicznym numerem roboczej głowy. `published_revision`
wskazuje publikację. Kolumny treści, tytułu i metadane indeksowane w `pages`
stanowią opublikowaną projekcję, dopóki istnieje publikacja. Źródło robocze
jest w `page_revisions`; autosave pozostaje prywatną kopią autora i karty.
Operacje struktury i ACL są osobnymi, autoryzowanymi operacjami.

Wyszukiwanie, makra, zwykły widok i eksport używają publikacji. Dostępne nowe
szkice bez publikacji mogą być czytane przez współpracowników mających prawo
edycji, recenzji lub publikacji. Robocze zmiany istniejących artykułów są
dostępne w panelu obiegu. Historia dla czytelnika obejmuje wyłącznie rewizje,
które rzeczywiście były opublikowane. Podgląd i diff sprawdzają uprawnienia
obu rewizji; kopia sprzed konwersji pozostaje dostępna tylko do uprawnionego
przywrócenia. HTML snapshotów jest filtrowany przy odczycie bez nadpisywania.

Każdy zapis zamraża wersje i nazwy załączników rewizji. W przestrzeni z recenzją
publikacja nie udostępnia plików ani ich wersji dodanych po tym zapisie.
Czytelnik pobiera opublikowaną wersję, a bezpośredni URL nowszej wersji jest
odrzucany. Eksport publikacji pomija pliki robocze również dla edytora.
Usunięcie pliku w szkicu nie usuwa bajtów bieżącej publikacji. Dopiero publikacja
rewizji bez tego pliku usuwa go z odczytu czytelnika.
Powiadomienia sprawdzają aktualny ACL odbiorcy; decyzje trafiają też do
uprawnionego autora i właściciela merytorycznego. Zdarzenia robocze webhooków
nie zawierają roboczego tytułu ani slugu.

API: `GET /api/v1/pages/{id}` zwraca publikację i jej numer. Uprawniony
współpracownik może użyć `?view=working`. Zapis UI i API używa `base_version`
roboczej głowy. `POST /api/v1/pages/{id}/review` przyjmuje `revision`, `action`
(`submit`, `approve`, `reject`, `publish`, `verify`) i `comment`; `verify`
wymaga też `review_due_at`. Osobne endpointy `revisions`, `revisions/{number}`,
`diff?from=N&to=M` oraz `restore` zapewniają historię i jej bezpieczny odczyt.
`GET /api/v1/pages/{id}/review` zwraca decyzje wyłącznie osobie mającej dostęp
do roboczych rewizji; `page` i `per_page` stronicują wyniki.

Migracja 011 domyślnie wyłącza workflow w istniejących przestrzeniach. Zachowuje
źródła, stare snapshoty, daty edycji, konta i istniejące uprawnienia. Jeżeli
brakuje snapshotu bieżącej publikacji, dodaje go z rzeczywistej treści strony
z opisem `Migration: preserved published page snapshot`, nie zmieniając jej
numeru wersji ani wcześniejszych rewizji. Migrator stosuje blokadę i dziennik
etapów; ponowienie nie tworzy dodatkowych snapshotów.
