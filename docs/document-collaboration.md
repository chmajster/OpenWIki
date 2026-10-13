# Współpraca nad dokumentacją

## Mapa istniejącej aplikacji

- `CommentService` i `CommentController`: komentarze ogólne, odpowiedzi,
  edycja autora, usuwanie/moderacja oraz wzmianki. Brakuje kotwic rewizji,
  stanów dyskusji i wspólnej kontroli kontekstu przy każdej operacji.
- `PageEngagementService`: obserwowanie i powiadomienia. Odbiorcy zdarzeń
  stron są sprawdzani przy tworzeniu, ale zapisane powiadomienia wymagają
  ponownej kontroli widoczności przy odczycie i liczeniu.
- `PageWriteService`, `PageReviewService`, `RevisionAccessService` i
  `PageAclService`: wspólna granica publikacji/wersji roboczej, niezmienne
  snapshoty, kontrola wersji, recenzja i ACL. Te mechanizmy pozostają źródłem
  autoryzacji; współpraca nie publikuje dokumentów ani nie zmienia ich treści.
- Dashboard, kolejka recenzji i lista przeglądów: działające listy wymagające
  integracji w centrum pracy, filtrów oraz paginacji po kontroli dostępu.
- `SearchAccess`: zbiorcza kontrola ACL kandydatów i ich przodków; można ją
  wykorzystać do list pracy bez pobierania treści wszystkich dokumentów.
- Migrator stosuje dziennik i blokadę DDL. `cron:run` obsługuje istniejącą
  kolejkę, retencję i indeksowanie. PHP/MySQL pozostają jedynym runtime.

## Zakres implementacji

Kolejność: wspólne modele i migracja, kotwice oraz dyskusje, zadania,
centrum pracy i powiadomienia, następnie regresja i weryfikacja interfejsu.
Zmiany obejmą istniejące usługi komentarzy/powiadomień, kontrolery UI/API,
widoki strony/recenzji/pulpitu, zasoby czytnika, RBAC, cron oraz testy.

Kotwice będą osobnymi danymi rewizji: cytat, kontekst i lokalizacja w
kanonicznym tekście. Nie zmieniają HTML ani historycznych snapshotów.
Niejednoznaczne dopasowanie pozostawia dyskusję otwartą z oryginalnym cytatem.
Zadania mają własną wersję, historię i identyfikator operacji. Ich przypisanie
nie przyznaje dostępu do strony. Powiadomienia mają kontekst zasobu i
deduplikację; odczyt zawsze ponownie sprawdza bieżące uprawnienia.
