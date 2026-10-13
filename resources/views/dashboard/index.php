<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow"><?= $e($t('Knowledge base')) ?></div>
            <h1><?= $e($t($currentUser === null ? 'Dashboard' : 'Work center')) ?></h1>
            <p class="muted"><?= $e($t('Spaces and documentation available to your account.')) ?></p>
        </div>
        <?php if ($canCreateSpace): ?>
            <a class="button button--primary" href="/spaces/create"><?= $e($t('Create space')) ?></a>
        <?php endif; ?>
    </div>

    <?php if($currentUser !== null): require __DIR__.'/../partials/work-center.php'; endif; ?>
    <div class="dashboard-grid">
        <section class="card panel panel--wide">
            <div class="panel__header"><h2><?= $e($t('Spaces')) ?></h2><span class="count"><?= count($spaces) ?></span></div>
            <?php if ($spaces === []): ?>
                <div class="empty-state"><?= $e($t('No spaces are available.')) ?></div>
            <?php else: ?>
                <div class="space-grid">
                    <?php foreach ($spaces as $space): ?>
                        <a class="space-card" href="/spaces/<?= rawurlencode($space['space_key']) ?>">
                            <span class="space-key"><?= $e($space['space_key']) ?></span>
                            <strong><?= $e($space['name']) ?></strong>
                            <span><?= $e($space['description'] ?: $t('No description')) ?></span>
                            <small><?= $e($t(ucfirst($space['visibility']))) ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="card panel">
            <div class="panel__header"><h2><?= $e($t('Recently updated')) ?></h2></div>
            <?php if ($recentUpdated === []): ?>
                <div class="empty-state"><?= $e($t('No recent updates.')) ?></div>
            <?php else: ?>
                <ul class="item-list">
                    <?php foreach ($recentUpdated as $page): ?>
                        <li>
                            <a href="/spaces/<?= rawurlencode($page['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>">
                                <strong><?= $e($page['title']) ?></strong>
                                <span><?= $e($page['space_name']) ?> · <?= $e($page['updated_at']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if ($currentUser !== null): ?>
            <section class="card panel">
                <div class="panel__header"><h2><?= $e($t('Recently viewed')) ?></h2></div>
                <?php if ($recentViewed === []): ?>
                    <div class="empty-state"><?= $e($t('Pages you open will appear here.')) ?></div>
                <?php else: ?>
                    <ul class="item-list">
                        <?php foreach ($recentViewed as $page): ?>
                            <li>
                                <a href="/spaces/<?= rawurlencode($page['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>">
                                    <strong><?= $e($page['title']) ?></strong>
                                    <span><?= $e($page['space_name']) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="card panel">
                <div class="panel__header"><h2><?= $e($t('Favorites')) ?></h2></div>
                <?php if ($favorites === []): ?>
                    <div class="empty-state"><?= $e($t('No favorite pages.')) ?></div>
                <?php else: ?>
                    <ul class="item-list">
                        <?php foreach ($favorites as $page): ?>
                            <li>
                                <a href="/spaces/<?= rawurlencode($page['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>">
                                    <strong><?= $e($page['title']) ?></strong>
                                    <span><?= $e($page['space_name']) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

        <?php endif; ?>
    </div>
</section>
