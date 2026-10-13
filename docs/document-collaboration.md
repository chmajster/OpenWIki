# Współpraca nad dokumentacją

## Interfejs

Na opublikowanej stronie, w podglądzie recenzji i w dostępnej rewizji historycznej zaznacz tekst i wybierz **Dodaj komentarz do zaznaczenia**. Cytat i rewizja źródłowa są widoczne w panelu **Dyskusje**. Wątki obsługują odpowiedzi, @wzmianki, edycję autora, moderację, rozwiązanie/ponowne otwarcie, filtry stanu i paginację. Komentarze ogólne pozostają w dotychczasowej sekcji strony.

Przycisk cytatu zaznacza tekst. Niebieskie oznaczenia aktualnie wczytanych wątków otwierają panel po kliknięciu lub Enter w dokumencie. Żółte oznaczenie wskazuje wybrany fragment. **Wszystkie dyskusje** wychodzi z widoku pojedynczego wątku. Desktop ma prawy panel; telefon dialog zajmujący ekran. Escape zamyka dialog i przywraca fokus. Błąd zapisu zachowuje wpisaną treść. Po wygaśnięciu sesji zaloguj się na to samo konto w drugiej karcie i ponów zapis; formularz odświeży CSRF po ponownej kontroli dokumentu i konta.

**Utwórz zadanie** jest dostępne obok dokumentu oraz w dyskusji. Tytuł i status są w krótkim formularzu; opis, termin i osoba odpowiedzialna są rozwijane. **Zadania strony** pokazują statusy i licznik otwartych zadań. Zadanie zachowuje stronę, rewizję i odnośnik do wątku oraz udostępnia historię. Usunięta dyskusja jest oznaczona jako niedostępna; istniejąca rewizja pozostaje dostępna zgodnie z ACL.

**Centrum pracy** na stronie głównej zawiera przypisane zadania, recenzje/publikacje, przeglądy aktualności, odpowiedzi/wzmianki oraz prywatne szkice. **Wznów szkic** korzysta z istniejącego odzyskiwania edytora. Filtry przestrzeni, rodzaju, stanu i terminu, sortowanie oraz paginacja są zachowane w URL. Starsze adresy kolejki recenzji i dokumentów po terminie przekierowują do filtrów centrum. Na stronie przestrzeni **Dokumenty wymagające uwagi** obejmują zadania otwarte, nierozwiązane dyskusje, zaległe przeglądy i brak aktywnego właściciela.

## Modele i dostęp

Migracja **013_document_collaboration** rozszerza komentarze o wątek, rewizję, widoczność, kotwicę, stan i wersję. Dodaje page_tasks, page_task_history i collaboration_operations; powiadomienia otrzymują kontekst zasobu i unikalny klucz odbiorca/zdarzenie. Stare komentarze zachowują widoczność page, treść, daty i relacje odpowiedzi. Dokumenty, checklisty, załączniki, szkice, ustawienia i rewizje nie są przepisywane.

CommentAnchor zapisuje osobno oryginalny cytat, kontekst po 64 znaki, pozycję w kanonicznym tekście Unicode, liczbę wystąpień oraz SHA-256 źródła. Aktualny model nie posiada stabilnych identyfikatorów bloków. Dla identycznego źródła używana jest zweryfikowana pozycja. Po zmianie wymagane jest jednoznaczne dopasowanie cytatu i kontekstu; indeks DOM lub numer wystąpienia nie wystarcza. Przy niepewnym dopasowaniu wątek zachowuje cytat, stan i źródłowy odnośnik z komunikatem **Fragment zmieniony lub usunięty**. Dekoracje nigdy nie trafiają do dokumentu.

Dyskusja/zadanie nad zmianą roboczą pozostaje trwale working, również po publikacji źródłowej rewizji. Odczyt, odpowiedzi, historia, liczniki, wyszukiwanie, przypisanie i powiadomienia wymagają aktualnego dostępu do strony i roboczych rewizji. Przypisanie nie nadaje dostępu. Zadanie z menu podglądu recenzji dziedziczy wyświetlaną rewizję; zadanie z menu publikacji bez wątku dotyczy całej strony.

