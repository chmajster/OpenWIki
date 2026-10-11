<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$old = $old ?? [];
?>
<section class="narrow-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow"><?= $e($t('Spaces')) ?></div>
            <h1><?= $e($t('Create space')) ?></h1>
            <p class="muted"><?= $e($t('A space groups related documentation and controls its visibility.')) ?></p>
        </div>
    </div>

    <div class="card form-card">
        <?php if (!empty($formError)): ?>
            <div class="alert alert--error" role="alert"><?= $e($formError) ?></div>
        <?php endif; ?>

        <form method="post" action="/spaces" class="form-stack">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <label><?= $e($t('Name')) ?>
                <input name="name" required maxlength="191" value="<?= $e($old['name'] ?? '') ?>">
            </label>
            <label><?= $e($t('Key')) ?>
                <input name="space_key" maxlength="50" pattern="[A-Za-z0-9_-]+" value="<?= $e($old['space_key'] ?? '') ?>">
                <span class="field-help"><?= $e($t('Leave empty to derive a key from the name.')) ?></span>
            </label>
            <label><?= $e($t('Description')) ?>
                <textarea name="description" rows="4" maxlength="5000"><?= $e($old['description'] ?? '') ?></textarea>
            </label>
            <label><?= $e($t('Visibility')) ?>
                <select name="visibility">
                    <?php foreach (['private' => 'Private', 'restricted' => 'Restricted', 'public' => 'Public'] as $value => $label): ?>
                        <option value="<?= $e($value) ?>" <?= ($old['visibility'] ?? 'private') === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="form-actions">
                <a class="button button--ghost" href="/"><?= $e($t('Cancel')) ?></a>
                <button class="button button--primary" type="submit"><?= $e($t('Create space')) ?></button>
            </div>
        </form>
    </div>
</section>
