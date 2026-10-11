<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="narrow-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow"><?= $e($t('Account')) ?></div>
            <h1><?= $e($t('Change password')) ?></h1>
            <?php if ($forced): ?>
                <p class="muted"><?= $e($t('Your administrator requires a password change before you can continue.')) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <section class="card form-card">
        <?php if (!empty($formError)): ?>
            <div class="alert alert--error" role="alert"><?= $e($formError) ?></div>
        <?php endif; ?>

        <form method="post" action="/account/change-password" class="form-stack">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <label><?= $e($t('Current password')) ?>
                <input type="password" name="current_password" autocomplete="current-password" required>
            </label>
            <label><?= $e($t('New password')) ?>
                <input type="password" name="new_password" minlength="12" autocomplete="new-password" required>
                <span class="field-help"><?= $e($t('Minimum 12 characters.')) ?></span>
            </label>
            <label><?= $e($t('Confirm new password')) ?>
                <input type="password" name="new_password_confirmation" minlength="12" autocomplete="new-password" required>
            </label>
            <button class="button button--primary" type="submit"><?= $e($t('Change password')) ?></button>
        </form>
    </section>
</section>