UI i REST korzystają z CollaborationActions i wspólnych usług domenowych. CollaborationAccess wykorzystuje PageAclService, RevisionAccessService oraz bieżące role, ograniczone prawami tokenu. Mutacje ponownie sprawdzają kontekst pod blokadą użytkownika, przestrzeni i strony. Zasób bez dostępu zwraca 404. Archiwum jest tylko do odczytu, zachowując historię. Usunięta strona blokuje powiązane zasoby. Trwałe usunięcie usuwa jej zadania/historię przez FK zgodnie z semantyką usuwania dokumentu.

| Uprawnienie | Zastosowanie |
| --- | --- |
| task.create | Tworzenie i edycja własnych zadań |
| task.manage | Zarządzanie zadaniami innych autorów |
| task.update_own | Status zadania przypisanego do siebie |
| comment.resolve | Rozwiązanie/ponowne otwarcie dyskusji |

Autor posiadający comment.create może rozwiązać swój wątek. Moderacja nadal wymaga comment.delete. Admin/space-admin otrzymują komplet praw współpracy; editor/author/reviewer/publisher tworzenie zadań, własny status i rozwiązywanie. Viewer otrzymuje tylko własny status zadania; dotychczasowe prawa komentarzy pozostają zachowane. Role niestandardowe należy skonfigurować świadomie. Te prawa nie nadają page.edit, dostępu przestrzeni ani publikacji.

Katalog osób zawiera aktywne konta z dostępem do kontekstu. Wykorzystuje istniejącą widoczność nazw dla zalogowanych, bez e-maili. Wyszukiwanie jest ograniczone do 100 kandydatów na frazę; w dużym katalogu zawęź nazwę. Zapis ponownie sprawdza wybraną osobę pod blokadą. Czytelnik przypisany do publicznego zadania może zmienić status bez prawa edycji strony.

## Konflikty, powiadomienia i wydajność

Zmiana zadania/stanu dyskusji wymaga base_version. Nieaktualna wersja zwraca 409. **Wczytaj aktualną wersję** zachowuje pola zmienione przez użytkownika, odświeża pozostałe i aktualne prawa. Następny zapis używa nowej wersji.

Opcjonalne operation_id (UUID) identyfikuje operację użytkownika. Ponowienie tych samych danych zwraca ten sam zasób bez dodatkowego audytu, historii i powiadomienia. Zmiana danych z tym samym UUID zwraca 409. Receipts zawierają hash i identyfikatory bez prywatnej treści; cron usuwa je po 30 dniach. Po tym okresie UUID nie gwarantuje deduplikacji. Krótkie ponowienia transakcji obsługują deadlock/lock timeout; wyczerpanie limitu zwraca kontrolowany konflikt.

Odbiorca otrzymuje jedno powiadomienie, nawet gdy jest obserwatorem, uczestnikiem i adresatem wzmianki. Dostęp jest sprawdzany przy dostarczeniu i każdym odczycie/liczeniu. Odebranie ACL ukrywa zapisany tytuł i komunikat. Audyt zapisuje identyfikatory, statusy i nazwy zmienionych pól, bez prywatnych cytatów. Autosave nie tworzy zdarzeń współpracy.

**php bin/console cron:run** tworzy powiadomienia w aplikacji dla przypisanych otwartych zadań w dniu terminu i raz dziennie po terminie. Klucz zadanie/termin/osoba/dzień oraz unikalny indeks chronią retry. Nie wymaga e-mail, Redis, Node runtime ani WebSocket.

Znaczniki czasu są zapisywane jawnie w UTC i prezentowane według APP_TIMEZONE. Termin zadania jest datą YYYY-MM-DD, nie chwilą w UTC. Porównania dnia w centrum i cron korzystają ze skonfigurowanej strefy.

WorkCenterService materializuje kandydatów/dozwolone identyfikatory w tymczasowych tabelach SQL w ramach żądania. Sprawdza metadata partiami po 200 przez zbiorczą semantykę ACL/przodków. Dopiero z dozwolonej relacji liczy sumy/filtry i pobiera tytuły wyświetlanej strony. Nie pobiera treści wszystkich dokumentów do pamięci. Konto bazy wymaga CREATE TEMPORARY TABLES (standardowe instalatory nadają wymagane prawa). Listy są paginowane, odpowiedzi i historia w partiach po 25.

## REST API v1

