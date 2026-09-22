<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Knowledge base</div>
            <h1>Dashboard</h1>
            <p class="muted">Spaces and documentation available to your account.</p>
        </div>
        <?php if ($canCreateSpace): ?>
            <a class="button button--primary" href="/spaces/create">Create space</a>
        <?php endif; ?>
    </div>

    <div class="dashboard-grid">
        <section class="card panel panel--wide">
            <div class="panel__header"><h2>Spaces</h2><span class="count"><?= count($spaces) ?></span></div>
            <?php if ($spaces === []): ?>
                <div class="empty-state">No spaces are available.</div>
            <?php else: ?>
                <div class="space-grid">
                    <?php foreach ($spaces as $space): ?>
                        <a class="space-card" href="/spaces/<?= rawurlencode($space['space_key']) ?>">
                            <span class="space-key"><?= $e($space['space_key']) ?></span>
                            <strong><?= $e($space['name']) ?></strong>
                            <span><?= $e($space['description'] ?: 'No description') ?></span>
                            <small><?= $e(ucfirst($space['visibility'])) ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="card panel">
            <div class="panel__header"><h2>Recently updated</h2></div>
            <?php if ($recentUpdated === []): ?>
                <div class="empty-state">No recent updates.</div>
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
                <div class="panel__header"><h2>Recently viewed</h2></div>
                <?php if ($recentViewed === []): ?>
                    <div class="empty-state">Pages you open will appear here.</div>
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
                <div class="panel__header"><h2>My drafts</h2></div>
                <?php if ($drafts === []): ?>
                    <div class="empty-state">No drafts.</div>
                <?php else: ?>
                    <ul class="item-list">
                        <?php foreach ($drafts as $page): ?>
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
        <?php endif; ?>
    </div>
</section>
