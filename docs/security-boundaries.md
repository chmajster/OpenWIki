# Granice bezpieczeństwa OpenWiki

Dokument HTML jest filtrowany przez wspólną politykę przy zapisie i odczycie. Niedozwolona otoczka nie zwalnia jej dzieci z kontroli. Każdy zachowany element, atrybut i URL wymaga walidacji, niezależnie od CSP. Odczyt historycznych rewizji nie zmienia zapisanych snapshotów.

Delegowanie administracji musi uwzględniać aktywnego wykonawcę, chronione konto docelowe oraz obecne i przyznawane uprawnienia. `user.manage` ani `role.manage` nie stanowią prawa nadania `*`. Ochrona dotyczy również alternatywnej roli z wildcardem, resetu hasła/MFA i konfiguracji uwierzytelniania. Zmiany katalogu muszą serializować kontrolę ostatniego aktywnego Super Admina w transakcji.

Publikacja i archiwizacja wymagają prawa globalnego oraz decyzji ACL konkretnej strony. Zapis przez UI/API i przywracanie stosują tę samą politykę. Formularz po błędzie nie może ujawniać metadanych ukrytych stron.

Usuwanie rodzica obejmuje całe poddrzewo w jednej transakcji, z kontrolą dostępu do każdego elementu. Struktura i reguły ACL pozostają zachowane w koszu. Przywracanie nie może omijać ograniczeń rodzica ani automatycznie odtwarzać elementów usuniętych wcześniej w innej operacji. Trwałe usunięcie nie może pozostawić aktywnych lub przywracalnych dzieci z rozszerzonym dostępem.

Weryfikacja obejmuje gościa, czytelnika, edytora, administratora delegowanego i superadministratora, rzeczywiste formularze/API, brak ochrony CSP dla próby filtra HTML oraz równoległe transakcje administracyjne.
