<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="auth-layout">
    <div class="card auth-card">
        <div class="eyebrow">Multi-factor authentication</div>
        <h1>Verify sign-in</h1>
        <p class="muted">Enter the current six-digit TOTP code or one unused recovery code.</p>

        <?php if (!empty($mfaError)): ?>
            <div class="alert alert--error" role="alert"><?= $e($mfaError) ?></div>
        <?php endif; ?>

        <form method="post" action="/mfa/challenge" class="form-stack">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <label>Authentication or recovery code
                <input name="code" required autofocus autocomplete="one-time-code">
            </label>
            <button class="button button--primary button--large" type="submit">Verify</button>
        </form>
    </div>
</section>
