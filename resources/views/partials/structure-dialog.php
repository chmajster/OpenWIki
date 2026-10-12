<dialog class="structure-dialog" data-structure-dialog aria-labelledby="structure-title" data-token="<?= $e($csrfToken) ?>">
<h2 id="structure-title">Przenieś lub kopiuj stronę</h2>
<form data-structure-form>
    <p data-structure-source></p>
    <label>Operacja <select name="action"><option value="move">Przenieś / zmień kolejność</option><option value="copy">Kopiuj</option></select></label>
    <label>Zakres <select name="scope"><option value="subtree">Całe poddrzewo</option><option value="page">Tylko ta strona (dzieci pozostają na poprzednim poziomie)</option></select></label>
    <label data-copy-title hidden>Tytuł kopii <input name="title" maxlength="255"></label>
    <label>Wyszukaj przestrzeń docelową <input type="search" data-destination-search autocomplete="off"></label>
    <label>Przestrzeń docelowa <select name="destination_space_id" required></select></label>
    <label>Wyszukaj rodzica <input type="search" data-parent-search autocomplete="off"></label>
    <label>Rodzic <select name="parent_id"><option value="">Główny poziom</option></select></label>
    <label>Wstaw przed stroną <select name="before_id"><option value="">Na końcu</option></select></label>
    <p class="form-help">Przeciągnięcie strony otwiera ten sam dialog. Kolejność zapisuje się dopiero po zatwierdzeniu. Tab przechodzi pomiędzy polami; Escape zamyka dialog.</p>
    <div data-structure-status role="status" aria-live="polite"></div>
    <section data-structure-preview hidden><h3>Skutki operacji</h3><div data-structure-effects></div><label><input type="checkbox" data-structure-confirm> Potwierdzam przedstawioną zmianę struktury i dostępu.</label></section>
    <div class="dialog-actions"><button type="submit" class="button button--secondary" data-structure-prepare>Przygotuj podgląd</button><button type="button" class="button button--primary" data-structure-save disabled>Zatwierdź operację</button><button type="button" class="button button--ghost" data-structure-cancel>Anuluj</button></div>
</form>
</dialog>
