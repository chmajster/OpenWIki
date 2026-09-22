<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="narrow-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Account security</div>
            <h1>Multi-factor authentication</h1>
            <p class="muted">TOTP is compatible with Google Authenticator, Microsoft Authenticator, Authy and 1Password.</p>
        </div>
    </div>

    <?php if (is_array($recoveryCodes)): ?>
        <div class="card panel">
            <h2>Recovery codes</h2>
            <p>Store these codes securely. Each code can be used once and will not be shown again.</p>
            <pre><?php foreach ($recoveryCodes as $code): ?><?= $e($code) . "\n" ?><?php endforeach; ?></pre>
        </div>
    <?php endif; ?>

    <?php if ($enabled): ?>
        <div class="card panel">
            <h2>MFA enabled</h2>
            <p>Your account requires a TOTP or unused recovery code during sign-in.</p>
        </div>
    <?php else: ?>
        <div class="card panel">
            <h2><?= $required ? 'MFA setup required' : 'Enable MFA' ?></h2>
            <p>Add a TOTP account using this secret or otpauth URI, then enter the current six-digit code.</p>

            <label>Secret
                <input readonly value="<?= $e($enrollment['secret']) ?>">
            </label>
            <label>otpauth URI
                <textarea readonly rows="4"><?= $e($enrollment['otpauth_uri']) ?></textarea>
            </label>

            <form method="post" action="/account/mfa/confirm" class="form-stack">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <label>Authentication code
                    <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                </label>
                <button class="button button--primary" type="submit">Confirm and enable MFA</button>
            </form>
        </div>
    <?php endif; ?>
</section>
