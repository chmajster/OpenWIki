<?php
$navigationEntries = ['home' => ['home', 'Home'], 'recent' => ['clock', 'Recently viewed'], 'favorites' => ['star', 'Favorites'], 'work' => ['check', 'Your work'], 'spaces' => ['spaces', 'Spaces']];
$navigationView = $data['dashboardView'] ?? null;
?>
<aside class="workspace-navigation" id="workspace-navigation" data-workspace-navigation data-page-panel aria-label="<?= $e($t('Workspace navigation')) ?>">
    <div class="navigation-mobile-heading"><strong><?= $e($app->name()) ?></strong><button type="button" class="shell-icon-button" data-navigation-close hidden aria-label="<?= $e($t('Close navigation')) ?>"><?= $uiIcon('close') ?></button></div>
    <nav class="navigation-primary" aria-label="<?= $e($t('Workspace')) ?>">
        <?php foreach ($navigationEntries as $key => [$icon, $label]): if ($currentUser === null && in_array($key, ['recent', 'favorites', 'work'], true)) continue; ?>
            <a class="navigation-link<?= $navigationView === $key ? ' is-active' : '' ?>" href="/<?= $key === 'home' ? '' : '?view=' . $key ?>"<?= $navigationView === $key ? ' aria-current="page"' : '' ?>><?= $uiIcon($icon) ?><span><?= $e($t($label)) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($navigationSpace !== null): $space = $navigationSpace; ?>
        <section class="navigation-space">
            <a class="navigation-space-heading" href="/spaces/<?= rawurlencode($space['space_key']) ?>"><span class="space-avatar" aria-hidden="true"><?= $e($space['icon'] ?: mb_substr($space['name'], 0, 1)) ?></span><span><strong><?= $e($space['name']) ?></strong><small><?= $e($space['space_key']) ?></small></span></a>
            <a class="navigation-link<?= $template === 'spaces/show' && ($spaceTab ?? '') === 'overview' ? ' is-active' : '' ?>" href="/spaces/<?= rawurlencode($space['space_key']) ?>"<?= $template === 'spaces/show' && ($spaceTab ?? '') === 'overview' ? ' aria-current="page"' : '' ?>><?= $uiIcon('home') ?><span><?= $e($t('Overview')) ?></span></a>
            <a class="navigation-link<?= $template === 'spaces/show' && ($spaceTab ?? '') === 'content' ? ' is-active' : '' ?>" href="/spaces/<?= rawurlencode($space['space_key']) ?>?tab=content"<?= $template === 'spaces/show' && ($spaceTab ?? '') === 'content' ? ' aria-current="page"' : '' ?>><?= $uiIcon('document') ?><span><?= $e($t('All pages')) ?></span></a>
            <?php if ($navigationCanCreate): ?><a class="navigation-link<?= $template === 'pages/templates' ? ' is-active' : '' ?>" href="/spaces/<?= rawurlencode($space['space_key']) ?>/templates"<?= $template === 'pages/templates' ? ' aria-current="page"' : '' ?>><?= $uiIcon('spaces') ?><span><?= $e($t('Templates')) ?></span></a><?php endif; ?>
            <div class="navigation-section-heading"><h2><?= $e($t('Content')) ?></h2><?php if ($navigationCanCreate): ?><a class="shell-icon-button" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/create" aria-label="<?= $e($t('Create page')) ?>"><?= $uiIcon('plus') ?></a><?php endif; ?></div>
            <nav aria-label="<?= $e($t('Pages')) ?>">
                <?php $activePageId = $navigationPageId; require __DIR__ . '/page-tree.php'; ?>
            </nav>
        </section>
    <?php else: ?>
        <section class="navigation-spaces">
            <div class="navigation-section-heading"><h2><?= $e($t('Spaces')) ?></h2><?php if ($app->auth()->can('space.create')): ?><a class="shell-icon-button" href="/spaces/create" aria-label="<?= $e($t('Create space')) ?>"><?= $uiIcon('plus') ?></a><?php endif; ?></div>
            <?php if ($navigationSpaces === []): ?><p class="navigation-empty"><?= $e($t('No spaces are available.')) ?></p><?php endif; ?>
            <?php foreach (array_slice($navigationSpaces, 0, 12) as $navigationItem): ?>
                <a class="navigation-link" href="/spaces/<?= rawurlencode($navigationItem['space_key']) ?>"><span class="space-avatar space-avatar--small" aria-hidden="true"><?= $e($navigationItem['icon'] ?: mb_substr($navigationItem['name'], 0, 1)) ?></span><span><?= $e($navigationItem['name']) ?></span></a>
            <?php endforeach; ?>
            <a class="navigation-link navigation-browse" href="/?view=spaces"><?= $uiIcon('spaces') ?><span><?= $e($t('Browse all spaces')) ?></span></a>
        </section>
    <?php endif; ?>
    <div class="navigation-footer"><?= $uiIcon('book') ?><span><?= $e($t('A shared place for knowledge')) ?></span></div>
</aside>
<button class="navigation-backdrop" type="button" data-navigation-backdrop tabindex="-1" aria-label="<?= $e($t('Close navigation')) ?>" hidden></button>
