<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$currentVersion = (int) $page['version'];
?>
<section class="content-container revision-history-page">
    <div class="page-heading revision-history-heading">
        <div>
            <div class="breadcrumbs">
                <a href="/spaces/<?= rawurlencode($space['space_key']) ?>"><?= $e($space['name']) ?></a>
                <span>/</span>
                <a href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>"><?= $e($page['title']) ?></a>
                <span>/</span>
                <span><?= $e($t('History')) ?></span>
            </div>
            <h1><?= $e($t('Revision history')) ?></h1>
            <p class="muted">Każdy zapis strony tworzy osobną wersję, którą możesz przejrzeć lub przywrócić.</p>
        </div>
        <a class="button button--secondary revision-history-back" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>">
            <span aria-hidden="true">←</span> <?= $e($t('Back to page')) ?>
        </a>
    </div>

    <form method="get" class="card panel revision-compare-form">
        <div class="revision-section-heading">
            <div>
                <h2>Porównaj wersje</h2>
                <p class="muted">Wybierz dwa zapisy, aby zobaczyć różnice w treści i ustawieniach strony.</p>
            </div>
        </div>
        <div class="revision-compare-form__fields">
            <label>
                <span>Od wersji</span>
                <select name="from" required>
                    <?php foreach (array_reverse($revisions) as $revisionOption): ?>
                        <option value="<?= (int) $revisionOption['revision_number'] ?>" <?= (int) ($diff['from'] ?? 0) === (int) $revisionOption['revision_number'] ? 'selected' : '' ?>>
                            Wersja <?= (int) $revisionOption['revision_number'] ?> · <?= $e($revisionOption['created_at']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Do wersji</span>
                <select name="to" required>
                    <?php foreach ($revisions as $revisionOption): ?>
                        <option value="<?= (int) $revisionOption['revision_number'] ?>" <?= (int) ($diff['to'] ?? 0) === (int) $revisionOption['revision_number'] ? 'selected' : '' ?>>
                            Wersja <?= (int) $revisionOption['revision_number'] ?> · <?= $e($revisionOption['created_at']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="button button--primary" type="submit">Porównaj wersje</button>
        </div>
    </form>

    <?php if ($diff !== null): ?>
        <section class="card panel revision-diff-panel" data-revision-diff>
            <div class="revision-section-heading">
                <div>
                    <h2>Różnice między wersjami</h2>
                    <p class="muted">Zmiany w metadanych i treści strony.</p>
                </div>
            </div>
            <?php if (isset($diff['error'])): ?>
                <p class="revision-diff-error" role="alert"><?= $e($diff['error']) ?></p>
            <?php else: ?>
                <?php if (!empty($diff['metadata'])): ?>
                    <dl class="revision-metadata-diff">
                        <?php foreach ($diff['metadata'] as $key => $change): ?>
                            <div>
                                <dt><?= $e($key) ?></dt>
                                <dd><del><?= $e(json_encode($change['before'], JSON_UNESCAPED_UNICODE)) ?></del><span aria-hidden="true">→</span><ins><?= $e(json_encode($change['after'], JSON_UNESCAPED_UNICODE)) ?></ins></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
                <pre class="revision-diff"><?php foreach ($diff['document'] as [$kind, $line]): ?><span class="diff-<?= $e($kind) ?>"><?= $kind === 'added' ? '+' : ($kind === 'removed' ? '−' : ' ') ?> <?= $e($line) ?><?= "\n" ?></span><?php endforeach; ?></pre>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card panel revision-history-list" aria-labelledby="revision-list-heading">
        <div class="revision-list-heading">
            <div>
                <h2 id="revision-list-heading">Wszystkie wersje</h2>
                <p class="muted">Najnowsze zapisy są wyświetlane jako pierwsze.</p>
            </div>
            <span class="revision-count">Wersje: <?= count($revisions) ?></span>
        </div>
        <div class="revision-list">
            <?php foreach ($revisions as $revision): ?>
                <?php $isCurrentVersion = (int) $revision['revision_number'] === $currentVersion; ?>
                <?php $restoreAllowed = $canRestore && ($revision['status'] !== 'published' || $canRestorePublished) && ($revision['status'] !== 'archived' || $canRestoreArchived); ?>
                <article class="revision-row<?= $isCurrentVersion ? ' revision-row--current' : '' ?>">
                    <div class="revision-row__content">
                        <div class="revision-row__title">
                            <a href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/revisions/<?= (int) $revision['revision_number'] ?>">
                                <strong><?= $e($t('Version')) ?> <?= (int) $revision['revision_number'] ?></strong>
                            </a>
                            <span class="status-pill status-pill--<?= $e($revision['status']) ?>"><?= $e($t($revision['status'])) ?></span>
                            <?php if ($isCurrentVersion): ?><span class="revision-current-pill">Bieżąca wersja</span><?php endif; ?>
                        </div>
                        <p class="revision-row__summary"><?= $e($revision['change_summary'] ?: $t('No change summary')) ?></p>
                        <small class="revision-row__meta"><span><?= $e($revision['username']) ?></span><span aria-hidden="true">·</span><time><?= $e($revision['created_at']) ?></time></small>
                    </div>
                    <?php if (($restoreAllowed && !$isCurrentVersion) || ($restoreAllowed && !empty($revision['has_conversion_backup']))): ?>
                        <div class="revision-row__actions">
                            <?php if ($restoreAllowed && !$isCurrentVersion): ?>
                                <form method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/restore">
                                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                    <input type="hidden" name="revision" value="<?= (int) $revision['revision_number'] ?>">
                                    <input type="hidden" name="base_version" value="<?= $currentVersion ?>">
                                    <button class="button button--secondary" type="submit"><?= $e($t('Restore')) ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($restoreAllowed && !empty($revision['has_conversion_backup'])): ?>
                                <form method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/restore">
                                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                    <input type="hidden" name="revision" value="<?= (int) $revision['revision_number'] ?>">
                                    <input type="hidden" name="base_version" value="<?= $currentVersion ?>">
                                    <input type="hidden" name="restore_conversion" value="1">
                                    <button class="button button--secondary" type="submit"><?= $e($t('Restore content before conversion')) ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</section>
