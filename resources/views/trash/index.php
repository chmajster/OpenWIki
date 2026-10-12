<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Wiki</div>
            <h1><?= $e($t('Trash')) ?></h1>
            <p class="muted"><?= $e($t('Deleted subtrees retain their hierarchy and access restrictions. Restore the parent first.')) ?></p>
        </div>
    </div>

    <section class="card panel">
        <div class="panel__header"><h2><?= $e($t('Deleted pages')) ?></h2><span class="count"><?= count($pages) ?></span></div>
        <?php if ($pages === []): ?>
            <div class="empty-state"><?= $e($t('Trash is empty.')) ?></div>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead><tr><th><?= $e($t('Page')) ?></th><th><?= $e($t('Space')) ?></th><th><?= $e($t('Deleted')) ?></th><th><?= $e($t('Actions')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($pages as $page): ?>
                        <tr>
                            <td><strong><?= $e($page['title']) ?></strong><br><small><?= $e($page['slug']) ?></small></td>
                            <td><?= $e($page['space_name']) ?> <span class="space-key"><?= $e($page['space_key']) ?></span></td>
                            <td><?= $e($page['deleted_at']) ?></td>
                            <td>
                                <div class="page-actions">
                                    <form method="post" action="/trash/<?= (int) $page['id'] ?>/restore">
                                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                        <button class="button button--secondary" type="submit"><?= $e($t('Restore')) ?></button>
                                    </form>
                                    <form method="post" action="/trash/<?= (int) $page['id'] ?>/delete" data-confirm="<?= $e($t('Permanently delete this subtree and all its attachments? This cannot be undone.')) ?>">
                                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                        <button class="button button--ghost" type="submit"><?= $e($t('Delete permanently')) ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
