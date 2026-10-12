<div data-documentation-tree data-url="/spaces/<?= rawurlencode($space['space_key']) ?>/tree" data-space-id="<?= (int) $space['id'] ?>" data-user-id="<?= (int) ($currentUser['id'] ?? 0) ?>" data-active-page="<?= (int) ($activePageId ?? 0) ?>">
    <label class="tree-search">Szukaj w przestrzeni <input type="search" data-tree-search autocomplete="off" aria-label="Szukaj strony w przestrzeni"></label>
    <div data-tree-status role="status" aria-live="polite">Ładowanie stron…</div>
    <ul class="page-tree" role="tree" aria-label="Struktura dokumentacji" data-tree-root></ul>
    <noscript><p>Włącz JavaScript, aby przeglądać gałęzie drzewa. <a href="/search?space=<?= rawurlencode($space['space_key']) ?>">Wyszukaj dokument</a>.</p></noscript>
</div>
