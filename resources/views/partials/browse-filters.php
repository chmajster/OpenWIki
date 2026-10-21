<?php
$browseFilters = $browseResult['filters'];
$browseMeta = $browseResult['meta'];
$browseUrl = static fn (array $changes = []): string => $browseAction . '?' . http_build_query(array_replace($browseFixed, $browseFilters, ['page' => $browseMeta['page']], $changes));
?>
<form class="browse-filters" method="get" action="<?= $e($browseAction) ?>" role="search" aria-label="<?= $e($t($browseKind === 'spaces' ? 'Find a space' : 'Find a page')) ?>" data-browse-filters>
    <?php foreach ($browseFixed as $key => $value): ?><input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>"><?php endforeach; ?>
    <?php if ($browseKind === 'spaces'): ?><input type="hidden" name="layout" value="<?= $e($browseFilters['layout']) ?>"><?php endif; ?>
    <label class="browse-query"><?= $e($t($browseKind === 'spaces' ? 'Find a space' : 'Find a page')) ?><span><?= $uiIcon('search') ?><input name="q" type="search" maxlength="200" value="<?= $e($browseFilters['q']) ?>" placeholder="<?= $e($t($browseKind === 'spaces' ? 'Name, key, or description' : 'Search page titles')) ?>"></span></label>
    <?php if ($browseKind === 'spaces'): ?><label><?= $e($t('Visibility')) ?><select name="visibility"><?php foreach (['all' => 'All', 'public' => 'Public', 'restricted' => 'Restricted', 'private' => 'Private'] as $value => $label): ?><option value="<?= $value ?>"<?= $browseFilters['visibility'] === $value ? ' selected' : '' ?>><?= $e($t($label)) ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <label><?= $e($t('Status')) ?><select name="state"><?php foreach ($browseKind === 'spaces' ? ['all' => 'All', 'active' => 'Active', 'archived' => 'Archived'] : ['all' => 'All', 'published' => 'Published', 'draft' => 'Draft', 'archived' => 'Archived'] as $value => $label): ?><option value="<?= $value ?>"<?= $browseFilters['state'] === $value ? ' selected' : '' ?>><?= $e($t($label)) ?></option><?php endforeach; ?></select></label>
    <label><?= $e($t('Sort')) ?><select name="sort"><?php foreach (['name' => 'Name', 'updated' => 'Recently updated'] as $value => $label): ?><option value="<?= $value ?>"<?= $browseFilters['sort'] === $value ? ' selected' : '' ?>><?= $e($t($label)) ?></option><?php endforeach; ?></select></label>
    <button class="button button--primary" type="submit"><?= $e($t('Apply filters')) ?></button><a class="button button--ghost" href="<?= $e($browseAction . '?' . http_build_query($browseFixed)) ?>"><?= $e($t('Reset filters')) ?></a>
</form>
<div class="browse-summary">
    <p class="muted" role="status" data-listing-total="<?= (int) $browseMeta['total'] ?>"><?= $e(strtr($t('Showing :from–:to of :total'), [':from' => $browseMeta['total'] === 0 ? '0' : (string) (($browseMeta['page'] - 1) * $browseMeta['per_page'] + 1), ':to' => (string) min($browseMeta['total'], $browseMeta['page'] * $browseMeta['per_page']), ':total' => (string) $browseMeta['total']])) ?></p>
    <?php if ($browseKind === 'spaces'): ?><nav class="browse-layout" aria-label="<?= $e($t('Display mode')) ?>"><?php foreach (['cards' => ['spaces', 'Card view'], 'list' => ['list', 'List view']] as $value => [$icon, $label]): ?><a class="shell-icon-button<?= $browseFilters['layout'] === $value ? ' is-active' : '' ?>" href="<?= $e($browseUrl(['layout' => $value])) ?>" aria-label="<?= $e($t($label)) ?>"<?= $browseFilters['layout'] === $value ? ' aria-current="true"' : '' ?>><?= $uiIcon($icon) ?></a><?php endforeach; ?></nav><?php endif; ?>
</div>
