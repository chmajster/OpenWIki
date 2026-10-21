<?php
require __DIR__ . '/../partials/ui-icons.php';
$viewLabels = ['home' => 'For you', 'recent' => 'Recently viewed', 'favorites' => 'Favorites', 'work' => 'Your work', 'spaces' => 'Spaces'];
$listPages = match ($dashboardView) {
    'recent' => $recentViewed,
    'favorites' => $favorites,
    default => $recentViewed !== [] ? $recentViewed : $recentUpdated,
};
?>
<section class="content-container home-workspace" data-home-view="<?= $e($dashboardView) ?>">
    <div class="page-heading home-heading">
        <div>
            <div class="home-eyebrow"><?= $e($t('Your knowledge workspace')) ?></div>
            <h1><?= $e($t($viewLabels[$dashboardView])) ?></h1>
            <p class="muted"><?= $e($t($dashboardView === 'spaces' ? 'Find a space for your team, project, or next idea.' : 'Pick up where you left off and keep your team moving.')) ?></p>
        </div>
        <?php if ($canCreateSpace && $dashboardView === 'spaces'): ?><a class="button button--primary" href="/spaces/create"><?= $uiIcon('plus') ?><?= $e($t('Create space')) ?></a><?php endif; ?>
    </div>
    <?php if ($dashboardView !== 'spaces'): ?>
        <nav class="workspace-tabs" aria-label="<?= $e($t('Home views')) ?>">
            <?php foreach (['home', 'recent', 'favorites', 'work'] as $tab): if ($currentUser === null && $tab !== 'home') continue; ?>
                <a href="/<?= $tab === 'home' ? '' : '?view=' . $tab ?>"<?= $dashboardView === $tab ? ' aria-current="page"' : '' ?>><?= $e($t($viewLabels[$tab])) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <?php if (in_array($dashboardView, ['home', 'spaces'], true)): ?>
        <?php if ($dashboardView === 'spaces'): $browseResult = $directory; $browseKind = 'spaces'; $browseAction = '/'; $browseFixed = ['view' => 'spaces']; require __DIR__ . '/../partials/browse-filters.php'; endif; ?>
        <section class="home-section" aria-labelledby="home-spaces-heading">
            <div class="panel__header"><h2 id="home-spaces-heading"><?= $e($t('Spaces')) ?><?php if ($dashboardView === 'spaces'): ?> <span class="count"><?= (int) $directory['meta']['total'] ?></span><?php endif; ?></h2><?php if ($dashboardView === 'home'): ?><a class="text-link" href="/?view=spaces"><?= $e($t('View all spaces')) ?> <?= $uiIcon('arrow') ?></a><?php endif; ?></div>
            <?php if ($spaces === []): ?>
                <div class="workspace-empty"><?= $uiIcon('spaces') ?><h3><?= $e($t($dashboardView === 'spaces' ? 'No spaces match these filters.' : 'Your knowledge starts here')) ?></h3><p><?= $e($t($dashboardView === 'spaces' ? 'Try another name or change the filters.' : 'No spaces are available.')) ?></p><?php if ($dashboardView === 'spaces'): ?><a class="button button--secondary" href="/?view=spaces"><?= $e($t('Reset filters')) ?></a><?php elseif ($canCreateSpace): ?><a class="button button--primary" href="/spaces/create"><?= $e($t('Create space')) ?></a><?php endif; ?></div>
            <?php else: ?>
                <div class="space-grid home-space-grid"<?= $dashboardView === 'spaces' ? ' data-directory-layout="' . $e($directory['filters']['layout']) . '"' : '' ?>>
                    <?php foreach ($dashboardView === 'home' ? array_slice($spaces, 0, 6) : $spaces as $homeSpace): ?>
                        <a class="space-card home-space-card" data-space-id="<?= (int) $homeSpace['id'] ?>" href="/spaces/<?= rawurlencode($homeSpace['space_key']) ?>">
                            <span class="space-avatar space-avatar--large space-color-<?= (int) (crc32($homeSpace['space_key']) % 5) ?>" aria-hidden="true"><?= $e($homeSpace['icon'] ?: mb_substr($homeSpace['name'], 0, 1)) ?></span>
                            <strong><?= $e($homeSpace['name']) ?></strong><span class="space-description"><?= $e($homeSpace['description'] ?: $t('A shared place for knowledge')) ?></span>
                            <small><?= $e($homeSpace['space_key']) ?> <span aria-hidden="true">·</span> <?= $e($t(ucfirst($homeSpace['visibility']))) ?><?= $homeSpace['status'] === 'archived' ? ' · ' . $e($t('Archived')) : '' ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($dashboardView === 'spaces'): require __DIR__ . '/../partials/browse-pagination.php'; endif; ?>
        </section>
    <?php endif; ?>
    <?php if (in_array($dashboardView, ['home', 'recent', 'favorites'], true)): ?>
        <section class="home-section" aria-labelledby="home-documents-heading">
            <div class="panel__header"><h2 id="home-documents-heading"><?= $e($t($dashboardView === 'favorites' ? 'Favorites' : ($dashboardView === 'recent' || $recentViewed !== [] ? 'Recently viewed' : 'Recently updated'))) ?></h2></div>
            <?php if ($listPages === []): ?>
                <div class="workspace-empty"><?= $uiIcon($dashboardView === 'favorites' ? 'star' : 'document') ?><h3><?= $e($t($dashboardView === 'favorites' ? 'Keep useful pages close' : 'Make room for your next idea')) ?></h3><p><?= $e($t($dashboardView === 'favorites' ? 'Save a page with Favorite in its actions menu to find it here.' : 'Pages you open will appear here.')) ?></p><a class="button button--secondary" href="/?view=spaces"><?= $e($t('Browse all spaces')) ?></a></div>
            <?php else: ?>
                <ul class="home-document-list">
                    <?php foreach ($listPages as $homePage): ?>
                        <li><a href="/spaces/<?= rawurlencode($homePage['space_key']) ?>/pages/<?= rawurlencode($homePage['slug']) ?>"><span class="document-list-icon"><?= $uiIcon('document') ?></span><span class="document-list-title"><strong><?= $e($homePage['title']) ?></strong><small><?= $e($homePage['space_name']) ?></small></span><span class="document-list-date"><?= $e($homePage['updated_at']) ?></span><?= $uiIcon('chevron') ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <?php if ($dashboardView === 'home' && $currentUser !== null): ?>
            <a class="home-work-link" href="/?view=work"><?= $uiIcon('check') ?><span><strong><?= $e($t('Your work')) ?></strong><small><?= $e($t('Tasks, drafts, and reviews in one place')) ?></small></span><span class="count"><?= (int) $workCenter['meta']['total'] ?></span><?= $uiIcon('arrow') ?></a>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($dashboardView === 'work'): require __DIR__ . '/../partials/work-center.php'; endif; ?>
</section>
