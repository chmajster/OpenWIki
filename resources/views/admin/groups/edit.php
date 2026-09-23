<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$old = $old ?? [];
$record = $group ?? null;
$field = static fn (string $key, mixed $default = ''): mixed => array_key_exists($key, $old) ? $old[$key] : ($record[$key] ?? $default);
$userIds = array_map('intval', (array) ($old['user_ids'] ?? ($record['user_ids'] ?? [])));
$isEdit = $record !== null;
$isLdap = $isEdit && $record['source'] === 'ldap';
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Administration · Groups</div>
            <h1><?= $isEdit ? 'Edit ' . $e($record['name']) : 'Create group' ?></h1>
        </div>
        <a class="button button--ghost" href="/admin/groups">Back to groups</a>
    </div>

    <?php if (!empty($formError)): ?><div class="alert alert--error"><?= $e($formError) ?></div><?php endif; ?>
    <?php if ($isLdap): ?><div class="alert">LDAP groups are read-only locally. Membership is managed by directory synchronization.</div><?php endif; ?>

    <section class="card form-card">
        <form method="post" action="<?= $e($formAction) ?>" class="form-stack">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <label>Name
                <input name="name" maxlength="150" value="<?= $e($field('name')) ?>" required <?= $isLdap ? 'disabled' : '' ?>>
            </label>
            <label>Slug
                <input name="slug" maxlength="150" value="<?= $e($field('slug')) ?>" <?= $isLdap ? 'disabled' : '' ?>>
            </label>
            <label>Description
                <textarea name="description" maxlength="500" rows="3" <?= $isLdap ? 'disabled' : '' ?>><?= $e($field('description')) ?></textarea>
            </label>

            <?php if (!$isLdap): ?>
                <fieldset>
                    <legend>Members</legend>
                    <div class="scope-grid directory-selection">
                        <?php foreach ($users as $user): ?>
                            <label class="check-row">
                                <input type="checkbox" name="user_ids[]" value="<?= (int) $user['id'] ?>" <?= in_array((int) $user['id'], $userIds, true) ? 'checked' : '' ?>>
                                <span><?= $e($user['username']) ?> <small><?= $e($user['email']) ?></small></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <button class="button button--primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create group' ?></button>
            <?php endif; ?>
        </form>
    </section>

    <?php if ($isEdit && !$isLdap): ?>
        <section class="card panel danger-zone">
            <form method="post" action="/admin/groups/<?= (int) $record['id'] ?>/delete" onsubmit="return confirm('Delete this group?')">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <button class="button button--ghost" type="submit">Delete group</button>
            </form>
        </section>
    <?php endif; ?>
</section>
