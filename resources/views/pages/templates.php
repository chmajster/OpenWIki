<?php
require __DIR__ . '/../partials/ui-icons.php';
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$base = '/spaces/' . rawurlencode($space['space_key']);
$parentQuery = $parent === null ? [] : ['parent' => (int) $parent['id']];
$filters = $listing['filters'];
$browseMeta = $listing['meta'];
$browseUrl = static fn (array $change = []): string => $base . '/templates?' . http_build_query(array_replace($filters + $parentQuery + ['page' => $browseMeta['page']], $change));
$createUrl = static function (?array $row = null) use ($base, $parentQuery): string {
    $query = $parentQuery + ($row === null ? [] : ['template' => (string) $row['id']]);
    return $base . '/pages/create' . ($query === [] ? '' : '?' . http_build_query($query));
};
$categoryIcons = ['technical' => 'document', 'knowledge' => 'book', 'team' => 'spaces', 'operations' => 'check', 'custom' => 'star'];
?>
<section class="wiki-layout space-overview">
<article class="wiki-content template-gallery" data-template-gallery>
    <?php require __DIR__ . '/../partials/space-header.php'; ?>
    <header class="space-tab-heading">
        <div><h2><?= $e($t('Create from a template')) ?></h2><p class="muted"><?= $e($t('Give your next document a useful starting point.')) ?></p>
            <?php if ($parent !== null): ?><p class="template-parent"><?= $e($t('Parent page')) ?>: <strong><?= $e($parent['title']) ?></strong></p><?php endif; ?>
        </div>
        <a class="button button--primary" href="<?= $e($createUrl()) ?>"><?= $uiIcon('plus') ?><?= $e($t('Blank page')) ?></a>
    </header>
    <form method="get" action="<?= $e($base) ?>/templates" class="browse-filters template-gallery-filters" role="search" data-template-gallery-filters>
        <?php if ($parent !== null): ?><input type="hidden" name="parent" value="<?= (int) $parent['id'] ?>"><?php endif; ?>
        <label class="browse-query"><?= $e($t('Find a template')) ?><span><?= $uiIcon('search') ?><input type="search" name="q" maxlength="200" value="<?= $e($filters['q']) ?>" placeholder="<?= $e($t('Name or purpose')) ?>"></span></label>
        <label><?= $e($t('Category')) ?><select name="category"><?php foreach (\OpenWiki\Wiki\TemplateGallery::CATEGORIES as $key => $label): ?><option value="<?= $e($key) ?>"<?= $filters['category'] === $key ? ' selected' : '' ?>><?= $e($t($label)) ?></option><?php endforeach; ?></select></label>
        <button class="button button--secondary" type="submit"><?= $e($t('Apply filters')) ?></button>
        <a class="button button--ghost" href="<?= $e($base . '/templates' . ($parentQuery === [] ? '' : '?' . http_build_query($parentQuery))) ?>"><?= $e($t('Reset filters')) ?></a>
    </form>
    <div class="browse-summary"><p class="muted" data-template-total="<?= $browseMeta['total'] ?>"><?= $e(strtr($t('Showing :from–:to of :total'), [':from' => $browseMeta['from'], ':to' => $browseMeta['to'], ':total' => $browseMeta['total']])) ?></p></div>
    <div class="template-gallery-layout<?= $preview === null ? '' : ' template-gallery-layout--preview' ?>">
        <div>
            <?php if ($listing['data'] === []): ?><div class="workspace-empty"><?= $uiIcon('search') ?><h2><?= $e($t('No templates match these filters.')) ?></h2><p><?= $e($t('Try another name or change the filters.')) ?></p></div><?php endif; ?>
            <div class="template-gallery-grid">
                <?php foreach ($listing['data'] as $row): ?>
                    <article class="template-gallery-card<?= $preview !== null && (string) $preview['id'] === (string) $row['id'] ? ' is-selected' : '' ?>" data-gallery-template="<?= $e($row['id']) ?>">
                        <div class="template-gallery-thumbnail template-category--<?= $e($row['category_key']) ?>" aria-hidden="true">
                            <?= $uiIcon($categoryIcons[$row['category_key']] ?? 'document') ?>
                            <div class="template-miniature"><strong><?= $e($row['display_name']) ?></strong>
                            <?php preg_match_all('/<h[1-3]\b[^>]*>(.*?)<\/h[1-3]>/isu', $row['content_html'], $headings); foreach (array_slice($headings[1], 0, 3) as $heading): ?><span><?= $e(html_entity_decode(strip_tags($heading), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?></span><i></i><?php endforeach; ?>
                            </div>
                        </div>
                        <div class="template-gallery-description"><span class="template-gallery-category"><?= $e($row['category']) ?></span><h2><?= $e($row['display_name']) ?></h2><p><?= $e($row['summary']) ?></p></div>
                        <div class="template-gallery-actions"><a class="button button--ghost" href="<?= $e($browseUrl(['preview' => (string) $row['id']])) ?>#template-preview"><?= $e($t('Preview')) ?></a><a class="button button--secondary" href="<?= $e($createUrl($row)) ?>"><?= $e($t('Use template')) ?></a></div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php require __DIR__ . '/../partials/browse-pagination.php'; ?>
        </div>
        <?php if ($preview !== null): ?>
            <section class="template-full-preview" id="template-preview" aria-labelledby="template-preview-title" tabindex="-1">
                <header><div><span class="template-gallery-category"><?= $e($preview['category']) ?></span><h2 id="template-preview-title"><?= $e($preview['display_name']) ?></h2></div><a class="shell-icon-button" href="<?= $e($browseUrl()) ?>" aria-label="<?= $e($t('Close preview')) ?>"><?= $uiIcon('close') ?></a></header>
                <p class="muted"><?= $e($preview['summary']) ?></p>
                <a class="button button--primary" href="<?= $e($createUrl($preview)) ?>"><?= $e($t('Use template')) ?></a>
                <div class="document document-content template-preview-document" data-gallery-preview><?= $preview['content_html'] ?></div>
            </section>
        <?php endif; ?>
    </div>
</article>
</section>
