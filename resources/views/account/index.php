<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$displayName = trim((string) ($profile['first_name'] ?? '') . ' ' . (string) ($profile['last_name'] ?? ''));
if ($displayName === '') $displayName = (string) $profile['username'];
$dateFormat = (string) ($profile['date_format'] ?? 'locale');
$datePreview = \OpenWiki\Core\DateFormatter::formatDateTime('2026-10-23 15:45:00', $dateFormat, $locale);
?>
<section class="content-container account-settings-page">
    <header class="page-heading">
        <div>
            <div class="eyebrow"><?= $e($t('Account')) ?></div>
            <h1><?= $e($t('Profile and settings')) ?></h1>
            <p class="muted"><?= $e($t('Manage your account, security and preferences.')) ?></p>
        </div>
    </header>

    <div class="account-settings-grid">
        <section class="card account-settings-card">
            <h2><?= $e($t('Profile')) ?></h2>
            <dl class="account-profile-details">
                <div><dt><?= $e($t('Name')) ?></dt><dd><?= $e($displayName) ?></dd></div>
                <div><dt><?= $e($t('Username')) ?></dt><dd><?= $e($profile['username']) ?></dd></div>
                <div><dt><?= $e($t('Email')) ?></dt><dd><?= $e($profile['email']) ?></dd></div>
            </dl>
            <?php if ($profile['auth_source'] === 'local'): ?>
                <a class="button button--secondary" href="/account/change-password"><?= $e($t('Change password')) ?></a>
            <?php else: ?>
                <p class="field-help"><?= $e($t('Your password is managed by your identity provider.')) ?></p>
            <?php endif; ?>
        </section>

        <section class="card account-settings-card">
            <h2><?= $e($t('Sign-in and security')) ?></h2>
            <p class="muted"><?= $e($t('Manage multi-factor authentication and active sessions.')) ?></p>
            <div class="account-settings-actions">
                <a class="button button--secondary" href="/account/mfa/setup"><?= $e($t('Multi-factor authentication')) ?></a>
                <a class="button button--secondary" href="/account/sessions"><?= $e($t('Sessions')) ?></a>
            </div>
        </section>

        <section class="card account-settings-card">
            <h2><?= $e($t('Integrations')) ?></h2>
            <p class="muted"><?= $e($t('Create and revoke tokens used by integrations and automation.')) ?></p>
            <a class="button button--secondary" href="/account/api-tokens"><?= $e($t('API tokens')) ?></a>
        </section>

        <section class="card account-settings-card">
            <h2><?= $e($t('Preferences')) ?></h2>
            <p class="muted"><?= $e($t('Choose your language and date display format.')) ?></p>
            <?php require __DIR__ . '/../partials/language-form.php'; ?>
            <form method="post" action="/account/preferences" class="account-date-format-form">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <label for="account-date-format"><?= $e($t('Date format')) ?>
                    <select id="account-date-format" name="date_format">
                        <?php foreach ($dateFormats as $value => $label): ?><option value="<?= $e($value) ?>" <?= $dateFormat === $value ? 'selected' : '' ?>><?= $e($t($label)) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <button class="button button--secondary" type="submit"><?= $e($t('Save preferences')) ?></button>
            </form>
            <p class="field-help account-date-format-preview"><?= $e($t('Date preview')) ?>: <strong><?= $e($datePreview) ?></strong></p>
            <p class="field-help account-settings-theme-help"><?= $e($t('Change the color theme from the appearance button in the top bar.')) ?></p>
        </section>
    </div>
</section>
