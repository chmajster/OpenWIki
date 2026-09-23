<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Tag</div>
            <h1>#<?= $e($tag['name']) ?></h1>
            <p class="muted"><?= count($pages) ?> accessible page<?= count($pages) === 1 ? '' : 's' ?>.</p>
        </div>
    </div>

    <section class="card panel">
        <?php if ($pages === []): ?>
            <div class="empty-state">No accessible pages use this tag.</div>
        <?php else: ?>
            <ul class="item-list">
                <?php foreach ($pages as $page): ?>
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
</section>
