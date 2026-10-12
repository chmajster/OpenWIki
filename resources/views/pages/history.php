<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="breadcrumbs">
                <a href="/spaces/<?= rawurlencode($space['space_key']) ?>"><?= $e($space['name']) ?></a>
                <span>/</span>
                <a href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>"><?= $e($page['title']) ?></a>
                <span>/</span>
                <span><?= $e($t('History')) ?></span>
            </div>
            <h1><?= $e($t('Revision history')) ?></h1>
            <p class="muted"><?= $e($t('Every explicit save is retained as an immutable revision.')) ?></p>
        </div>
        <a class="button button--ghost" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>"><?= $e($t('Back to page')) ?></a>
    </div>

    <div class="card panel">
        <div class="revision-list">
            <?php foreach ($revisions as $revision): ?>
                <article class="revision-row">
                    <div>
                        <a href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/revisions/<?= (int) $revision['revision_number'] ?>"><strong><?= $e($t('Version')) ?> <?= (int) $revision['revision_number'] ?></strong></a>
                        <span class="status-pill status-pill--<?= $e($revision['status']) ?>"><?= $e($t($revision['status'])) ?></span>
                        <p><?= $e($revision['change_summary'] ?: $t('No change summary')) ?></p>
                        <small><?= $e($revision['username']) ?> · <?= $e($revision['created_at']) ?></small>
                    </div>
                    <?php if ($canRestore && (int) $revision['revision_number'] !== (int) $page['version']): ?>
                        <form method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/restore">
                            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="revision" value="<?= (int) $revision['revision_number'] ?>">
                            <input type="hidden" name="base_version" value="<?= (int) $page['version'] ?>">
                            <button class="button button--secondary" type="submit"><?= $e($t('Restore')) ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if ($canRestore && !empty($revision['conversion_backup_json'])): ?>
                        <form method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/restore">
                            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="revision" value="<?= (int) $revision['revision_number'] ?>">
                            <input type="hidden" name="base_version" value="<?= (int) $page['version'] ?>">
                            <input type="hidden" name="restore_conversion" value="1">
                            <button class="button button--secondary" type="submit"><?= $e($t('Restore content before conversion')) ?></button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
