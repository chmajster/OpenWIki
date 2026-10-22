<div data-documentation-tree data-url="/spaces/<?= rawurlencode($space['space_key']) ?>/tree" data-space-id="<?= (int) $space['id'] ?>" data-user-id="<?= (int) ($currentUser['id'] ?? 0) ?>" data-active-page="<?= (int) ($activePageId ?? 0) ?>" data-active-path="<?= $e(json_encode(array_values(array_filter(array_column($data['breadcrumbs'] ?? [], 'url'))), JSON_THROW_ON_ERROR)) ?>">
    <label class="tree-search"><span class="sr-only">Szukaj w przestrzeni</span><input type="search" data-tree-search autocomplete="off" aria-label="Szukaj strony w przestrzeni" placeholder="Szukaj stron"></label>
    <div data-tree-status role="status" aria-live="polite">Ładowanie stron…</div>
    <ul class="page-tree" role="tree" aria-label="Struktura dokumentacji" data-tree-root></ul>
    <noscript><p>Włącz JavaScript, aby przeglądać gałęzie drzewa. <a href="/search?space=<?= rawurlencode($space['space_key']) ?>">Wyszukaj dokument</a>.</p></noscript>
</div>
