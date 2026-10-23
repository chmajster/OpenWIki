<?php
require __DIR__ . '/../partials/ui-icons.php';
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$highlight = static function (mixed $value, string $query) use ($e): string {
    $escaped = $e($value);
    $escapedQuery = $e($query);
    if ($escapedQuery === '') {
        return $escaped;
    }

    $pattern = '~' . preg_quote($escapedQuery, '~') . '~iu';
    $highlighted = preg_replace($pattern, '<mark>$0</mark>', $escaped);
    return is_string($highlighted) ? $highlighted : $escaped;
};
$filters = $filters ?? [];
$defaultSearchType = $space === null ? 'all' : 'page';
$searchAdvancedOpen = ($space !== null && $type !== $defaultSearchType)
    || ($space === null && (($filters['visibility'] ?? 'all') !== 'all'
    || !empty($filters['created_from'])
    || !empty($filters['created_to'])
    || !empty($filters['updated_from'])
    || !empty($filters['updated_to'])
    || (int) ($searchMeta['per_page'] ?? 25) !== 25))
    || ($space !== null && (!empty($filters['space']) || !empty($filters['author']) || !empty($filters['tag']) || ($filters['visibility'] ?? 'all') !== 'all' || !empty($filters['created_from']) || !empty($filters['created_to']) || !empty($filters['updated_from']) || !empty($filters['updated_to']) || (int) ($searchMeta['per_page'] ?? 25) !== 25));
