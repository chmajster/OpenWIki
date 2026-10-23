<?php
$navigationEntries = ['home' => ['home', 'Home'], 'recent' => ['clock', 'Recently viewed'], 'favorites' => ['star', 'Favorites'], 'popular' => ['trending', 'Popular'], 'work' => ['check', 'Your work'], 'spaces' => ['spaces', 'Spaces']];
$navigationView = $data['dashboardView'] ?? null;
?>
<aside class="workspace-navigation" id="workspace-navigation" data-workspace-navigation data-page-panel aria-label="<?= $e($t('Workspace navigation')) ?>">
    <div class="navigation-mobile-heading"><strong><?= $e($app->name()) ?></strong><button type="button" class="shell-icon-button" data-navigation-close hidden aria-label="<?= $e($t('Close navigation')) ?>"><?= $uiIcon('close') ?></button></div>
    <nav class="navigation-primary" aria-label="<?= $e($t('Workspace')) ?>">
        <?php foreach ($navigationEntries as $key => [$icon, $label]): if ($currentUser === null && in_array($key, ['recent', 'favorites', 'work'], true)) continue; ?>
            <a class="navigation-link<?= $navigationView === $key ? ' is-active' : '' ?>" href="/<?= $key === 'home' ? '' : '?view=' . $key ?>"<?= $navigationView === $key ? ' aria-current="page"' : '' ?>><?= $uiIcon($icon) ?><span><?= $e($t($label)) ?></span></a>
        <?php endforeach; ?>
        <?php if ($app->auth()->can('page.delete')): ?><a class="navigation-link<?= $template === 'trash/index' ? ' is-active' : '' ?>" href="/trash"<?= $template === 'trash/index' ? ' aria-current="page"' : '' ?>><?= $uiIcon('trash') ?><span><?= $e($t('Trash')) ?></span></a><?php endif; ?>
    </nav>
    <section class="navigation-spaces">
        <div class="navigation-section-heading"><h2><?= $uiIcon('spaces') ?><span><?= $e($t('Spaces')) ?></span></h2><?php if ($app->auth()->can('space.create')): ?><a class="shell-icon-button" href="/spaces/create" aria-label="<?= $e($t('Create space')) ?>"><?= $uiIcon('plus') ?></a><?php endif; ?></div>
        <?php if ($navigationSpaces === []): ?><p class="navigation-empty"><?= $e($t('No spaces are available.')) ?></p><?php endif; ?>
        <?php foreach (array_slice($navigationSpaces, 0, 12) as $navigationItem): $isCurrentSpace = $navigationSpace !== null && $navigationItem['space_key'] === $navigationSpace['space_key']; ?>
            <a class="navigation-link navigation-space-item<?= $isCurrentSpace ? ' is-current' : '' ?>" href="/spaces/<?= rawurlencode($navigationItem['space_key']) ?>"<?= $isCurrentSpace ? ' aria-current="location"' : '' ?>>
                <span class="space-avatar" aria-hidden="true"><?= $e($navigationItem['icon'] ?: mb_substr($navigationItem['name'], 0, 1)) ?></span>
                <span class="navigation-space-item__label"><strong><?= $e($navigationItem['name']) ?></strong><small><?= $e($navigationItem['space_key']) ?></small></span>
            </a>
        <?php endforeach; ?>
        <a class="navigation-link navigation-browse" href="/?view=spaces"><?= $uiIcon('spaces') ?><span><?= $e($t('Browse all spaces')) ?></span></a>
    </section>
    <?php if ($navigationSpace !== null): $space = $navigationSpace; ?>
        <section class="navigation-space" aria-label="<?= $e($space['name']) ?>">
            <nav class="navigation-space-links" aria-label="<?= $e($t('Space navigation')) ?>">
                <a class="navigation-link<?= $template === 'spaces/show' && ($spaceTab ?? '') === 'overview' ? ' is-active' : '' ?>" href="/spaces/<?= rawurlencode($space['space_key']) ?>"<?= $template === 'spaces/show' && ($spaceTab ?? '') === 'overview' ? ' aria-current="page"' : '' ?>><?= $uiIcon('home') ?><span><?= $e($t('Overview')) ?></span></a>
                <a class="navigation-link<?= $template === 'spaces/show' && ($spaceTab ?? '') === 'content' ? ' is-active' : '' ?>" href="/spaces/<?= rawurlencode($space['space_key']) ?>?tab=content"<?= $template === 'spaces/show' && ($spaceTab ?? '') === 'content' ? ' aria-current="page"' : '' ?>><?= $uiIcon('document') ?><span><?= $e($t('All pages')) ?></span></a>
                <a class="navigation-link<?= $template === 'spaces/news' || $template === 'spaces/news-form' ? ' is-active' : '' ?>" href="/spaces/<?= rawurlencode($space['space_key']) ?>/news"<?= $template === 'spaces/news' || $template === 'spaces/news-form' ? ' aria-current="page"' : '' ?>><?= $uiIcon('megaphone') ?><span><?= $e($t('News')) ?></span></a>
                <?php if ($navigationCanCreate): ?><a class="navigation-link<?= $template === 'pages/templates' ? ' is-active' : '' ?>" href="/spaces/<?= rawurlencode($space['space_key']) ?>/templates"<?= $template === 'pages/templates' ? ' aria-current="page"' : '' ?>><?= $uiIcon('spaces') ?><span><?= $e($t('Templates')) ?></span></a><?php endif; ?>
            </nav>
            <div class="navigation-section-heading"><h2><?= $uiIcon('document') ?><span><?= $e($t('Pages')) ?></span></h2><?php if ($navigationCanCreate): ?><a class="shell-icon-button" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/create" aria-label="<?= $e($t('Create page')) ?>"><?= $uiIcon('plus') ?></a><?php endif; ?></div>
            <nav aria-label="<?= $e($t('Pages')) ?>">
                <?php $activePageId = $navigationPageId; require __DIR__ . '/page-tree.php'; ?>
            </nav>
        </section>
    <?php endif; ?>
    <div class="navigation-footer"><?= $uiIcon('book') ?><span><?= $e($t('A shared place for knowledge')) ?></span></div>
</aside>
<div class="navigation-resize-handle" data-navigation-resize role="separator" aria-orientation="vertical" aria-label="Zmień szerokość nawigacji" aria-valuemin="240" aria-valuemax="480" aria-valuenow="264" tabindex="0"><span aria-hidden="true"></span></div>
<button class="navigation-backdrop" type="button" data-navigation-backdrop tabindex="-1" aria-label="<?= $e($t('Close navigation')) ?>" hidden></button>
