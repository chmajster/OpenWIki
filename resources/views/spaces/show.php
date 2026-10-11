<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="wiki-layout">
    <details class="wiki-sidebar" data-page-panel open>
        <summary class="sidebar-toggle"><?= $e($t('Pages')) ?></summary>
        <div class="sidebar-body">
        <div class="sidebar-heading">
            <a href="/spaces/<?= rawurlencode($space['space_key']) ?>">
                <span class="space-key"><?= $e($space['space_key']) ?></span>
                <strong><?= $e($space['name']) ?></strong>
            </a>
        </div>
        <nav aria-label="<?= $e($t('Pages')) ?>">
            <?php if ($pages === []): ?>
                <div class="sidebar-empty"><?= $e($t('No pages yet.')) ?></div>
            <?php else: ?>
                <?php $activePageId = null; require __DIR__ . '/../partials/page-tree.php'; ?>
            <?php endif; ?>
        </nav>
        <?php if ($canCreatePage): ?>
            <a class="button button--secondary button--block" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/create"><?= $e($t('Create page')) ?></a>
        <?php endif; ?>
        </div>
    </details>

    <article class="wiki-content">
        <div class="page-heading">
            <div>
                <div class="eyebrow"><?= $e($space['visibility']) ?> space</div>
                <h1><?= $e($space['name']) ?></h1>
                <?php if ($space['description']): ?>
                    <p class="lead"><?= nl2br($e($space['description'])) ?></p>
                <?php endif; ?>
            </div>
            <div class="page-actions">
                <?php if ($currentUser !== null): ?>
                    <form class="inline-form" method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/watch">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="enabled" value="<?= $isWatchingSpace ? '0' : '1' ?>">
                        <button class="button button--ghost" type="submit"><?= $e($t($isWatchingSpace ? 'Stop watching' : 'Watch space')) ?></button>
                    </form>
                <?php endif; ?>
                <a class="button button--ghost" href="/spaces/<?= rawurlencode($space['space_key']) ?>/export"><?= $e($t('Export ZIP')) ?></a>
                <?php if ($canCreatePage): ?>
                    <a class="button button--ghost" href="/spaces/<?= rawurlencode($space['space_key']) ?>/import"><?= $e($t('Import')) ?></a>
                    <a class="button button--primary" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/create"><?= $e($t('Create page')) ?></a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($pages === []): ?>
            <div class="card empty-hero">
                <h2><?= $e($t('This space has no pages')) ?></h2>
                <p><?= $e($t('Create the first page to start building its documentation tree.')) ?></p>
            </div>
        <?php else: ?>
            <div class="card panel">
                <div class="panel__header"><h2><?= $e($t('Pages')) ?></h2><span class="count"><?= count($pages) ?></span></div>
                <?php $activePageId = null; require __DIR__ . '/../partials/page-tree.php'; ?>
            </div>
        <?php endif; ?>
    </article>
</section>
