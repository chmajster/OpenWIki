<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$value = static function (string $key, mixed $fallback = '') use ($old, $config): mixed {
    return array_key_exists($key, $old) ? $old[$key] : ($config[$key] ?? $fallback);
};
$enabled = array_key_exists('enabled', $old)
    ? filter_var($old['enabled'], FILTER_VALIDATE_BOOL)
    : (bool) ($config['enabled'] ?? false);
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Administration</div>
            <h1>LDAP / Active Directory</h1>
            <p class="muted">Authenticate directory users, create local profiles after first login and map directory groups to OpenWiki roles.</p>
        </div>
    </div>

    <?php if (!empty($formError)): ?>
        <div class="alert alert--error" role="alert"><?= $e($formError) ?></div>
    <?php endif; ?>
    <?php if (!empty($testSuccess)): ?>
        <div class="alert alert--success" role="status"><?= $e($testSuccess) ?></div>
    <?php endif; ?>

    <form class="card form-card form-stack" method="post" action="/admin/ldap">
        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">

        <label>
            <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
            Enable LDAP / Active Directory authentication
        </label>

        <div class="form-grid">
            <label>Host
                <input name="host" required maxlength="255" value="<?= $e($value('host')) ?>" placeholder="ldap.example.com">
            </label>
            <label>Port
                <input name="port" type="number" min="1" max="65535" required value="<?= (int) $value('port', 389) ?>">
            </label>
            <label>Connection
                <select name="security">
                    <option value="ldap" <?= $value('security', 'ldap') === 'ldap' ? 'selected' : '' ?>>LDAP</option>
                    <option value="ldaps" <?= $value('security', 'ldap') === 'ldaps' ? 'selected' : '' ?>>LDAPS</option>
                </select>
            </label>
        </div>

        <label>Base DN
            <input name="base_dn" required maxlength="1000" value="<?= $e($value('base_dn')) ?>" placeholder="dc=example,dc=com">
        </label>

        <div class="form-grid">
            <label>Bind DN
                <input name="bind_dn" maxlength="1000" value="<?= $e($value('bind_dn')) ?>" placeholder="cn=openwiki,ou=service,dc=example,dc=com">
            </label>
            <label>Bind Password
                <input name="bind_password" type="password" maxlength="2048" autocomplete="new-password" placeholder="<?= !empty($config['bind_password_set']) ? 'Stored password — leave blank to keep' : 'Bind password' ?>">
            </label>
        </div>

        <div class="form-grid">
            <label>User DN
                <input name="user_dn" maxlength="1000" value="<?= $e($value('user_dn')) ?>" placeholder="ou=users,dc=example,dc=com">
            </label>
            <label>Group DN
                <input name="group_dn" maxlength="1000" value="<?= $e($value('group_dn')) ?>" placeholder="ou=groups,dc=example,dc=com">
            </label>
        </div>

        <h2>Attributes</h2>
        <div class="form-grid">
            <label>Username attribute
                <input name="username_attribute" required maxlength="64" value="<?= $e($value('username_attribute', 'uid')) ?>">
            </label>
            <label>Email attribute
                <input name="mail_attribute" required maxlength="64" value="<?= $e($value('mail_attribute', 'mail')) ?>">
            </label>
            <label>First name attribute
                <input name="first_name_attribute" required maxlength="64" value="<?= $e($value('first_name_attribute', 'givenName')) ?>">
            </label>
            <label>Last name attribute
                <input name="last_name_attribute" required maxlength="64" value="<?= $e($value('last_name_attribute', 'sn')) ?>">
            </label>
            <label>Group membership attribute
                <input name="group_attribute" required maxlength="64" value="<?= $e($value('group_attribute', 'memberOf')) ?>">
            </label>
        </div>

        <h2>Group mapping</h2>
        <p class="muted">One mapping per line: LDAP group DN or CN <code>=&gt;</code> OpenWiki role slug.</p>
        <textarea name="group_mapping" rows="8" placeholder="CN=Wiki Editors,OU=Groups,DC=example,DC=com => editor"><?= $e($value('group_mapping')) ?></textarea>

        <details>
            <summary>Available role slugs</summary>
            <ul>
                <?php foreach ($roles as $role): ?>
                    <li><code><?= $e($role['slug']) ?></code> — <?= $e($role['name']) ?></li>
                <?php endforeach; ?>
            </ul>
        </details>

        <div class="form-actions">
            <button class="button button--secondary" type="submit" formaction="/admin/ldap/test">Test connection</button>
            <button class="button button--primary" type="submit">Save settings</button>
        </div>
    </form>

    <div class="card panel">
        <h2>Security behavior</h2>
        <ul>
            <li>The directory password entered at login is used only for the LDAP bind and is never stored.</li>
            <li>The service bind password is encrypted at rest.</li>
            <li>An LDAP identity cannot claim an existing local account with the same username or email.</li>
            <li>Only role assignments created by LDAP synchronization are removed when directory group membership changes.</li>
        </ul>
    </div>
</section>
