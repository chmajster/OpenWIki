<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$record = $templateRecord;
$isSystem = $record !== null && (bool) $record['is_system'];
$value = static function (string $key, mixed $default = '') use ($old, $record): mixed {
    return array_key_exists($key, $old) ? $old[$key] : ($record[$key] ?? $default);
};
$markdown = (string) $value('content_markdown', '');
$format = (string) ($old['content_format'] ?? ($markdown !== '' ? 'markdown' : 'visual'));
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Administration</div>
            <h1><?= $e($record === null ? 'Create template' : $record['name']) ?></h1>
            <?php if ($isSystem): ?><p class="muted">System template · read-only</p><?php endif; ?>
        </div>
        <a class="button button--ghost" href="/admin/templates">Back to templates</a>
    </div>

    <?php if (!empty($formError)): ?>
        <div class="alert alert--error" role="alert"><?= $e($formError) ?></div>
    <?php endif; ?>

    <form class="card form-card form-stack" method="post" action="<?= $e($formAction) ?>">
        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">

        <label>Name
            <input name="name" maxlength="191" required value="<?= $e($value('name')) ?>" <?= $isSystem ? 'readonly' : '' ?>>
        </label>

        <label>Description
            <textarea name="description" rows="3" maxlength="500" <?= $isSystem ? 'readonly' : '' ?>><?= $e($value('description')) ?></textarea>
        </label>

        <label>Content format
            <select name="content_format" <?= $isSystem ? 'disabled' : '' ?>>
                <option value="visual" <?= $format === 'visual' ? 'selected' : '' ?>>HTML / Visual</option>
                <option value="markdown" <?= $format === 'markdown' ? 'selected' : '' ?>>Markdown</option>
            </select>
        </label>

        <label>HTML content
            <textarea name="content_html" rows="14" <?= $isSystem ? 'readonly' : '' ?>><?= $e($value('content_html', '<p></p>')) ?></textarea>
        </label>

        <label>Markdown content
            <textarea name="content_markdown" rows="14" <?= $isSystem ? 'readonly' : '' ?>><?= $e($value('content_markdown')) ?></textarea>
        </label>

        <?php if (!$isSystem): ?>
            <div class="form-actions">
                <button class="button button--primary" type="submit">Save template</button>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($record !== null && !$isSystem): ?>
        <div class="card panel danger-zone">
            <h2>Delete template</h2>
            <form method="post" action="/admin/templates/<?= (int) $record['id'] ?>/delete" onsubmit="return confirm('Delete this custom template?')">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <button class="button button--ghost" type="submit">Delete template</button>
            </form>
        </div>
    <?php endif; ?>
</section>
