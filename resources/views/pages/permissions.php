<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$baseUrl = '/spaces/' . rawurlencode((string) $space['space_key'])
    . '/pages/' . rawurlencode((string) $page['slug'])
    . '/permissions';
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow"><?= $e($space['name']) ?></div>
            <h1><?= $e($t('Page permissions')) ?></h1>
            <p class="muted"><?= $e($page['title']) ?></p>
        </div>
        <a class="button button--ghost" href="/spaces/<?= rawurlencode((string) $space['space_key']) ?>/pages/<?= rawurlencode((string) $page['slug']) ?>"><?= $e($t('Back to page')) ?></a>
    </div>

    <div class="card panel">
        <h2><?= $e($t('Inheritance')) ?></h2>
        <p class="muted"><?= $e($t('When enabled, matching ACL rules from parent pages are inherited. Only parent rules marked for child inheritance propagate.')) ?></p>
        <form method="post" action="<?= $e($baseUrl) ?>/inheritance" class="form-stack">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <label>
                <input type="checkbox" name="inherit_acl" value="1" <?= !empty($page['inherit_acl']) ? 'checked' : '' ?>>
                <?= $e($t('Inherit ACL from parent pages')) ?>
            </label>
            <button class="button button--secondary" type="submit"><?= $e($t('Save inheritance')) ?></button>
        </form>
    </div>

    <form class="card form-card form-stack" method="post" action="<?= $e($baseUrl) ?>/rules">
        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
        <h2><?= $e($t('Add or update rule')) ?></h2>

        <label><?= $e($t('Principal')) ?>
            <select name="principal" required>
                <option value=""><?= $e($t('Select user, group or role')) ?></option>
                <optgroup label="<?= $e($t('Users')) ?>">
                    <?php foreach ($users as $user): ?>
                        <option value="user:<?= (int) $user['id'] ?>"><?= $e($user['username']) ?> — <?= $e($user['email']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="<?= $e($t('Groups')) ?>">
                    <?php foreach ($groups as $group): ?>
                        <option value="group:<?= (int) $group['id'] ?>"><?= $e($group['name']) ?> (<?= $e($group['source']) ?>)</option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="<?= $e($t('Roles')) ?>">
                    <?php foreach ($roles as $role): ?>
                        <option value="role:<?= (int) $role['id'] ?>"><?= $e($role['name']) ?> — <?= $e($role['slug']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </label>

        <div class="form-grid">
            <label><?= $e($t('Permission')) ?>
                <select name="permission" required>
                    <?php foreach ($permissions as $permission): ?>
                        <option value="<?= $e($permission['name']) ?>"><?= $e($permission['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?= $e($t('Effect')) ?>
                <select name="effect">
                    <option value="allow"><?= $e($t('Allow')) ?></option>
                    <option value="deny"><?= $e($t('Deny')) ?></option>
                </select>
            </label>
        </div>

        <label>
            <input type="checkbox" name="inherit_to_children" value="1" checked>
            <?= $e($t('Propagate this rule to child pages')) ?>
        </label>

        <button class="button button--primary" type="submit"><?= $e($t('Save ACL rule')) ?></button>
    </form>

    <section class="card panel">
        <div class="panel__header">
            <h2><?= $e($t('Direct rules')) ?></h2>
            <span class="count"><?= count($rules) ?></span>
        </div>

        <?php if ($rules === []): ?>
            <div class="empty-state"><?= $e($t('No direct ACL rules. Access follows Space permissions and inherited page rules.')) ?></div>
        <?php else: ?>
            <div class="item-list">
                <?php foreach ($rules as $rule): ?>
                    <div class="item-row">
                        <div>
                            <strong><?= $e($rule['principal_name']) ?></strong>
                            <div><?= $e($rule['principal_type']) ?> · <?= $e($rule['permission']) ?> · <?= $e($rule['effect']) ?></div>
                            <small><?= !empty($rule['inherit_to_children']) ? 'Inherited by child pages' : 'This page only' ?></small>
                        </div>
                        <form method="post" action="<?= $e($baseUrl) ?>/rules/<?= (int) $rule['id'] ?>/delete" data-confirm="<?= $e($t('Delete this ACL rule?')) ?>">
                            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                            <button class="button button--ghost" type="submit"><?= $e($t('Delete')) ?></button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</section>
