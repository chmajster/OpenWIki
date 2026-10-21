<?php $spaceBase = '/spaces/' . rawurlencode($space['space_key']); ?>
<nav class="workspace-tabs" aria-label="<?= $e($t('Space navigation')) ?>">
    <a href="<?= $e($spaceBase) ?>"<?= $spaceTab === 'overview' ? ' aria-current="page"' : '' ?>><?= $e($t('Overview')) ?></a>
    <a href="<?= $e($spaceBase) ?>?tab=content"<?= $spaceTab === 'content' ? ' aria-current="page"' : '' ?>><?= $e($t('Content')) ?></a>
    <?php if ($canCreatePage): ?><a href="<?= $e($spaceBase) ?>/templates"<?= $spaceTab === 'templates' ? ' aria-current="page"' : '' ?>><?= $e($t('Templates')) ?></a><?php endif; ?>
    <a href="/search?space=<?= rawurlencode($space['space_key']) ?>&amp;type=page"<?= $spaceTab === 'search' ? ' aria-current="page"' : '' ?>><?= $e($t('Search')) ?></a>
</nav>
