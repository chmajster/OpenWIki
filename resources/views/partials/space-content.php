<section data-space-content aria-label="<?= $e($t('Space content')) ?>">
    <?php $browseResult = $contentListing; $browseKind = 'pages'; $browseAction = '/spaces/' . rawurlencode($space['space_key']); $browseFixed = ['tab' => 'content']; require __DIR__ . '/browse-filters.php'; ?>
    <?php if ($contentListing['data'] === []): ?>
        <div class="workspace-empty"><?= $uiIcon('document') ?><h2><?= $e($t('No pages match these filters.')) ?></h2><p><?= $e($t('Try another name or change the filters.')) ?></p><a class="button button--secondary" href="<?= $e($browseAction) ?>?tab=content"><?= $e($t('Reset filters')) ?></a><?php if ($canCreatePage): ?><a class="button button--primary" href="<?= $e($browseAction) ?>/pages/create"><?= $e($t('Create page')) ?></a><?php endif; ?></div>
    <?php else: ?>
        <ul class="home-document-list content-document-list">
            <?php foreach ($contentListing['data'] as $contentItem): ?>
                <li data-content-page="<?= (int) $contentItem['id'] ?>"><a href="<?= $e($contentItem['url']) ?>"><span class="document-list-icon"><?= $uiIcon('document') ?></span><span class="document-list-title"><strong><?= $e($contentItem['title']) ?></strong><small><?= $e($space['name']) ?></small></span><span class="status-pill status-pill--<?= $e($contentItem['status']) ?>"><?= $e($t($contentItem['status'])) ?></span><span class="document-list-date"><?= $e($contentItem['updated_at']) ?></span><?= $uiIcon('chevron') ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php require __DIR__ . '/browse-pagination.php'; ?>
</section>
