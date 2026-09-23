<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="narrow-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Account</div>
            <h1>Change password</h1>
            <?php if ($forced): ?>
                <p class="muted">Your administrator requires a password change before you can continue.</p>
            <?php endif; ?>
        </div>
    </div>

    <section class="card form-card">
        <?php if (!empty($formError)): ?>
            <div class="alert alert--error" role="alert"><?= $e($formError) ?></div>
        <?php endif; ?>

        <form method="post" action="/account/change-password" class="form-stack">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <label>Current password
                <input type="password" name="current_password" autocomplete="current-password" required>
            </label>
            <label>New password
                <input type="password" name="new_password" minlength="12" autocomplete="new-password" required>
                <span class="field-help">Minimum 12 characters.</span>
            </label>
            <label>Confirm new password
                <input type="password" name="new_password_confirmation" minlength="12" autocomplete="new-password" required>
            </label>
            <button class="button button--primary" type="submit">Change password</button>
        </form>
    </section>
</section>
