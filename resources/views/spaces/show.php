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
            <?php if ($pages === []): ?>
                <div class="sidebar-empty">No pages yet.</div>
            <?php else: ?>
                <?php $activePageId = null; require __DIR__ . '/../partials/page-tree.php'; ?>
            <?php endif; ?>
        </nav>
        <?php if ($canCreatePage): ?>
            <a class="button button--secondary button--block" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/create">Create page</a>
        <?php endif; ?>
    </aside>

    <article class="wiki-content">
        <div class="page-heading">
            <div>
                <div class="eyebrow"><?= $e($space['visibility']) ?> space</div>
                <h1><?= $e($space['name']) ?></h1>
                <?php if ($space['description']): ?>
                    <p class="lead"><?= nl2br($e($space['description'])) ?></p>
                <?php endif; ?>
            </div>
            <?php if ($canCreatePage): ?>
                <a class="button button--primary" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/create">Create page</a>
            <?php endif; ?>
        </div>

        <?php if ($pages === []): ?>
            <div class="card empty-hero">
                <h2>This space has no pages</h2>
                <p>Create the first page to start building its documentation tree.</p>
            </div>
        <?php else: ?>
            <div class="card panel">
                <div class="panel__header"><h2>Pages</h2><span class="count"><?= count($pages) ?></span></div>
                <?php $activePageId = null; require __DIR__ . '/../partials/page-tree.php'; ?>
            </div>
        <?php endif; ?>
    </article>
</section>
