# Aktualizacje istniejącej instalacji

Aktualizacja jest oddzielną operacją od instalacji, diagnostyki i naprawy konfiguracji. Wskazana wersja Git zostaje przypięta do konkretnego SHA. Przygotowanie kodu i zależności odbywa się w osobnym katalogu wydania; konfiguracja i storage należą do instancji, a nie do wydania.

Warunki powodzenia obejmują sprawdzenie wymagań, wyłączną blokadę aktualizacji, zweryfikowaną kopię odzyskiwania, zatrzymanie zapisów HTTP i workera, wykonanie `php bin/console migrate`, przełączenie wydania oraz gotowość bez oczekujących migracji. Wznowienie korzysta z trwałego dziennika etapów i tego samego przypiętego SHA.

DDL w MySQL/MariaDB zatwierdza transakcje niezależnie od aplikacji. Migrator musi używać blokady połączenia, trwałego dziennika kroków i jawnych reguł ponowienia; samo opakowanie DDL w transakcję nie zapewnia atomowości.

Cofnięcie kodu jest dopuszczalne tylko wtedy, gdy manifest poprzedniego wydania akceptuje bieżący schemat. Dla niezgodnego schematu potrzebne jest kontrolowane odtworzenie zweryfikowanej kopii bazy, konfiguracji i plików przy zablokowanych zapisach. Kopie odzyskiwania zawierają sekrety i wymagają ochrony przed dostępem HTTP oraz kontami aplikacji.
