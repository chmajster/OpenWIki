<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="wiki-layout">
    <aside class="wiki-sidebar">
        <div class="sidebar-heading">
            <a href="/spaces/<?= rawurlencode($space['space_key']) ?>">
                <span class="space-key"><?= $e($space['space_key']) ?></span>
                <strong><?= $e($space['name']) ?></strong>
            </a>
        </div>
        <nav aria-label="Pages">
            <?php $activePageId = (int) $page['id']; require __DIR__ . '/../partials/page-tree.php'; ?>
        </nav>
    </aside>

    <article class="wiki-content">
        <div class="page-heading page-heading--compact">
            <div>
                <div class="breadcrumbs">
                    <a href="/spaces/<?= rawurlencode($space['space_key']) ?>"><?= $e($space['name']) ?></a>
                    <span>/</span>
                    <span><?= $e($page['title']) ?></span>
                </div>
                <h1><?= $e($page['title']) ?></h1>
                <div class="page-meta">
                    <span class="status-pill status-pill--<?= $e($page['status']) ?>"><?= $e($page['status']) ?></span>
                    <span>Version <?= (int) $page['version'] ?></span>
                    <span>Updated <?= $e($page['updated_at']) ?></span>
                    <span>by <?= $e($page['author_username']) ?></span>
                </div>
            </div>
            <div class="page-actions">
                <a class="button button--ghost" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/history">History</a>
                <?php if ($canEdit): ?>
                    <a class="button button--primary" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/edit">Edit</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="document card" data-document-content>
            <?= $page['content_html'] ?>
        </div>
    </article>
</section>
