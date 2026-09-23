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
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Administration · Users</div>
            <h1><?= $isEdit ? 'Edit ' . $e($record['username']) : 'Create user' ?></h1>
        </div>
        <a class="button button--ghost" href="/admin/users">Back to users</a>
    </div>

    <?php if (!empty($formError)): ?>
        <div class="alert alert--error" role="alert"><?= $e($formError) ?></div>
    <?php endif; ?>

    <section class="card form-card">
        <form method="post" action="<?= $e($formAction) ?>" class="form-stack">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <div class="form-grid">
                <label>Username
                    <input name="username" value="<?= $e($field('username')) ?>" pattern="[A-Za-z0-9._-]{3,100}" required>
                </label>
                <label>Email
                    <input type="email" name="email" value="<?= $e($field('email')) ?>" required>
                </label>
                <label>First name
                    <input name="first_name" maxlength="100" value="<?= $e($field('first_name')) ?>">
                </label>
                <label>Last name
                    <input name="last_name" maxlength="100" value="<?= $e($field('last_name')) ?>">
                </label>
                <label>Status
                    <select name="status">
                        <?php foreach (['active','disabled','locked'] as $status): ?>
                            <option value="<?= $status ?>" <?= $field('status', 'active') === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if (!$isEdit): ?>
                    <label>Initial password
                        <input type="password" name="password" minlength="12" autocomplete="new-password" required>
                    </label>
                <?php else: ?>
                    <label>Authentication source
                        <input value="<?= $e($record['auth_source']) ?>" disabled>
                    </label>
                <?php endif; ?>
            </div>

            <label class="check-row">
                <input type="checkbox" name="force_password_change" value="1" <?= !empty($field('force_password_change')) ? 'checked' : '' ?>>
                <span>Require password change on next sign-in</span>
            </label>

            <fieldset>
                <legend>Roles</legend>
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
                <legend>Groups</legend>
                <div class="scope-grid">
                    <?php foreach ($groups as $group): ?>
                        <label class="check-row">
                            <input type="checkbox" name="group_ids[]" value="<?= (int) $group['id'] ?>" <?= in_array((int) $group['id'], $groupIds, true) ? 'checked' : '' ?>>
                            <span><?= $e($group['name']) ?><?= $group['source'] === 'ldap' ? ' · LDAP' : '' ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <button class="button button--primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
        </form>
    </section>

    <?php if ($isEdit): ?>
        <section class="card panel danger-zone">
            <div class="panel__header"><h2>Account actions</h2></div>
            <div class="page-actions">
                <form method="post" action="/admin/users/<?= (int) $record['id'] ?>/reset-password" onsubmit="return confirm('Reset this user password?')">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <button class="button button--secondary" type="submit">Reset password</button>
                </form>
                <form method="post" action="/admin/users/<?= (int) $record['id'] ?>/reset-mfa" onsubmit="return confirm('Reset MFA for this user?')">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <button class="button button--secondary" type="submit">Reset MFA</button>
                </form>
                <form method="post" action="/admin/users/<?= (int) $record['id'] ?>/delete" onsubmit="return confirm('Delete this user account?')">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <button class="button button--ghost" type="submit">Delete user</button>
                </form>
            </div>
        </section>
    <?php endif; ?>
</section>
