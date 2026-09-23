<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$children = [];
foreach ($pages as $treePage) {
    $parentKey = $treePage['parent_id'] === null ? 0 : (int) $treePage['parent_id'];
    $children[$parentKey][] = $treePage;
}
$activePageId = $activePageId ?? null;

$renderTree = function (int $parentId) use (&$renderTree, $children, $space, $activePageId, $e): void {
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
            echo '<small>' . $e($treePage['status']) . '</small>';
        }
        echo '</a>';
        $renderTree((int) $treePage['id']);
        echo '</li>';
    }
    echo '</ul>';
};
$renderTree(0);
