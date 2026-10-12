<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$value = static fn (string $key, mixed $fallback = ''): string => $e($defaults[$key] ?? $fallback);
$relativePath = static function (string $path) use ($app, $e): string {
    $base = rtrim($app->basePath(), '/\\') . DIRECTORY_SEPARATOR;
    $relative = str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    return $e(str_replace('\\', '/', $relative));
};
$timezones = timezone_identifiers_list();
if (!in_array('UTC', $timezones, true)) {
    array_unshift($timezones, 'UTC');
}
natcasesort($timezones);
$timezones = array_values($timezones);
?>
<section class="install-layout install-layout--wizard">
    <div
        class="install-wizard"
        data-install-wizard
        data-requirements-ok="<?= $requirements['ok'] ? '1' : '0' ?>"
    >
        <aside class="card install-sidebar" aria-label="<?= $e($t('Installation progress')) ?>">
            <div class="eyebrow"><?= $e($t('OpenWiki setup')) ?></div>
            <h1><?= $e($t('Installation')) ?></h1>
            <p class="muted"><?= $e($t('Configure a new OpenWiki instance directly in the browser.')) ?></p>

            <ol class="install-steps">
                <li class="is-active" data-wizard-indicator="1">
                    <span>1</span>
                    <div><strong><?= $e($t('Requirements')) ?></strong><small><?= $e($t('Runtime and permissions')) ?></small></div>
                </li>
                <li data-wizard-indicator="2">
                    <span>2</span>
                    <div><strong><?= $e($t('Instance')) ?></strong><small><?= $e($t('Name, URL and timezone')) ?></small></div>
                </li>
                <li data-wizard-indicator="3">
                    <span>3</span>
                    <div><strong><?= $e($t('Database')) ?></strong><small><?= $e($t('MySQL / MariaDB connection')) ?></small></div>
                </li>
                <li data-wizard-indicator="4">
                    <span>4</span>
                    <div><strong><?= $e($t('Install')) ?></strong><small><?= $e($t('Review and initialize')) ?></small></div>
                </li>
            </ol>

            <div class="install-bootstrap-note">
                <span class="muted"><?= $e($t('Initial administrator')) ?></span>
                <strong>admin / admin</strong>
                <small><?= $e($t('Password change is required after the first sign-in.')) ?></small>
            </div>
        </aside>

        <main class="card install-card">
            <div class="install-card__header">
                <div>
                    <div class="eyebrow"><?= $e($t('First-run wizard')) ?></div>
                    <h2><?= $e($t('Install OpenWiki')) ?></h2>
                </div>
                <span class="install-badge"><?= $e($t('Fresh instance')) ?></span>
            </div>

            <?php if (!empty($installError)): ?>
                <div class="alert alert--error" role="alert">
                    <strong><?= $e($t('Installation failed.')) ?></strong>
                    <span><?= $e($installError) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="/install" class="install-form" data-wizard-form>
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">

                <section class="install-panel is-active" data-wizard-panel="1">
                    <div class="install-panel__heading">
                        <span class="install-step-number">1</span>
                        <div>
                            <h3><?= $e($t('Server requirements')) ?></h3>
                            <p><?= $e($t('All required checks must pass before installation can start.')) ?></p>
                        </div>
                    </div>

                    <div class="check-grid install-checks">
                        <div class="check-row">
                            <span>PHP <?= $e($requirements['php']['minimum']) ?>+</span>
                            <strong class="<?= $requirements['php']['ok'] ? 'status-ok' : 'status-fail' ?>">
                                <?= $e($requirements['php']['current']) ?>
                            </strong>
                        </div>
                        <?php foreach ($requirements['extensions'] as $extension => $ok): ?>
                            <div class="check-row">
                                <span><?= $e($t('PHP extension:')) ?> <?= $e($extension) ?></span>
                                <strong class="<?= $ok ? 'status-ok' : 'status-fail' ?>"><?= $ok ? 'OK' : 'Missing' ?></strong>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach ($requirements['writable'] as $path => $ok): ?>
                            <div class="check-row">
                                <span><?= $e($t('Writable:')) ?> <?= $relativePath($path) ?></span>
                                <strong class="<?= $ok ? 'status-ok' : 'status-fail' ?>"><?= $ok ? 'OK' : 'No' ?></strong>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach ($requirements['assets'] as $label => $ok): ?>
                            <div class="check-row"><span><?= $e($t($label)) ?></span><strong class="<?= $ok ? 'status-ok' : 'status-fail' ?>"><?= $ok ? 'OK' : $e($t('Missing')) ?></strong></div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!$requirements['ok']): ?>
                        <div class="alert alert--warning" role="alert">
                            <?= $e($t('Fix the failed requirements and reload this page before continuing.')) ?>
                            <?php if (in_array(false, $requirements['assets'], true)): ?>
                                <p><?= $e($t('Use a complete release package or run composer install, npm ci and npm run build before installation.')) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="wizard-actions" data-wizard-only>
                        <span></span>
                        <button class="button button--primary" type="button" data-wizard-next <?= !$requirements['ok'] ? 'disabled' : '' ?>>
                            <?= $e($t('Continue')) ?>
                        </button>
                    </div>
                </section>

                <section class="install-panel" data-wizard-panel="2">
                    <div class="install-panel__heading">
                        <span class="install-step-number">2</span>
                        <div>
                            <h3><?= $e($t('Instance settings')) ?></h3>
                            <p><?= $e($t('These values identify this OpenWiki installation.')) ?></p>
                        </div>
                    </div>

                    <div class="form-stack">
                        <label><?= $e($t('Instance name')) ?>
                            <input name="app_name" required maxlength="120" value="<?= $value('app_name', 'OpenWiki') ?>">
                        </label>
                        <label><?= $e($t('Application URL')) ?>
                            <input name="app_url" type="url" required value="<?= $value('app_url') ?>">
                            <span class="field-help"><?= $e($t('Public base URL used by OpenWiki, including http:// or https://.')) ?></span>
                        </label>
                        <label><?= $e($t('Timezone')) ?>
                            <div
                                class="timezone-picker"
                                data-timezone-picker
                                data-timezone-autodetect="<?= empty($installError) ? '1' : '0' ?>"
                            >
                                <input
                                    name="timezone"
                                    required
                                    value="<?= $value('timezone', 'UTC') ?>"
                                    list="openwiki-timezones"
                                    autocomplete="off"
                                    data-timezone-input
                                >
                                <button class="button button--ghost" type="button" data-timezone-detect>
                                    <?= $e($t('Detect from device')) ?>
                                </button>
                            </div>
                            <span class="field-help" data-timezone-help>
                                <?= $e($t('Choose a PHP timezone identifier or let OpenWiki detect it from this browser.')) ?>
                            </span>
                        </label>
                        <datalist id="openwiki-timezones">
                            <?php foreach ($timezones as $timezone): ?>
                                <option value="<?= $e($timezone) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="wizard-actions" data-wizard-only>
                        <button class="button button--ghost" type="button" data-wizard-prev><?= $e($t('Back')) ?></button>
                        <button class="button button--primary" type="button" data-wizard-next><?= $e($t('Continue')) ?></button>
                    </div>
                </section>

                <section class="install-panel" data-wizard-panel="3">
                    <div class="install-panel__heading">
                        <span class="install-step-number">3</span>
                        <div>
                            <h3><?= $e($t('Database connection')) ?></h3>
                            <p><?= $e($t('OpenWiki requires MySQL 8 or a compatible MariaDB server.')) ?></p>
                        </div>
                    </div>

                    <div class="form-stack">
                        <div class="form-grid">
                            <label><?= $e($t('Host')) ?>
                                <input <?= !empty($managedDatabase) ? 'readonly' : '' ?> name="db_host" required value="<?= $value('db_host', '127.0.0.1') ?>">
                            </label>
                            <label><?= $e($t('Port')) ?>
                                <input <?= !empty($managedDatabase) ? 'readonly' : '' ?> name="db_port" type="number" min="1" max="65535" required value="<?= $value('db_port', '3306') ?>">
                            </label>
                        </div>
                        <label><?= $e($t('Database name')) ?>
                            <input <?= !empty($managedDatabase) ? 'readonly' : '' ?> name="db_database" required pattern="[A-Za-z0-9_]+" value="<?= $value('db_database', 'openwiki') ?>">
                            <span class="field-help"><?= $e($t('Letters, numbers and underscore only.')) ?></span>
                        </label>
                        <label><?= $e($t('Database username')) ?>
                            <input <?= !empty($managedDatabase) ? 'readonly' : '' ?> name="db_username" required autocomplete="username" value="<?= $value('db_username') ?>">
                        </label>
                        <label><?= $e($t('Database password')) ?>
                            <input <?= !empty($managedDatabase) ? 'readonly' : '' ?> name="db_password" type="password" autocomplete="current-password">
                        </label>
                        <label class="checkbox-row install-checkbox">
                            <input <?= !empty($managedDatabase) ? 'disabled' : '' ?> name="create_database" type="checkbox" value="1" <?= !empty($defaults['create_database']) ? 'checked' : '' ?>>
                            <span>
                                <strong><?= $e($t('Create database automatically if it does not exist')) ?></strong>
                                <small><?= $e($t('The supplied database account must have CREATE DATABASE permission.')) ?></small>
                            </span>
                        </label>

                        <?php if (!empty($managedDatabase)): ?><p class="muted">Docker: baza i konto są przygotowane. Hasło pochodzi z prywatnej konfiguracji kontenera; nie wpisuj go w formularzu.</p><?php endif; ?>
                        <div class="database-test" data-database-test data-test-url="/install/test-database">
                            <div class="database-test__actions">
                                <button class="button button--secondary" type="button" data-database-test-button>
                                    <?= $e($t('Test connection')) ?>
                                </button>
                                <span class="database-test__hint"><?= $e($t('A successful test is required before continuing.')) ?></span>
                            </div>
                            <div
                                class="database-test__status"
                                data-database-test-status
                                role="status"
                                aria-live="polite"
                                hidden
                            ></div>
                        </div>
                    </div>

                    <div class="wizard-actions" data-wizard-only>
                        <button class="button button--ghost" type="button" data-wizard-prev><?= $e($t('Back')) ?></button>
                        <button class="button button--primary" type="button" data-wizard-next data-database-next disabled>
                            <?= $e($t('Review')) ?>
                        </button>
                    </div>
                </section>

                <section class="install-panel" data-wizard-panel="4">
                    <div class="install-panel__heading">
                        <span class="install-step-number">4</span>
                        <div>
                            <h3><?= $e($t('Review and install')) ?></h3>
                            <p><?= $e($t('The installer will create the schema, seed permissions and templates, write .env and lock the installer.')) ?></p>
                        </div>
                    </div>

                    <dl class="install-summary">
                        <div><dt><?= $e($t('Instance')) ?></dt><dd data-wizard-summary="app_name"><?= $value('app_name', 'OpenWiki') ?></dd></div>
                        <div><dt><?= $e($t('URL')) ?></dt><dd data-wizard-summary="app_url"><?= $value('app_url') ?></dd></div>
                        <div><dt><?= $e($t('Timezone')) ?></dt><dd data-wizard-summary="timezone"><?= $value('timezone', 'UTC') ?></dd></div>
                        <div><dt><?= $e($t('Database')) ?></dt><dd><span data-wizard-summary="db_host"><?= $value('db_host', '127.0.0.1') ?></span>:<span data-wizard-summary="db_port"><?= $value('db_port', '3306') ?></span> / <span data-wizard-summary="db_database"><?= $value('db_database', 'openwiki') ?></span></dd></div>
                    </dl>

                    <div class="install-credentials">
                        <div>
                            <span class="eyebrow"><?= $e($t('Default administrator')) ?></span>
                            <h4>admin / admin</h4>
                            <p><?= $e($t('The password is hashed in the database. OpenWiki will require a new password immediately after the first successful sign-in.')) ?></p>
                        </div>
                        <code>admin</code>
                    </div>

                    <div class="wizard-actions">
                        <button class="button button--ghost" type="button" data-wizard-prev data-wizard-only><?= $e($t('Back')) ?></button>
                        <button class="button button--primary button--large" type="submit" <?= !$requirements['ok'] ? 'disabled' : '' ?>>
                            <?= $e($t('Install OpenWiki')) ?>
                        </button>
                    </div>
                </section>
            </form>
        </main>
    </div>
</section>
