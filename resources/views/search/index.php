<?php
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
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Search</div>
            <h1>Search knowledge</h1>
            <p class="muted">Search pages, Spaces, users, comments, tags and attachment names.</p>
        </div>
    </div>

    <form class="card form-card form-stack" method="get" action="/search">
        <div class="search-page-form">
            <input type="search" name="q" value="<?= $e($query) ?>" placeholder="Search knowledge…" autofocus required>
            <button class="button button--primary" type="submit">Search</button>
        </div>

        <div class="form-grid">
            <label>Type
                <select name="type">
                    <?php foreach ($types as $searchType): ?>
                        <option value="<?= $e($searchType) ?>" <?= $type === $searchType ? 'selected' : '' ?>>
                            <?= $e($typeLabels[$searchType] ?? ucfirst($searchType)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Space
                <input name="space" maxlength="191" value="<?= $e($filters['space'] ?? '') ?>" placeholder="Key or name">
            </label>

            <label>Author
                <input name="author" maxlength="100" value="<?= $e($filters['author'] ?? '') ?>" placeholder="Username">
            </label>

            <label>Tag
                <input name="tag" maxlength="100" value="<?= $e($filters['tag'] ?? '') ?>" placeholder="Tag name or slug">
            </label>
        </div>

        <div class="form-grid">
            <label>Created from
                <input type="date" name="created_from" value="<?= $e($filters['created_from'] ?? '') ?>">
            </label>
            <label>Created to
                <input type="date" name="created_to" value="<?= $e($filters['created_to'] ?? '') ?>">
            </label>
            <label>Updated from
                <input type="date" name="updated_from" value="<?= $e($filters['updated_from'] ?? '') ?>">
            </label>
            <label>Updated to
                <input type="date" name="updated_to" value="<?= $e($filters['updated_to'] ?? '') ?>">
            </label>
        </div>

        <div class="form-actions">
            <button class="button button--secondary" type="submit">Apply filters</button>
            <a class="button button--ghost" href="/search<?= $query !== '' ? '?q=' . rawurlencode($query) : '' ?>">Reset filters</a>
        </div>
    </form>

    <?php if (!empty($searchError)): ?>
        <div class="alert alert--error" role="alert"><?= $e($searchError) ?></div>
    <?php endif; ?>

    <?php if ($query !== '' && empty($searchError)): ?>
        <p class="muted">
            <?= count($results) ?> result<?= count($results) === 1 ? '' : 's' ?>
            for “<?= $e($query) ?>”.
        </p>

        <?php if ($results === []): ?>
            <div class="card empty-hero">
                <h2>No matching results</h2>
                <p>Change the query or remove one of the filters.</p>
            </div>
        <?php else: ?>
            <div class="search-results">
                <?php foreach ($results as $result): ?>
                    <article class="card search-result">
                        <div class="search-result__meta">
                            <?= $e(strtoupper((string) $result['type'])) ?> · <?= $e($result['meta']) ?>
                        </div>

                        <h2>
                            <?php if (!empty($result['url'])): ?>
                                <a href="<?= $e($result['url']) ?>"><?= $highlight($result['title'], $query) ?></a>
                            <?php else: ?>
                                <?= $highlight($result['title'], $query) ?>
                            <?php endif; ?>
                        </h2>

                        <?php if ((string) $result['snippet'] !== ''): ?>
                            <p><?= $highlight($result['snippet'], $query) ?></p>
                        <?php endif; ?>

                        <small>
                            <?php if (!empty($result['updated_at'])): ?>
                                Updated <?= $e($result['updated_at']) ?>
                            <?php elseif (!empty($result['created_at'])): ?>
                                Created <?= $e($result['created_at']) ?>
                            <?php endif; ?>
                        </small>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
