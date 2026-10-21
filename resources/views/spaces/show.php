<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
require __DIR__ . '/../partials/ui-icons.php';
?>
<section class="wiki-layout space-overview">
    <article class="wiki-content">
        <?php require __DIR__ . '/../partials/space-header.php'; ?>
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
