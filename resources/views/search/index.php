<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Search</div>
            <h1>Search documentation</h1>
        </div>
    </div>

    <form class="search-page-form" method="get" action="/search">
        <input type="search" name="q" value="<?= $e($query) ?>" placeholder="Search pages…" autofocus>
        <button class="button button--primary" type="submit">Search</button>
    </form>

    <?php if (!empty($searchError)): ?>
        <div class="alert alert--error" role="alert"><?= $e($searchError) ?></div>
    <?php endif; ?>

    <?php if ($query !== '' && empty($searchError)): ?>
        <p class="muted"><?= count($results) ?> result<?= count($results) === 1 ? '' : 's' ?> for “<?= $e($query) ?>”.</p>
        <?php if ($results === []): ?>
            <div class="card empty-hero">
                <h2>No matching pages</h2>
                <p>Try a shorter or more specific query.</p>
            </div>
        <?php else: ?>
            <div class="search-results">
                <?php foreach ($results as $result): ?>
                    <article class="card search-result">
                        <div class="search-result__meta"><?= $e($result['space_name']) ?> · <?= $e($result['space_key']) ?></div>
                        <h2>
                            <a href="/spaces/<?= rawurlencode($result['space_key']) ?>/pages/<?= rawurlencode($result['slug']) ?>"><?= $e($result['title']) ?></a>
                        </h2>
                        <?php if ($result['snippet'] !== ''): ?>
                            <p><?= $e($result['snippet']) ?></p>
                        <?php endif; ?>
                        <small>Updated <?= $e($result['updated_at']) ?></small>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
