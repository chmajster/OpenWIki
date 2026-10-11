<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$children = [];
$visibleIds = array_fill_keys(array_map(static fn (array $row): int => (int) $row['id'], $pages), true);
foreach ($pages as $treePage) {
    $parentKey = isset($visibleIds[(int) ($treePage['parent_id'] ?? 0)]) ? (int) $treePage['parent_id'] : 0;
    $children[$parentKey][] = $treePage;
}
$activePageId = $activePageId ?? null;

$renderTree = function (int $parentId) use (&$renderTree, $children, $space, $activePageId, $e, $t): void {
    if (empty($children[$parentId])) {
        return;
    }
    echo '<ul class="page-tree">';
    foreach ($children[$parentId] as $treePage) {
        $active = $activePageId !== null && (int) $treePage['id'] === (int) $activePageId;
        echo '<li>';
        echo '<a class="page-tree__link' . ($active ? ' is-active' : '') . '" href="/spaces/'
            . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($treePage['slug']) . '">';
        echo '<span>' . $e($treePage['title']) . '</span>';
        if ($treePage['status'] !== 'published') {
            echo '<small>' . $e($t($treePage['status'])) . '</small>';
        }
        echo '</a>';
        $renderTree((int) $treePage['id']);
        echo '</li>';
    }
    echo '</ul>';
};
$renderTree(0);
