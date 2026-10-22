<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$spaceUrl = '/spaces/' . rawurlencode((string) $space['space_key']);
$pageUrl = $spaceUrl . '/pages/' . rawurlencode((string) $page['slug']);
$baseUrl = $pageUrl . '/permissions';
require __DIR__ . '/../partials/ui-icons.php';
$permissionLabels = [
    'page.view' => 'View page', 'page.create' => 'Create pages', 'page.edit' => 'Edit page content',
    'page.delete' => 'Delete page', 'page.publish' => 'Publish page', 'page.archive' => 'Archive page',
    'page.move' => 'Move page', 'page.review' => 'Review page', 'page.verify' => 'Verify page',
];
$principalLabels = ['user' => 'User', 'group' => 'Group', 'role' => 'Role'];
$inherits = !empty($page['inherit_acl']);
?>
<section class="content-container page-permissions" data-page-permissions>
    <nav class="breadcrumbs permissions-breadcrumbs" aria-label="<?= $e($t('Breadcrumbs')) ?>">
        <a href="<?= $e($spaceUrl) ?>"><?= $e($space['name']) ?></a><span aria-hidden="true">/</span>
        <a href="<?= $e($pageUrl) ?>" title="<?= $e($page['title']) ?>"><?= $e($page['title']) ?></a><span aria-hidden="true">/</span>
        <span aria-current="page"><?= $e($t('Permissions')) ?></span>
    </nav>
    <div class="page-heading permissions-heading">
        <div><h1><?= $e($t('Page permissions')) ?></h1>
            <p class="muted"><?= $e($t('Manage rules for this page and how they apply to child pages.')) ?></p>
        </div>
        <a class="button button--ghost" href="<?= $e($pageUrl) ?>"><?= $uiIcon('arrow') ?><?= $e($t('Back to page')) ?></a>
    </div>
    <section class="card permissions-inheritance" aria-labelledby="inheritance-heading">
        <div class="permissions-section-title">
            <span class="permissions-icon"><?= $uiIcon('link') ?></span>
            <div><h2 id="inheritance-heading"><?= $e($t('Inheritance')) ?></h2>
                <p id="inheritance-help"><?= $e($t('When enabled, matching ACL rules from parent pages are inherited. Only parent rules marked for child inheritance propagate.')) ?></p>
            </div>
            <span class="permissions-badge <?= $inherits ? 'permissions-badge--enabled' : '' ?>"><?= $e($t($inherits ? 'Enabled' : 'Disabled')) ?></span>
        </div>
        <form method="post" action="<?= $e($baseUrl) ?>/inheritance" class="permissions-inheritance-form">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <label class="permissions-checkbox"><input type="checkbox" name="inherit_acl" value="1" aria-describedby="inheritance-help" <?= $inherits ? 'checked' : '' ?>><span><?= $e($t('Inherit ACL from parent pages')) ?></span></label>
            <button class="button button--secondary" type="submit"><?= $e($t('Save inheritance')) ?></button>
        </form>
    </section>
    <div class="permissions-layout">
        <div class="permissions-main">
            <section class="card permissions-rules" aria-labelledby="direct-rules-heading">
                <header class="permissions-section-header">
                    <div><h2 id="direct-rules-heading"><?= $e($t('Direct rules')) ?> <span class="count" data-rule-count><?= count($rules) ?></span></h2>
                        <p><?= $e($t('Rules saved on this page. Inherited rules are not listed here.')) ?></p>
                    </div>
                    <a class="text-link" href="#page-rule-form"><?= $uiIcon('plus') ?><?= $e($t('Add rule')) ?></a>
                </header>
                <?php if ($rules === []): ?>
                    <div class="permissions-empty">
                        <span class="permissions-icon"><?= $uiIcon('document') ?></span>
                        <h3><?= $e($t('No direct rules yet')) ?></h3>
                        <p><?= $e($t('No direct ACL rules. Access follows Space permissions and inherited page rules.')) ?></p>
                        <a class="button button--secondary" href="#page-rule-form"><?= $e($t('Add the first rule')) ?></a>
                    </div>
                <?php else: ?>
                    <ul class="permissions-rule-list">
                        <?php foreach ($rules as $rule): ?>
                            <li class="permissions-rule" data-permission-rule>
                                <div class="permissions-principal">
                                    <span class="permissions-avatar" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr((string) $rule['principal_name'], 0, 1))) ?></span>
                                    <div><strong><?= $e($rule['principal_name']) ?></strong><small><?= $e($t($principalLabels[$rule['principal_type']] ?? $rule['principal_type'])) ?></small></div>
                                </div>
                                <span class="permissions-badge permissions-badge--<?= $rule['effect'] === 'allow' ? 'allow' : 'deny' ?>"><?= $uiIcon($rule['effect'] === 'allow' ? 'check' : 'close') ?><?= $e($t($rule['effect'] === 'allow' ? 'Allow' : 'Deny')) ?></span>
                                <div class="permissions-rule-detail">
                                    <span class="permissions-caption"><?= $e($t('Permission')) ?></span>
                                    <strong><?= $e($t($permissionLabels[$rule['permission']] ?? $rule['permission'])) ?></strong>
                                    <code><?= $e($rule['permission']) ?></code>
                                </div>
                                <div class="permissions-rule-detail">
                                    <span class="permissions-caption"><?= $e($t('Applies to')) ?></span>
                                    <span><?= $e($t(!empty($rule['inherit_to_children']) ? 'This page and child pages' : 'This page only')) ?></span>
                                </div>
                                <div class="permissions-rule-actions">
                                    <button type="button" class="button button--ghost" hidden data-edit-page-rule
                                        data-principal="<?= $e($rule['principal_type'] . ':' . $rule['principal_id']) ?>" data-principal-name="<?= $e($rule['principal_name']) ?>"
                                        data-permission="<?= $e($rule['permission']) ?>" data-effect="<?= $e($rule['effect']) ?>" data-children="<?= !empty($rule['inherit_to_children']) ? '1' : '0' ?>"
                                        aria-label="<?= $e($t('Edit rule') . ': ' . $rule['principal_name'] . ' · ' . $rule['permission']) ?>"><?= $uiIcon('edit') ?><?= $e($t('Edit')) ?></button>
                                    <form method="post" action="<?= $e($baseUrl) ?>/rules/<?= (int) $rule['id'] ?>/delete" data-confirm="<?= $e($t('Delete this ACL rule?')) ?>">
                                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                        <button class="button button--ghost permissions-delete" type="submit" aria-label="<?= $e($t('Delete rule') . ': ' . $rule['principal_name'] . ' · ' . $rule['permission']) ?>"><?= $e($t('Delete')) ?></button>
                                    </form>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
            <details class="permissions-help">
                <summary><?= $e($t('How page permissions work')) ?></summary>
                <ul>
                    <li><?= $e($t('Direct rules are evaluated before inherited rules. At the same level, a matching deny takes priority.')) ?></li>
                    <li><?= $e($t('Adding an allow rule creates an allow list for that permission: people who do not match it are denied.')) ?></li>
                    <li><?= $e($t('Page rules do not replace required space access or global permissions.')) ?></li>
                    <li><?= $e($t('Child pages inherit only rules marked for propagation, when their inheritance is enabled.')) ?></li>
                </ul>
            </details>
        </div>
        <form class="card permissions-rule-form form-stack" id="page-rule-form" method="post" action="<?= $e($baseUrl) ?>/rules" aria-labelledby="rule-form-heading" data-page-rule-form tabindex="-1">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <div><h2 id="rule-form-heading" data-rule-form-heading aria-live="polite"><?= $e($t('Add or update rule')) ?></h2>
                <p class="permissions-form-help"><?= $e($t('Saving the same recipient and permission updates the existing rule.')) ?></p>
            </div>
            <p class="permissions-editing" data-rule-editing hidden></p>
            <label><?= $e($t('Recipient')) ?>
                <select name="principal" required>
                    <option value=""><?= $e($t('Select user, group or role')) ?></option>
                    <optgroup label="<?= $e($t('Users')) ?>">
                        <?php foreach ($users as $user): ?><option value="user:<?= (int) $user['id'] ?>"><?= $e($user['username']) ?> — <?= $e($user['email']) ?></option><?php endforeach; ?>
                    </optgroup>
                    <optgroup label="<?= $e($t('Groups')) ?>">
                        <?php foreach ($groups as $group): ?><option value="group:<?= (int) $group['id'] ?>"><?= $e($group['name']) ?> (<?= $e($group['source']) ?>)</option><?php endforeach; ?>
                    </optgroup>
                    <optgroup label="<?= $e($t('Roles')) ?>">
                        <?php foreach ($roles as $role): ?><option value="role:<?= (int) $role['id'] ?>"><?= $e($role['name']) ?> — <?= $e($role['slug']) ?></option><?php endforeach; ?>
                    </optgroup>
                </select>
            </label>
            <label><?= $e($t('Permission')) ?>
                <select name="permission" required>
                    <?php foreach ($permissions as $permission): ?><option value="<?= $e($permission['name']) ?>" <?= $permission['name'] === 'page.view' ? 'selected' : '' ?>><?= $e($t($permissionLabels[$permission['name']] ?? $permission['name'])) ?> · <?= $e($permission['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label><?= $e($t('Effect')) ?>
                <select name="effect"><option value="allow"><?= $e($t('Allow')) ?></option><option value="deny"><?= $e($t('Deny')) ?></option></select>
            </label>
            <label class="permissions-checkbox">
                <input type="checkbox" name="inherit_to_children" value="1" checked aria-describedby="rule-children-help">
                <span><?= $e($t('Propagate this rule to child pages')) ?><small id="rule-children-help"><?= $e($t('Applies when child pages have inheritance enabled.')) ?></small></span>
            </label>
            <div class="permissions-form-actions">
                <button class="button button--primary" type="submit"><?= $e($t('Save ACL rule')) ?></button>
                <button class="button button--ghost" type="button" data-cancel-page-rule hidden><?= $e($t('Cancel editing')) ?></button>
            </div>
        </form>
    </div>
</section>
