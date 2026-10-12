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

    <form method="get" class="card panel form-grid"><label>Od rewizji<select name="from" required><?php foreach (array_reverse($revisions) as $r): ?><option value="<?= (int) $r['revision_number'] ?>" <?= (int) ($diff['from'] ?? 0) === (int) $r['revision_number'] ? 'selected' : '' ?>><?= (int) $r['revision_number'] ?></option><?php endforeach; ?></select></label><label>Do rewizji<select name="to" required><?php foreach ($revisions as $r): ?><option value="<?= (int) $r['revision_number'] ?>" <?= (int) ($diff['to'] ?? 0) === (int) $r['revision_number'] ? 'selected' : '' ?>><?= (int) $r['revision_number'] ?></option><?php endforeach; ?></select></label><button class="button">Porównaj rewizje</button></form>
    <?php if ($diff !== null): ?><section class="card panel" data-revision-diff><h2>Porównanie treści i metadanych</h2><?php if (isset($diff['error'])): ?><p role="alert"><?= $e($diff['error']) ?></p><?php else: ?><dl><?php foreach ($diff['metadata'] as $key => $change): ?><dt><?= $e($key) ?></dt><dd><del><?= $e(json_encode($change['before'], JSON_UNESCAPED_UNICODE)) ?></del> → <ins><?= $e(json_encode($change['after'], JSON_UNESCAPED_UNICODE)) ?></ins></dd><?php endforeach; ?></dl><pre class="revision-diff"><?php foreach ($diff['document'] as [$kind, $line]): ?><span class="diff-<?= $e($kind) ?>"><?= $kind === 'added' ? '+' : ($kind === 'removed' ? '−' : ' ') ?> <?= $e($line) ?><?= "\n" ?></span><?php endforeach; ?></pre><?php endif; ?></section><?php endif; ?>
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
                    <?php $restoreAllowed = $canRestore && ($revision['status'] !== 'published' || $canRestorePublished) && ($revision['status'] !== 'archived' || $canRestoreArchived); ?>
                    <?php if ($restoreAllowed && (int) $revision['revision_number'] !== (int) $page['version']): ?>
                        <form method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/restore">
                            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="revision" value="<?= (int) $revision['revision_number'] ?>">
                            <input type="hidden" name="base_version" value="<?= (int) $page['version'] ?>">
                            <button class="button button--secondary" type="submit"><?= $e($t('Restore')) ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if ($restoreAllowed && !empty($revision['has_conversion_backup'])): ?>
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