$searchResetQuery = $space === null ? [] : ['space' => $space['space_key'], 'type' => 'page'];
if ($query !== '') $searchResetQuery['q'] = $query;
$searchResetUrl = '/search' . ($searchResetQuery === [] ? '' : '?' . http_build_query($searchResetQuery));
$typeLabels = [
    'all' => 'All',
    'page' => 'Pages',
    'space' => 'Spaces',
    'user' => 'Users',
    'comment' => 'Comments',
    'attachment' => 'Attachments',
    'tag' => 'Tags',
];
?>
<section class="<?= $space === null ? 'content-container search-page' : 'wiki-layout space-overview space-search' ?>">
    <?php if ($space !== null): ?><div class="wiki-content">
        <?php require __DIR__ . '/../partials/space-header.php'; ?>
        <div class="space-tab-heading"><h2><?= $e($t('Search this space')) ?></h2></div>
    <?php else: ?>
    <div class="search-page-heading">
        <h1><?= $e($t('Search')) ?></h1>
    </div>
    <?php endif; ?>

    <form class="card form-card form-stack <?= $space === null ? 'search-form search-form--reference' : '' ?>" method="get" action="/search" data-search-form>
        <?php if ($space === null): ?><button class="sr-only" type="submit"><?= $e($t('Search')) ?></button><?php endif; ?>
        <div class="search-page-form">
            <div class="search-combobox">
                <input type="search" name="q" value="<?= $e($query) ?>" placeholder="<?= $e($t('Search knowledge')) ?>&#8230;" <?= $space === null ? 'autofocus' : '' ?> required aria-label="<?= $e($t('Search')) ?>" aria-autocomplete="list" aria-controls="search-suggestions" aria-expanded="false" autocomplete="off" data-search-query>
                <?php if ($space === null): ?><button class="search-clear-button" type="button" data-search-clear aria-label="<?= $e($t('Clear search')) ?>" <?= $query === '' ? 'hidden' : '' ?>><?= $uiIcon('close') ?></button><?php endif; ?>
                <ul id="search-suggestions" class="search-suggestions" role="listbox" aria-label="<?= $e($t('Search suggestions')) ?>" hidden></ul>
                <span data-search-suggestion-status class="muted" role="status" aria-live="polite"></span>
            </div>
            <?php if ($space === null): ?><button class="search-ask-ai" type="button" disabled title="<?= $e($t('AI search is not configured.')) ?>"><span aria-hidden="true">&#10022;</span><?= $e($t('Ask AI')) ?></button><?php else: ?><button class="button button--primary" type="submit"><?= $e($t('Search')) ?></button><?php endif; ?>
        </div>

        <?php if ($space === null): ?>
            <?php $updatedPeriod = $filters['updated_period'] ?? ((!empty($filters['updated_from']) || !empty($filters['updated_to'])) ? 'custom' : 'any'); $searchAuthor = !empty($filters['my_work']) ? '' : ($filters['author'] ?? ''); ?>
            <div class="search-filter-row" role="group" aria-label="<?= $e($t('Search filters')) ?>">
                <?php if ($currentUser !== null): ?><label class="search-filter-chip search-filter-chip--toggle"><input type="checkbox" name="my_work" value="1" <?= !empty($filters['my_work']) ? 'checked' : '' ?>><span aria-hidden="true"><?= $uiIcon('users') ?></span><?= $e($t('My work')) ?></label><?php endif; ?>
                <label class="search-filter-chip"><span><?= $e($t('Last updated')) ?></span><select name="updated_period" data-search-updated-period>
                    <?php foreach (['any' => 'Any time', 'today' => 'Today', 'yesterday' => 'Yesterday', '7' => 'Past 7 days', '30' => 'Past 30 days', '365' => 'Past year', 'custom' => 'Custom range'] as $value => $label): ?><option value="<?= $e($value) ?>" <?= $updatedPeriod === $value ? 'selected' : '' ?>><?= $e($t($label)) ?></option><?php endforeach; ?>
                </select></label>
                <details class="search-filter-popover" name="search-filter-popovers">
                    <summary><?= $e($t('Space')) ?><?php if (!empty($filters['space'])): ?><span class="search-filter-chip__value"><?= $e($filters['space']) ?></span><?php endif; ?><span class="search-filter-chevron" aria-hidden="true"></span></summary>
                    <div class="search-filter-popover__panel"><label><?= $e($t('Space')) ?><input name="space" maxlength="191" value="<?= $e($filters['space'] ?? '') ?>" placeholder="<?= $e($t('Key or name')) ?>"></label></div>
                </details>
                <details class="search-filter-popover" name="search-filter-popovers">
                    <summary><?= $e($t('Edited or created by')) ?><?php if ($searchAuthor !== ''): ?><span class="search-filter-chip__value"><?= $e($searchAuthor) ?></span><?php endif; ?><span class="search-filter-chevron" aria-hidden="true"></span></summary>
                    <div class="search-filter-popover__panel"><label><?= $e($t('Username')) ?><input name="author" maxlength="100" value="<?= $e($searchAuthor) ?>" placeholder="<?= $e($t('Username')) ?>"></label></div>
                </details>
                <details class="search-filter-popover" name="search-filter-popovers">
                    <summary><?= $e($t('Label')) ?><?php if (!empty($filters['tag'])): ?><span class="search-filter-chip__value"><?= $e($filters['tag']) ?></span><?php endif; ?><span class="search-filter-chevron" aria-hidden="true"></span></summary>
                    <div class="search-filter-popover__panel"><label><?= $e($t('Label')) ?><input name="tag" maxlength="100" value="<?= $e($filters['tag'] ?? '') ?>" placeholder="<?= $e($t('Tag name or slug')) ?>"></label></div>
                </details>
                <label class="search-filter-chip"><span><?= $e($t('Type')) ?></span><select name="type">
                    <?php foreach ($types as $searchType): ?><option value="<?= $e($searchType) ?>" <?= $type === $searchType ? 'selected' : '' ?>><?= $e($t($typeLabels[$searchType] ?? ucfirst($searchType))) ?></option><?php endforeach; ?>
                </select></label>
                <details class="search-filter-popover search-more-filters" name="search-filter-popovers" <?= $searchAdvancedOpen ? 'open' : '' ?>>
                    <summary><span aria-hidden="true">+</span><?= $e($t('More filters')) ?><span class="search-filter-chevron" aria-hidden="true"></span></summary>
                    <div class="search-filter-popover__panel search-more-filters__panel">
                        <label><?= $e($t('Revision visibility')) ?><select name="visibility">
                            <?php foreach (['all' => 'Published and available drafts', 'published' => 'Published only', 'working' => 'Drafts only'] as $value => $label): ?><option value="<?= $e($value) ?>" <?= ($filters['visibility'] ?? 'all') === $value ? 'selected' : '' ?>><?= $e($t($label)) ?></option><?php endforeach; ?>
                        </select></label>
                        <label><?= $e($t('Results per page')) ?><select name="per_page">
                            <?php foreach ([10,25,50,100] as $size): ?><option value="<?= $size ?>" <?= (int) ($searchMeta['per_page'] ?? 25) === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?>
                        </select></label>
                        <fieldset class="search-date-filters"><legend><?= $e($t('Date filters')) ?></legend><div class="form-grid search-date-grid">
                            <label><?= $e($t('Created from')) ?><input type="date" name="created_from" value="<?= $e($filters['created_from'] ?? '') ?>"></label>
                            <label><?= $e($t('Created to')) ?><input type="date" name="created_to" value="<?= $e($filters['created_to'] ?? '') ?>"></label>
                            <label><?= $e($t('Updated from')) ?><input type="date" name="updated_from" value="<?= $e($filters['updated_from'] ?? '') ?>" data-search-custom-updated></label>
                            <label><?= $e($t('Updated to')) ?><input type="date" name="updated_to" value="<?= $e($filters['updated_to'] ?? '') ?>" data-search-custom-updated></label>
                        </div></fieldset>
                        <div class="form-actions"><button class="button button--secondary" type="submit"><?= $e($t('Apply filters')) ?></button><a class="button button--ghost" href="<?= $e($searchResetUrl) ?>"><?= $e($t('Reset filters')) ?></a></div>
                    </div>
                </details>
            </div>
        <?php else: ?>
            <details class="search-advanced" <?= $searchAdvancedOpen ? 'open' : '' ?>><summary><?= $e($t('Advanced options')) ?></summary><section class="search-form__filters" aria-labelledby="search-filter-heading">
                <header class="search-form__filters-heading"><div><h2 id="search-filter-heading"><?= $e($t('Search filters')) ?></h2><p><?= $e($t('Narrow results by type, space, author, tag or dates.')) ?></p></div></header>
                <div class="form-grid search-filter-grid">
                    <label><?= $e($t('Type')) ?><select name="type"><?php foreach ($types as $searchType): ?><option value="<?= $e($searchType) ?>" <?= $type === $searchType ? 'selected' : '' ?>><?= $e($t($typeLabels[$searchType] ?? ucfirst($searchType))) ?></option><?php endforeach; ?></select></label>
                    <label><?= $e($t('Space')) ?><input name="space" maxlength="191" value="<?= $e($filters['space'] ?? '') ?>" placeholder="<?= $e($t('Key or name')) ?>"></label>
                    <label><?= $e($t('Author')) ?><input name="author" maxlength="100" value="<?= $e($filters['author'] ?? '') ?>" placeholder="<?= $e($t('Username')) ?>"></label>
                    <label><?= $e($t('Tag')) ?><input name="tag" maxlength="100" value="<?= $e($filters['tag'] ?? '') ?>" placeholder="<?= $e($t('Tag name or slug')) ?>"></label>
                    <label><?= $e($t('Revision visibility')) ?><select name="visibility"><?php foreach (['all' => 'Published and available drafts', 'published' => 'Published only', 'working' => 'Drafts only'] as $value => $label): ?><option value="<?= $e($value) ?>" <?= ($filters['visibility'] ?? 'all') === $value ? 'selected' : '' ?>><?= $e($t($label)) ?></option><?php endforeach; ?></select></label>
                    <label><?= $e($t('Results per page')) ?><select name="per_page"><?php foreach ([10,25,50,100] as $size): ?><option value="<?= $size ?>" <?= (int) ($searchMeta['per_page'] ?? 25) === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></label>
                </div>
                <fieldset class="search-date-filters"><legend><?= $e($t('Date filters')) ?></legend><div class="form-grid search-date-grid">
                    <label><?= $e($t('Created from')) ?><input type="date" name="created_from" value="<?= $e($filters['created_from'] ?? '') ?>"></label><label><?= $e($t('Created to')) ?><input type="date" name="created_to" value="<?= $e($filters['created_to'] ?? '') ?>"></label>
                    <label><?= $e($t('Updated from')) ?><input type="date" name="updated_from" value="<?= $e($filters['updated_from'] ?? '') ?>"></label><label><?= $e($t('Updated to')) ?><input type="date" name="updated_to" value="<?= $e($filters['updated_to'] ?? '') ?>"></label>
                </div></fieldset>
                <div class="form-actions"><button class="button button--secondary" type="submit"><?= $e($t('Apply filters')) ?></button><a class="button button--ghost" href="<?= $e($searchResetUrl) ?>"><?= $e($t('Reset filters')) ?></a></div>
            </section></details>
        <?php endif; ?>
    </form>

    <?php if (!empty($searchError)): ?>
        <div class="alert alert--error" role="alert"><?= $e($t($searchError)) ?></div>
    <?php endif; ?>

    <?php if ($query !== '' && empty($searchError)): ?>
        <p class="muted search-result-summary" data-search-summary><?= $e(strtr($t(':count results for “:query”. Page :page of :total.'), [':count' => (string) (int) $searchMeta['total'], ':query' => $query, ':page' => (string) (int) $searchMeta['page'], ':total' => (string) max(1, (int) $searchMeta['total_pages'])])) ?></p>

        <?php if ($results === []): ?>
            <div class="card empty-hero">
                <h2><?= $e($t('No matching results')) ?></h2>
                <p><?= $e($t('Change the query or remove one of the filters.')) ?></p>
            </div>
        <?php else: ?>
            <div class="search-results">
                <?php foreach ($results as $result): ?>
                    <article class="card search-result" data-search-key="<?= $e($result['result_key']) ?>">
                        <div class="search-result__meta">
                            <?= $e($t($typeLabels[$result['type']] ?? ucfirst((string) $result['type']))) ?><?= $result['meta'] !== '' ? ' · ' . $e($result['meta']) : '' ?>
                            <?php if (isset($result['author']) && $result['author'] !== ''): ?><span class="search-result__author"><span><?= $e($t('Author')) ?>:</span> <?= $e($result['author']) ?></span><?php endif; ?>
                            <?php if ($result['working']): ?><span class="badge"><?= $e($t('Draft')) ?> · <?= $e($t('Version')) ?> <?= (int) $result['revision'] ?></span><?php endif; ?>
                        </div>

                        <h2 class="search-result__heading">
                            <?php if ($result['type'] === 'page'): ?><span class="search-result__icon"><?= $uiIcon('document') ?></span><?php endif; ?>
                            <span class="search-result__title">
                                <?php if (!empty($result['url'])): ?>
                                    <a href="<?= $e($result['url']) ?>"><?= $highlight($result['title'], $query) ?></a>
                                <?php else: ?>
                                    <?= $highlight($result['title'], $query) ?>
                                <?php endif; ?>
                            </span>
                        </h2>

                        <?php if ((string) $result['snippet'] !== ''): ?>
                            <p><?= $highlight($result['snippet'], $query) ?></p>
                        <?php endif; ?>

                        <small>
                            <?php if (!empty($result['updated_at'])): ?>
                                    <?= $e($t('Updated')) ?> <?= $e($result['updated_at']) ?>
                            <?php elseif (!empty($result['created_at'])): ?>
                                    <?= $e($t('Created')) ?> <?= $e($result['created_at']) ?>
                            <?php endif; ?>
                        </small>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ((int) $searchMeta['total_pages'] > 1): ?>
            <?php $pageUrl = static fn (int $page): string => '/search?' . http_build_query(array_replace($filters, ['q' => $query, 'type' => $type, 'page' => $page, 'per_page' => $searchMeta['per_page']])); ?>
            <nav class="form-actions search-pagination" aria-label="<?= $e($t('Search result pages')) ?>">
                <?php if ((int) $searchMeta['page'] > 1): ?><a class="button button--secondary" rel="prev" href="<?= $e($pageUrl((int) $searchMeta['page'] - 1)) ?>"><?= $e($t('Previous')) ?></a><?php endif; ?>
                <?php if ((int) $searchMeta['page'] < (int) $searchMeta['total_pages']): ?><a class="button button--secondary" rel="next" href="<?= $e($pageUrl((int) $searchMeta['page'] + 1)) ?>"><?= $e($t('Next')) ?></a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($query === '' && empty($searchError)): ?>
        <div class="card empty-hero search-start-state">
            <h2><?= $e($t($type === 'page' ? 'Find a page' : 'Start a search')) ?></h2>
            <p><?= $e($t('Enter a search phrase to see matching pages and other content you can access.')) ?></p>
        </div>
    <?php endif; ?>
    <?php if ($space !== null): ?></div><?php endif; ?>
</section>
