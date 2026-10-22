<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$record = $userRecord ?? null;
$old = $old ?? [];
$field = static function (string $key, mixed $default = '') use ($old, $record): mixed {
    if (array_key_exists($key, $old)) {
        return $old[$key];
    }
    return $record[$key] ?? $default;
};
$roleIds = array_map('intval', (array) ($old['role_ids'] ?? ($record['role_ids'] ?? [])));
$groupIds = array_map('intval', (array) ($old['group_ids'] ?? ($record['group_ids'] ?? [])));
$isEdit = $record !== null;
$statusLabels = ['active' => 'Active', 'disabled' => 'Disabled', 'locked' => 'Locked'];
?>
<section class="content-container admin-page">
    <div class="page-heading">
        <div>
            <div class="eyebrow"><?= $e($t('Administration · Users')) ?></div>
            <h1><?= $isEdit ? $e(strtr($t('Edit :name'), [':name' => $record['username']])) : $e($t('Create user')) ?></h1>
        </div>
        <a class="button button--ghost" href="/admin/users"><?= $e($t('Back to users')) ?></a>
    </div>

    <?php if (!empty($formError)): ?>
        <div class="alert alert--error" role="alert"><?= $e($t($formError)) ?></div>
    <?php endif; ?>

    <section class="card form-card">
        <form method="post" action="<?= $e($formAction) ?>" class="form-stack">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <div class="form-grid">
                <label><?= $e($t('Username')) ?>
                    <input name="username" value="<?= $e($field('username')) ?>" pattern="[A-Za-z0-9._-]{3,100}" required>
                </label>
                <label><?= $e($t('Email')) ?>
                    <input type="email" name="email" value="<?= $e($field('email')) ?>" required>
                </label>
                <label><?= $e($t('First name')) ?>
                    <input name="first_name" maxlength="100" value="<?= $e($field('first_name')) ?>">
                </label>
                <label><?= $e($t('Last name')) ?>
                    <input name="last_name" maxlength="100" value="<?= $e($field('last_name')) ?>">
                </label>
                <label><?= $e($t('Status')) ?>
                    <select name="status">
                        <?php foreach (['active','disabled','locked'] as $status): ?>
                        <option value="<?= $status ?>" <?= $field('status', 'active') === $status ? 'selected' : '' ?>><?= $e($t($statusLabels[$status])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if (!$isEdit): ?>
                    <label><?= $e($t('Initial password')) ?>
                        <input type="password" name="password" minlength="12" autocomplete="new-password" required>
                    </label>
                <?php else: ?>
                    <label><?= $e($t('Authentication source')) ?>
                        <input value="<?= $e($t($record['auth_source'] === 'ldap' ? 'LDAP' : 'Local')) ?>" disabled>
                    </label>
                <?php endif; ?>
            </div>

            <label class="admin-form-checkbox">
                <input type="checkbox" name="force_password_change" value="1" <?= !empty($field('force_password_change')) ? 'checked' : '' ?>>
                <span><?= $e($t('Require password change on next sign-in')) ?><small><?= $e($t('The user must choose a new password after the next sign-in.')) ?></small></span>
            </label>

            <fieldset>
                <legend><?= $e($t('Roles')) ?></legend>
                <div class="scope-grid">
                    <?php foreach ($roles as $role): ?>
                        <label class="check-row">
                            <input type="checkbox" name="role_ids[]" value="<?= (int) $role['id'] ?>" <?= in_array((int) $role['id'], $roleIds, true) ? 'checked' : '' ?>>
                            <span><?= $e($role['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <fieldset>
                <legend><?= $e($t('Groups')) ?></legend>
                <div class="scope-grid">
                    <?php foreach ($groups as $group): ?>
                        <label class="check-row">
                            <input type="checkbox" name="group_ids[]" value="<?= (int) $group['id'] ?>" <?= in_array((int) $group['id'], $groupIds, true) ? 'checked' : '' ?>>
                            <span><?= $e($group['name']) ?><?= $group['source'] === 'ldap' ? ' · LDAP' : '' ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="form-actions"><button class="button button--primary" type="submit"><?= $e($t($isEdit ? 'Save changes' : 'Create user')) ?></button></div>
        </form>
    </section>

    <?php if ($isEdit): ?>
        <section class="card panel danger-zone">
            <div class="panel__header"><h2><?= $e($t('Account actions')) ?></h2></div>
            <div class="page-actions">
                <form method="post" action="/admin/users/<?= (int) $record['id'] ?>/reset-password" data-confirm="<?= $e($t('Reset this user password?')) ?>">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <button class="button button--secondary" type="submit"><?= $e($t('Reset password')) ?></button>
                </form>
                <form method="post" action="/admin/users/<?= (int) $record['id'] ?>/reset-mfa" data-confirm="<?= $e($t('Reset MFA for this user?')) ?>">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <button class="button button--secondary" type="submit"><?= $e($t('Reset MFA')) ?></button>
                </form>
                <form method="post" action="/admin/users/<?= (int) $record['id'] ?>/delete" data-confirm="<?= $e($t('Delete this user account?')) ?>">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <button class="button button--ghost" type="submit"><?= $e($t('Delete user')) ?></button>
                </form>
            </div>
        </section>
    <?php endif; ?>
</section>
