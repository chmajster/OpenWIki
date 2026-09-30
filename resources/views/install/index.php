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
        <aside class="card install-sidebar" aria-label="Installation progress">
            <div class="eyebrow">OpenWiki setup</div>
            <h1>Installation</h1>
            <p class="muted">Configure a new OpenWiki instance directly in the browser.</p>

            <ol class="install-steps">
                <li class="is-active" data-wizard-indicator="1">
                    <span>1</span>
                    <div><strong>Requirements</strong><small>Runtime and permissions</small></div>
                </li>
                <li data-wizard-indicator="2">
                    <span>2</span>
                    <div><strong>Instance</strong><small>Name, URL and timezone</small></div>
                </li>
                <li data-wizard-indicator="3">
                    <span>3</span>
                    <div><strong>Database</strong><small>MySQL / MariaDB connection</small></div>
                </li>
                <li data-wizard-indicator="4">
                    <span>4</span>
                    <div><strong>Install</strong><small>Review and initialize</small></div>
                </li>
            </ol>

            <div class="install-bootstrap-note">
                <span class="muted">Initial administrator</span>
                <strong>admin / admin</strong>
                <small>Password change is required after the first sign-in.</small>
            </div>
        </aside>

        <main class="card install-card">
            <div class="install-card__header">
                <div>
                    <div class="eyebrow">First-run wizard</div>
                    <h2>Install OpenWiki</h2>
                </div>
                <span class="install-badge">Fresh instance</span>
            </div>

            <?php if (!empty($installError)): ?>
                <div class="alert alert--error" role="alert">
                    <strong>Installation failed.</strong>
                    <span><?= $e($installError) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="/install" class="install-form" data-wizard-form>
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">

                <section class="install-panel is-active" data-wizard-panel="1">
                    <div class="install-panel__heading">
                        <span class="install-step-number">1</span>
                        <div>
                            <h3>Server requirements</h3>
                            <p>All required checks must pass before installation can start.</p>
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
                                <span>PHP extension: <?= $e($extension) ?></span>
                                <strong class="<?= $ok ? 'status-ok' : 'status-fail' ?>"><?= $ok ? 'OK' : 'Missing' ?></strong>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach ($requirements['writable'] as $path => $ok): ?>
                            <div class="check-row">
                                <span>Writable: <?= $relativePath($path) ?></span>
                                <strong class="<?= $ok ? 'status-ok' : 'status-fail' ?>"><?= $ok ? 'OK' : 'No' ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!$requirements['ok']): ?>
                        <div class="alert alert--warning" role="alert">
                            Fix the failed requirements and reload this page before continuing.
                        </div>
                    <?php endif; ?>

                    <div class="wizard-actions" data-wizard-only>
                        <span></span>
                        <button class="button button--primary" type="button" data-wizard-next <?= !$requirements['ok'] ? 'disabled' : '' ?>>
                            Continue
                        </button>
                    </div>
                </section>

                <section class="install-panel" data-wizard-panel="2">
                    <div class="install-panel__heading">
                        <span class="install-step-number">2</span>
                        <div>
                            <h3>Instance settings</h3>
                            <p>These values identify this OpenWiki installation.</p>
                        </div>
                    </div>

                    <div class="form-stack">
                        <label>Instance name
                            <input name="app_name" required maxlength="120" value="<?= $value('app_name', 'OpenWiki') ?>">
                        </label>
                        <label>Application URL
                            <input name="app_url" type="url" required value="<?= $value('app_url') ?>">
                            <span class="field-help">Public base URL used by OpenWiki, including http:// or https://.</span>
                        </label>
                        <label>Timezone
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
                                    Detect from device
                                </button>
                            </div>
                            <span class="field-help" data-timezone-help>
                                Choose a PHP timezone identifier or let OpenWiki detect it from this browser.
                            </span>
                        </label>
                        <datalist id="openwiki-timezones">
                            <?php foreach ($timezones as $timezone): ?>
                                <option value="<?= $e($timezone) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="wizard-actions" data-wizard-only>
                        <button class="button button--ghost" type="button" data-wizard-prev>Back</button>
                        <button class="button button--primary" type="button" data-wizard-next>Continue</button>
                    </div>
                </section>

                <section class="install-panel" data-wizard-panel="3">
                    <div class="install-panel__heading">
                        <span class="install-step-number">3</span>
                        <div>
                            <h3>Database connection</h3>
                            <p>OpenWiki requires MySQL 8 or a compatible MariaDB server.</p>
                        </div>
                    </div>

                    <div class="form-stack">
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
                            <span class="field-help">Letters, numbers and underscore only.</span>
                        </label>
                        <label>Database username
                            <input name="db_username" required autocomplete="username" value="<?= $value('db_username') ?>">
                        </label>
                        <label>Database password
                            <input name="db_password" type="password" autocomplete="current-password">
                        </label>
                        <label class="checkbox-row install-checkbox">
                            <input name="create_database" type="checkbox" value="1" <?= !empty($defaults['create_database']) ? 'checked' : '' ?>>
                            <span>
                                <strong>Create database automatically if it does not exist</strong>
                                <small>The supplied database account must have CREATE DATABASE permission.</small>
                            </span>
                        </label>
                    </div>

                    <div class="wizard-actions" data-wizard-only>
                        <button class="button button--ghost" type="button" data-wizard-prev>Back</button>
                        <button class="button button--primary" type="button" data-wizard-next>Review</button>
                    </div>
                </section>

                <section class="install-panel" data-wizard-panel="4">
                    <div class="install-panel__heading">
                        <span class="install-step-number">4</span>
                        <div>
                            <h3>Review and install</h3>
                            <p>The installer will create the schema, seed permissions and templates, write .env and lock the installer.</p>
                        </div>
                    </div>

                    <dl class="install-summary">
                        <div><dt>Instance</dt><dd data-wizard-summary="app_name"><?= $value('app_name', 'OpenWiki') ?></dd></div>
                        <div><dt>URL</dt><dd data-wizard-summary="app_url"><?= $value('app_url') ?></dd></div>
                        <div><dt>Timezone</dt><dd data-wizard-summary="timezone"><?= $value('timezone', 'UTC') ?></dd></div>
                        <div><dt>Database</dt><dd><span data-wizard-summary="db_host"><?= $value('db_host', '127.0.0.1') ?></span>:<span data-wizard-summary="db_port"><?= $value('db_port', '3306') ?></span> / <span data-wizard-summary="db_database"><?= $value('db_database', 'openwiki') ?></span></dd></div>
                    </dl>

                    <div class="install-credentials">
                        <div>
                            <span class="eyebrow">Default administrator</span>
                            <h4>admin / admin</h4>
                            <p>The password is hashed in the database. OpenWiki will require a new password immediately after the first successful sign-in.</p>
                        </div>
                        <code>admin</code>
                    </div>

                    <div class="wizard-actions">
                        <button class="button button--ghost" type="button" data-wizard-prev data-wizard-only>Back</button>
                        <button class="button button--primary button--large" type="submit" <?= !$requirements['ok'] ? 'disabled' : '' ?>>
                            Install OpenWiki
                        </button>
                    </div>
                </section>
            </form>
        </main>
    </div>
</section>
