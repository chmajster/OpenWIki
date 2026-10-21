<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
require __DIR__ . '/../partials/ui-icons.php';
?>
<section class="wiki-layout space-overview">
    <article class="wiki-content">
        <div class="page-heading<?= $spaceTab === 'content' ? ' space-heading--content' : '' ?>">
            <div>
                <div class="breadcrumbs"><a href="/?view=spaces"><?= $e($t('Spaces')) ?></a><span>/</span><span><?= $e($space['space_key']) ?></span></div>
                <?php if ($spaceTab === 'overview'): ?><div class="space-overview-badge"><span class="space-avatar space-avatar--large" aria-hidden="true"><?= $e($space['icon'] ?: mb_substr($space['name'], 0, 1)) ?></span><span class="status-pill"><?= $e($t(ucfirst($space['visibility']))) ?> · <?= $e($t($space['status'] === 'archived' ? 'Archived' : 'Active')) ?></span></div><?php endif; ?>
                <h1><?= $e($space['name']) ?></h1>
                <?php if ($space['description'] && $spaceTab === 'overview'): ?>
                    <p class="lead"><?= nl2br($e($space['description'])) ?></p>
                <?php endif; ?>
            </div>
            <div class="page-actions">
                <details class="space-action-menu" data-close-on-escape><summary class="button button--secondary"><?= $e($t('More actions')) ?></summary><div class="menu-items">
                <?php if ($canSettings): ?><a class="button button--secondary" href="/spaces/<?= rawurlencode($space['space_key']) ?>/settings"><?= $e($t('Space settings')) ?></a><?php endif; ?>
                <?php if ($currentUser !== null): ?>
                    <a class="button button--ghost" href="/?attention=1&amp;space=<?= (int)$space['id'] ?>"><?= $e($t('Documents needing attention')) ?></a>
                    <form class="inline-form" method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/watch">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="enabled" value="<?= $isWatchingSpace ? '0' : '1' ?>">
                        <button class="button button--ghost" type="submit"><?= $e($t($isWatchingSpace ? 'Stop watching' : 'Watch space')) ?></button>
                    </form>
                <?php endif; ?>
                <a class="button button--ghost" href="/spaces/<?= rawurlencode($space['space_key']) ?>/export"><?= $e($t('Export ZIP')) ?></a>
                <?php if ($canCreatePage): ?>
                    <a class="button button--ghost" href="/spaces/<?= rawurlencode($space['space_key']) ?>/import"><?= $e($t('Import')) ?></a>
                <?php endif; ?>
                </div></details>
                <?php if ($canCreatePage): ?><a class="button button--primary" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/create"><?= $e($t('Create page')) ?></a><?php endif; ?>
            </div>
        </div>

        <?php require __DIR__ . '/../partials/space-tabs.php'; ?>
        <?php if ($spaceTab === 'content'): require __DIR__ . '/../partials/space-content.php'; else: ?>
        <div class="space-welcome card panel">
            <span class="space-welcome-icon" aria-hidden="true"><?= $uiIcon('book') ?></span>
            <?php if ($homePage !== null): ?><h2><?= $e($t('Home page')) ?></h2><a href="<?= $e(\OpenWiki\Spaces\DocumentationTreeService::url($space, $homePage)) ?>"><?= $e($homePage['title']) ?></a><?php else: ?><h2><?= $e($t('Documentation')) ?></h2><p><?= $e($t('Choose a page from the tree or browse all pages in this space.')) ?></p><?php endif; ?>
            <?php if ($space['status'] === 'archived'): ?><p role="status"><?= $e($t('This archived space is read-only. Restore it in space settings to edit its documents.')) ?></p><?php endif; ?>
            <div class="collaboration-actions"><button class="button button--secondary" type="button" data-navigation-open hidden><?= $uiIcon('panel') ?><?= $e($t('Browse pages')) ?></button><a class="button button--secondary" href="/spaces/<?= rawurlencode($space['space_key']) ?>?tab=content"><?= $uiIcon('document') ?><?= $e($t('All pages')) ?></a><a class="button button--ghost" href="/search?space=<?= rawurlencode($space['space_key']) ?>&amp;type=page"><?= $uiIcon('search') ?><?= $e($t('Search this space')) ?></a><?php if ($canCreatePage): ?><a class="button button--primary" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/create"><?= $uiIcon('plus') ?><?= $e($t('Create page')) ?></a><?php endif; ?></div>
        </div>
        <?php endif; ?>
    </article>
</section>
