<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Administration</div>
            <h1>Page templates</h1>
            <p class="muted">System templates are read-only. Custom templates are immediately available when creating a page.</p>
        </div>
        <a class="button button--primary" href="/admin/templates/create">Create template</a>
    </div>

    <div class="card panel">
        <div class="panel__header">
            <h2>Templates</h2>
            <span class="count"><?= count($templates) ?></span>
        </div>
        <div class="item-list">
            <?php foreach ($templates as $template): ?>
                <div class="item-row">
                    <div>
                        <strong><?= $e($template['name']) ?></strong>
                        <div><?= $e($template['description'] ?? '') ?></div>
                        <small>
                            <?= $template['is_system'] ? 'System' : 'Custom' ?>
                            <?php if (!$template['is_system'] && !empty($template['creator_username'])): ?>
                                · <?= $e($template['creator_username']) ?>
                            <?php endif; ?>
                            · updated <?= $e($template['updated_at']) ?>
                        </small>
                    </div>
                    <a class="button button--ghost" href="/admin/templates/<?= (int) $template['id'] ?>/edit">
                        <?= $template['is_system'] ? 'View' : 'Edit' ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