Zakresy tokenu: comments:read/write, tasks:read/write, work:read; zawsze dodatkowo bieżące RBAC/ACL. Odpowiedzi używają data/meta i błędów 403/404/409/422. UI wymaga CSRF.

~~~text
GET       /api/v1/pages/{page}/discussions?revision_number=N&status=all|open|resolved&page=1
GET       /api/v1/comments/{id}
GET       /api/v1/comments/{thread}/replies?page=1
POST      /api/v1/comments
PUT,PATCH /api/v1/comments/{id}
DELETE    /api/v1/comments/{id}
POST      /api/v1/comments/{thread}/status
GET,POST  /api/v1/pages/{page}/tasks
GET       /api/v1/pages/{page}/task-assignees?revision_number=N&q=name
GET       /api/v1/tasks/{id}
PUT,PATCH /api/v1/tasks/{id}
GET       /api/v1/tasks/{id}/history?page=1
GET       /api/v1/work?space=ID&type=task&status=active&deadline=overdue&sort=due&page=1
~~~

Nowa dyskusja: page_id, body, revision_number, anchor: {quote, start, hash}. Start liczy punkty kodowe Unicode, hash to SHA-256 kanonicznego tekstu rewizji. Odpowiedź używa parent_id i dziedziczy kontekst. Edycja komentarza fragmentu wymaga base_version; ogólny komentarz zachowuje kompatybilność API. Zmiana wątku: status open/resolved, base_version i opcjonalny operation_id.

Zadanie: title, description, assignee_id, due_date, status, opcjonalnie thread_id/revision_number i UUID. Aktualizacja wymaga wersji. Statusy: todo, in_progress, blocked, done, cancelled. Typy pracy: task, review, freshness, discussion, draft, ownership. Parametr attention=1&space=ID wybiera uwagę w przestrzeni. Wyszukiwanie komentarzy roboczych stosuje te same granice rewizji.

## Instalacja i aktualizacja

Wykorzystaj [instalator](../README.md), [Docker](docker-runtime.md) lub [zarządzaną aktualizację](upgrades.md). Przed ręczną aktualizacją wykonaj zweryfikowaną kopię bazy, konfiguracji i storage oraz zatrzymaj zapisy i cron. Zachowaj APP_KEY i dane. Po przygotowaniu nowego kodu:

~~~bash
composer install --no-dev --prefer-dist --no-interaction
npm ci
npm run build
php bin/console migrate
php bin/console db:status
php bin/console system:check
php bin/console cron:run
~~~

Node służy budowaniu, nie jest serwerem aplikacji. Gotowy pakiet wydania zawiera bundle. Świeża instalacja stosuje 013 automatycznie. Nie resetuj bazy. Starszy kod bez zgodności migracji 013 wymaga kontrolowanego odtworzenia kopii przy rollback; nie cofaj samego Git przy działających zapisach.

## Weryfikacja i ograniczenia

Testy wymagają oddzielnej bazy kończącej się _test oraz OPENWIKI_TEST_DB_*. Browser używa rzeczywistych formularzy/API/backendu, bez atrap odpowiedzi.

~~~bash
vendor/bin/phpunit
npm run check:js
npm run build
npx playwright test
~~~

DocumentCollaborationTest sprawdza prywatność, ACL/token ceilings, własny status, konflikty/replay, historię, paginację po ACL, bounded replies i deduplikację cron. CommentAnchorTest sprawdza Unicode, przesunięcia, duplikaty, usunięcie i fałszywy hash. Test aktualizacji zachowuje stare komentarze/odpowiedzi, powiadomienia, dokumenty, rewizje i szkice. Collaboration.spec.cjs przechodzi komentarz→zadanie→centrum→status, prywatną recenzję, revocation, kotwice, szkic, konflikt i układ 390/768/1440. Zrzuty powstają w storage/temp/collaboration-*.png. CI sprawdza również instalator, upgrade MySQL/MariaDB, Docker i pakiet.

Przy niejednoznacznym tekście lub renderowaniu makra, którego nie można bezpiecznie odwzorować na źródło, interfejs odmawia błędnego zaznaczenia. Zachowany cytat i historyczna rewizja pozwalają odnaleźć kontekst. Nie przenosimy kotwic na podstawie zgadywania.
