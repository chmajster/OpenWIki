# Granice bezpieczeństwa OpenWiki

Dokument HTML jest filtrowany przez wspólną politykę przy zapisie i odczycie. Niedozwolona otoczka nie zwalnia jej dzieci z kontroli. Każdy zachowany element, atrybut i URL wymaga walidacji, niezależnie od CSP. Odczyt historycznych rewizji nie zmienia zapisanych snapshotów.

Delegowanie administracji musi uwzględniać aktywnego wykonawcę, chronione konto docelowe oraz obecne i przyznawane uprawnienia. `user.manage` ani `role.manage` nie stanowią prawa nadania `*`. Ochrona dotyczy również alternatywnej roli z wildcardem, resetu hasła/MFA i konfiguracji uwierzytelniania. Zmiany katalogu muszą serializować kontrolę ostatniego aktywnego Super Admina w transakcji.

Publikacja i archiwizacja wymagają prawa globalnego oraz decyzji ACL konkretnej strony. Zapis przez UI/API i przywracanie stosują tę samą politykę. Formularz po błędzie nie może ujawniać metadanych ukrytych stron.

Usuwanie rodzica obejmuje całe poddrzewo w jednej transakcji, z kontrolą dostępu do każdego elementu. Struktura i reguły ACL pozostają zachowane w koszu. Przywracanie nie może omijać ograniczeń rodzica ani automatycznie odtwarzać elementów usuniętych wcześniej w innej operacji. Trwałe usunięcie nie może pozostawić aktywnych lub przywracalnych dzieci z rozszerzonym dostępem.

Weryfikacja obejmuje gościa, czytelnika, edytora, administratora delegowanego i superadministratora, rzeczywiste formularze/API, brak ochrony CSP dla próby filtra HTML oraz równoległe transakcje administracyjne.

Parser [Masterminds HTML5](https://github.com/Masterminds/html5-php) zastępuje parser HTML4 w filtrze, zachowując PHP 8.2 i istniejące rozszerzenie DOM. [Dokumentacja PHP](https://www.php.net/manual/en/domdocument.loadhtml.php) opisuje różnice w interpretacji HTML4 i HTML5; dlatego sprawdzamy także ponowne parsowanie wyników w przeglądarce bez CSP. Lista elementów, wartości, wzorców atrybutów i zakresów liczbowych jest wspólna dla serwera i edytora. Odczyt zachowuje bezpiecznie przefiltrowany render historycznego Markdown, a zapis ponownie wyprowadza HTML z jego źródła.

Kontekst administracji jest jawny: sesja UI, właściciel tokenu API albo lokalna konsola PHP. Brak kontekstu odmawia mutacji. Administrator delegowany może przyznawać jedynie posiadane uprawnienia; nie może kontrolować kont ani ról mających uprawnienia wykraczające poza jego własne. LDAP, globalna polityka MFA i kopie zawierające sekrety wymagają Super Admina. Wspólny rekord `security_locks` i bieżące odczyty `FOR UPDATE` chronią ostatnie aktywne konto posiadające `*`, także przez rolę niestandardową lub synchronizację LDAP. Migrację można wznowić po przerwaniu DDL.

Kosz przypisuje aktywnym elementom usuwanego poddrzewa wspólny `trash_batch_id`. Przywracanie obejmuje tylko tę partię pod wskazanym węzłem; rodzic spoza partii musi być aktywny. Wcześniej usunięte dzieci pozostają w koszu. Historyczne rekordy bez partii przywracają pojedynczy węzeł. ACL obejmuje również zachowanych, usuniętych przodków. Trwałe usunięcie odmawia operacji, jeśli pozostało aktywne dziecko lub relacja między różnymi przestrzeniami; następnie usuwa dzieci przed rodzicem i pliki załączników dopiero po zatwierdzeniu transakcji. Audyt i kolejka zdarzeń są częścią tej samej transakcji. Operacja jest ograniczona do przestrzeni z maksymalnie 50 000 węzłów, a ACL odczytywane są zbiorczo pod blokadą przestrzeni.
