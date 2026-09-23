<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="narrow-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow"><?= $e($space['name']) ?></div>
            <h1>Import documentation</h1>
            <p class="muted">Import Markdown, HTML or a ZIP documentation tree. Imported pages start as drafts.</p>
        </div>
        <a class="button button--ghost" href="/spaces/<?= rawurlencode((string) $space['space_key']) ?>">Back to Space</a>
    </div>

    <?php if (!empty($formError)): ?>
        <div class="alert alert--error" role="alert"><?= $e($formError) ?></div>
    <?php endif; ?>

    <form class="card form-card form-stack" method="post" enctype="multipart/form-data" action="/spaces/<?= rawurlencode((string) $space['space_key']) ?>/import">
        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">

        <label>Documentation file
            <input type="file" name="document" accept=".md,.markdown,.html,.htm,.zip,text/markdown,text/html,application/zip" required>
        </label>

        <label>Parent page
            <select name="parent_id">
                <option value="">Space root</option>
                <?php foreach ($parents as $parent): ?>
                    <option value="<?= (int) $parent['id'] ?>"><?= $e($parent['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <div class="card panel">
            <h2>ZIP safety limits</h2>
            <p class="muted">ZIP archives are read entry-by-entry without filesystem extraction. Absolute paths, path traversal and symlink entries are rejected.</p>
            <ul>
                <li>Maximum 500 archive entries.</li>
                <li>Maximum 10 MiB per documentation entry.</li>
                <li>Maximum 100 MiB total uncompressed size by default.</li>
                <li>Only Markdown and HTML files become pages; unsupported files are counted as skipped.</li>
            </ul>
        </div>

        <button class="button button--primary" type="submit">Import documentation</button>
    </form>
</section>
