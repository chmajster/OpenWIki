<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="narrow-container mfa-page">
    <div class="page-heading page-heading--compact">
        <div>
            <div class="eyebrow"><?= $e($t('Account security')) ?></div>
            <h1><?= $e($t('Multi-factor authentication')) ?></h1>
            <p class="muted"><?= $e($t('TOTP is compatible with Google Authenticator, Microsoft Authenticator, Authy and 1Password.')) ?></p>
        </div>
    </div>

    <?php if (is_array($recoveryCodes)): ?>
        <div class="card panel mfa-state-card mfa-state-card--success">
            <span class="mfa-state-card__icon" aria-hidden="true">✓</span>
            <div>
                <div class="eyebrow"><?= $e($t('MFA enabled')) ?></div>
                <h2><?= $e($t('Recovery codes')) ?></h2>
                <p><?= $e($t('Store these codes securely. Each code can be used once and will not be shown again.')) ?></p>
                <pre class="mfa-recovery-codes"><?php foreach ($recoveryCodes as $code): ?><?= $e($code) . "\n" ?><?php endforeach; ?></pre>
            </div>
        </div>
    <?php elseif ($enabled): ?>
        <div class="card panel mfa-state-card mfa-state-card--success">
            <span class="mfa-state-card__icon" aria-hidden="true">✓</span>
            <div>
                <div class="eyebrow"><?= $e($t('Account security')) ?></div>
                <h2><?= $e($t('MFA enabled')) ?></h2>
                <p><?= $e($t('Your account requires a TOTP or unused recovery code during sign-in.')) ?></p>
            </div>
        </div>
    <?php else: ?>
        <div class="card mfa-enrollment-card">
            <header class="mfa-enrollment-header">
                <span class="mfa-enrollment-icon" aria-hidden="true">2FA</span>
                <div>
                    <div class="eyebrow"><?= $e($t('Account security')) ?></div>
                    <h2><?= $e($t($required ? 'MFA setup required' : 'Enable MFA')) ?></h2>
                    <p><?= $e($t('Scan this QR code with your authenticator app, or set up your account manually.')) ?></p>
                </div>
            </header>

            <div class="mfa-enrollment-content">
                <section class="mfa-setup-step" aria-labelledby="mfa-step-setup-title">
                    <span class="mfa-step-number" aria-hidden="true">1</span>
                    <div class="mfa-setup-step__content">
                        <h3 id="mfa-step-setup-title"><?= $e($t('Add authenticator account')) ?></h3>
                        <div class="mfa-qr-layout">
                            <div class="mfa-qr-card">
                                <div class="mfa-qr-frame" data-mfa-qr-frame data-state="loading">
                                    <div class="mfa-qr-placeholder" data-mfa-qr-placeholder aria-hidden="true">QR</div>
                                    <canvas data-mfa-qr-canvas role="img" aria-label="<?= $e($t('QR code for TOTP setup')) ?>" hidden></canvas>
                                </div>
                                <p class="mfa-qr-caption"><?= $e($t('Scan this code to add your account.')) ?></p>
                                <p class="field-help mfa-qr-status" data-mfa-qr-status role="status" aria-live="polite"><?= $e($t('Preparing QR code…')) ?></p>
                                <noscript><span class="field-help"><?= $e($t('JavaScript is required to generate the QR code. You can also set up MFA with the key below.')) ?></span></noscript>
                            </div>

                            <div class="mfa-qr-manual">
                                <p class="field-help"><?= $e($t('This QR code contains your MFA secret. Keep it private.')) ?></p>
                                <div class="mfa-copy-field" data-copy-field>
                                    <label for="mfa-secret"><?= $e($t('Secret')) ?></label>
                                    <div class="mfa-copy-field__control">
                                        <input id="mfa-secret" class="mfa-secret-input" data-copy-source readonly value="<?= $e($enrollment['secret']) ?>" autocomplete="off" spellcheck="false">
                                        <button class="button button--secondary mfa-copy-button" type="button" data-copy-target="mfa-secret"><?= $e($t('Copy secret')) ?></button>
                                    </div>
                                    <span class="field-help" data-copy-status role="status" aria-live="polite"></span>
                                </div>

                                <details class="mfa-uri-details" data-close-on-escape>
                                    <summary><?= $e($t('Use setup URI instead')) ?></summary>
                                    <div class="mfa-copy-field" data-copy-field>
                                        <p class="field-help"><?= $e($t('Some authenticator apps let you paste this setup address. Keep it private.')) ?></p>
                                        <label for="mfa-otpauth-uri"><?= $e($t('Setup URI')) ?></label>
                                        <textarea id="mfa-otpauth-uri" class="mfa-uri-input" data-copy-source readonly rows="3" spellcheck="false"><?= $e($enrollment['otpauth_uri']) ?></textarea>
                                        <button class="button button--secondary mfa-copy-button" type="button" data-copy-target="mfa-otpauth-uri"><?= $e($t('Copy setup URI')) ?></button>
                                        <span class="field-help" data-copy-status role="status" aria-live="polite"></span>
                                    </div>
                                </details>
                            </div>
                        </div>
                    </div>
                </section>

                <form method="post" action="/account/mfa/confirm" class="mfa-setup-step mfa-confirm-form">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <span class="mfa-step-number" aria-hidden="true">2</span>
                    <div class="mfa-setup-step__content">
                        <h3><?= $e($t('Confirm setup')) ?></h3>
                        <p class="muted"><?= $e($t('Enter the current six-digit code from your authenticator app.')) ?></p>
                        <label for="mfa-authentication-code"><?= $e($t('Authentication code')) ?></label>
                        <input id="mfa-authentication-code" class="mfa-code-input" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" required aria-describedby="mfa-code-help">
                        <span id="mfa-code-help" class="field-help"><?= $e($t('Enter all six digits shown in your authenticator app.')) ?></span>
                        <button class="button button--primary button--large mfa-submit-button" type="submit"><?= $e($t('Confirm and enable MFA')) ?></button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</section>
