<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$old = $old ?? [];
$record = $role ?? null;
$field = static fn (string $key, mixed $default = ''): mixed => array_key_exists($key, $old) ? $old[$key] : ($record[$key] ?? $default);
$permissionIds = array_map('intval', (array) ($old['permission_ids'] ?? ($record['permission_ids'] ?? [])));
$isEdit = $record !== null;
$isSystem = $isEdit && (int) $record['is_system'] === 1;
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow"><?= $e($t('Administration · Roles')) ?></div>
            <h1><?= $isEdit ? 'Edit ' . $e($record['name']) : 'Create role' ?></h1>
        </div>
        <a class="button button--ghost" href="/admin/roles"><?= $e($t('Back to roles')) ?></a>
    </div>

    <?php if (!empty($formError)): ?><div class="alert alert--error"><?= $e($formError) ?></div><?php endif; ?>

    <section class="card form-card">
        <form method="post" action="<?= $e($formAction) ?>" class="form-stack">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <label><?= $e($t('Name')) ?>
                <input name="name" maxlength="100" value="<?= $e($field('name')) ?>" required <?= $isSystem ? 'readonly' : '' ?>>
            </label>
            <label><?= $e($t('Slug')) ?>
                <input name="slug" maxlength="100" value="<?= $e($field('slug')) ?>" <?= $isSystem ? 'readonly' : '' ?>>
            </label>
            <label><?= $e($t('Description')) ?>
                <textarea name="description" maxlength="500" rows="3"><?= $e($field('description')) ?></textarea>
            </label>

            <fieldset>
                <legend><?= $e($t('Permissions')) ?></legend>
                <div class="scope-grid permission-grid">
                    <?php foreach ($permissions as $permission): ?>
                        <?php $lockedWildcard = $isSystem && $record['slug'] === 'super-admin' && $permission['name'] === '*'; ?>
                        <label class="check-row">
                            <input type="checkbox" name="permission_ids[]" value="<?= (int) $permission['id'] ?>"
                                <?= in_array((int) $permission['id'], $permissionIds, true) || $lockedWildcard ? 'checked' : '' ?>
                                <?= $lockedWildcard ? 'disabled' : '' ?>>
                            <span><code><?= $e($permission['name']) ?></code></span>
                        </label>
                        <?php if ($lockedWildcard): ?>
                            <input type="hidden" name="permission_ids[]" value="<?= (int) $permission['id'] ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <button class="button button--primary" type="submit"><?= $e($t('Save role')) ?></button>
        </form>
    </section>

    <?php if ($isEdit && !$isSystem): ?>
        <section class="card panel danger-zone">
            <form method="post" action="/admin/roles/<?= (int) $record['id'] ?>/delete" data-confirm="<?= $e($t('Delete this role?')) ?>">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <button class="button button--ghost" type="submit"><?= $e($t('Delete role')) ?></button>
            </form>
        </section>
    <?php endif; ?>
</section>
