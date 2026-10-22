<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$displayName = trim((string) ($profile['first_name'] ?? '') . ' ' . (string) ($profile['last_name'] ?? ''));
if ($displayName === '') $displayName = (string) $profile['username'];
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
            <p class="muted"><?= $e($t('Choose the language used for the interface.')) ?></p>
            <?php require __DIR__ . '/../partials/language-form.php'; ?>
            <p class="field-help account-settings-theme-help"><?= $e($t('Change the color theme from the appearance button in the top bar.')) ?></p>
        </section>
    </div>
</section>
