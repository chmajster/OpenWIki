<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$value = static fn (string $key, mixed $fallback = ''): string => $e($defaults[$key] ?? $fallback);
?>
<section class="install-layout">
    <div class="card install-card">
        <div class="eyebrow">OpenWiki setup</div>
        <h1>Install OpenWiki</h1>
        <p class="muted">The installer validates the server, connects to MySQL/MariaDB, applies migrations and creates the first Super Admin account.</p>

        <?php if (!empty($installError)): ?>
            <div class="alert alert--error" role="alert"><?= $e($installError) ?></div>
        <?php endif; ?>

        <h2>Server checks</h2>
        <div class="check-grid">
            <div class="check-row">
                <span>PHP <?= $e($requirements['php']['minimum']) ?>+</span>
                <strong class="<?= $requirements['php']['ok'] ? 'status-ok' : 'status-fail' ?>">
                    <?= $e($requirements['php']['current']) ?>
                </strong>
            </div>
            <?php foreach ($requirements['extensions'] as $extension => $ok): ?>
                <div class="check-row">
                    <span>PHP extension: <?= $e($extension) ?></span>
                    <strong class="<?= $ok ? 'status-ok' : 'status-fail' ?>"><?= $ok ? 'OK' : 'Missing' ?></strong>
                </div>
            <?php endforeach; ?>
            <?php foreach ($requirements['writable'] as $path => $ok): ?>
                <div class="check-row">
                    <span>Writable: <?= $e(basename($path) ?: $path) ?></span>
                    <strong class="<?= $ok ? 'status-ok' : 'status-fail' ?>"><?= $ok ? 'OK' : 'No' ?></strong>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="post" action="/install" class="form-stack">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">

            <fieldset>
                <legend>Instance</legend>
                <label>Instance name
                    <input name="app_name" required maxlength="120" value="<?= $value('app_name', 'OpenWiki') ?>">
                </label>
                <label>Application URL
                    <input name="app_url" type="url" required value="<?= $value('app_url') ?>">
                </label>
                <label>Timezone
                    <input name="timezone" required value="<?= $value('timezone', 'UTC') ?>">
                </label>
            </fieldset>

            <fieldset>
                <legend>Database</legend>
                <div class="form-grid">
                    <label>Host
                        <input name="db_host" required value="<?= $value('db_host', '127.0.0.1') ?>">
                    </label>
                    <label>Port
                        <input name="db_port" type="number" min="1" max="65535" required value="<?= $value('db_port', '3306') ?>">
                    </label>
                </div>
                <label>Database name
                    <input name="db_database" required pattern="[A-Za-z0-9_]+" value="<?= $value('db_database', 'openwiki') ?>">
                </label>
                <label>Database username
                    <input name="db_username" required autocomplete="username" value="<?= $value('db_username') ?>">
                </label>
                <label>Database password
                    <input name="db_password" type="password" autocomplete="new-password">
                </label>
                <label class="checkbox-row">
                    <input name="create_database" type="checkbox" value="1" <?= !empty($defaults['create_database']) ? 'checked' : '' ?>>
                    Create the database if it does not exist
                </label>
            </fieldset>

            <fieldset>
                <legend>Administrator</legend>
                <div class="form-grid">
                    <label>First name
                        <input name="admin_first_name" maxlength="100" value="<?= $value('admin_first_name') ?>">
                    </label>
                    <label>Last name
                        <input name="admin_last_name" maxlength="100" value="<?= $value('admin_last_name') ?>">
                    </label>
                </div>
                <label>Username
                    <input name="admin_username" required minlength="3" maxlength="100" pattern="[A-Za-z0-9._-]+" autocomplete="username" value="<?= $value('admin_username') ?>">
                </label>
                <label>Email
                    <input name="admin_email" type="email" required maxlength="191" value="<?= $value('admin_email') ?>">
                </label>
                <label>Password
                    <input name="admin_password" type="password" required minlength="12" autocomplete="new-password">
                    <span class="field-help">Minimum 12 characters. The password is hashed before storage.</span>
                </label>
            </fieldset>

            <button class="button button--primary button--large" type="submit" <?= !$requirements['ok'] ? 'disabled' : '' ?>>Install OpenWiki</button>
        </form>
    </div>
</section>
