<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$old = $old ?? [];
$record = $group ?? null;
$field = static fn (string $key, mixed $default = ''): mixed => array_key_exists($key, $old) ? $old[$key] : ($record[$key] ?? $default);
$userIds = array_map('intval', (array) ($old['user_ids'] ?? ($record['user_ids'] ?? [])));
$isEdit = $record !== null;
$isLdap = $isEdit && $record['source'] === 'ldap';
?>
<section class="content-container admin-page">
    <div class="page-heading">
        <div>
            <div class="eyebrow"><?= $e($t('Administration · Groups')) ?></div>
            <h1><?= $isEdit ? $e(strtr($t('Edit :name'), [':name' => $record['name']])) : $e($t('Create group')) ?></h1>
        </div>
        <a class="button button--ghost" href="/admin/groups"><?= $e($t('Back to groups')) ?></a>
    </div>

    <?php if (!empty($formError)): ?><div class="alert alert--error" role="alert"><?= $e($t($formError)) ?></div><?php endif; ?>
    <?php if ($isLdap): ?><div class="alert"><?= $e($t('LDAP groups are read-only locally. Membership is managed by directory synchronization.')) ?></div><?php endif; ?>

    <section class="card form-card">
        <form method="post" action="<?= $e($formAction) ?>" class="form-stack">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <label><?= $e($t('Name')) ?>
                <input name="name" maxlength="150" value="<?= $e($field('name')) ?>" required <?= $isLdap ? 'disabled' : '' ?>>
            </label>
            <label><?= $e($t('Slug')) ?>
                <input name="slug" maxlength="150" value="<?= $e($field('slug')) ?>" <?= $isLdap ? 'disabled' : '' ?>>
            </label>
            <label><?= $e($t('Description')) ?>
                <textarea name="description" maxlength="500" rows="3" <?= $isLdap ? 'disabled' : '' ?>><?= $e($field('description')) ?></textarea>
            </label>

            <?php if (!$isLdap): ?>
                <fieldset>
                    <legend><?= $e($t('Members')) ?></legend>
                    <div class="scope-grid directory-selection">
                        <?php foreach ($users as $user): ?>
                            <label class="check-row">
                                <input type="checkbox" name="user_ids[]" value="<?= (int) $user['id'] ?>" <?= in_array((int) $user['id'], $userIds, true) ? 'checked' : '' ?>>
                                <span><?= $e($user['username']) ?> <small><?= $e($user['email']) ?></small></span>
                            </label>
                        <?php endforeach; ?>
                        <?php if ($users === []): ?><p class="empty-state"><?= $e($t('No users are available to add.')) ?></p><?php endif; ?>
                    </div>
                </fieldset>
                <div class="form-actions"><button class="button button--primary" type="submit"><?= $e($t($isEdit ? 'Save changes' : 'Create group')) ?></button></div>
            <?php endif; ?>
        </form>
    </section>

    <?php if ($isEdit && !$isLdap): ?>
        <section class="card panel danger-zone">
            <form method="post" action="/admin/groups/<?= (int) $record['id'] ?>/delete" data-confirm="<?= $e($t('Delete this group?')) ?>">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <button class="button button--ghost" type="submit"><?= $e($t('Delete group')) ?></button>
            </form>
        </section>
    <?php endif; ?>
</section>
