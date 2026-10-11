<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$selected = array_map('intval', $policy['role_ids'] ?? []);
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow"><?= $e($t('Administration')) ?></div>
            <h1><?= $e($t('MFA policy')) ?></h1>
            <p class="muted"><?= $e($t('Require TOTP MFA globally or for selected roles.')) ?></p>
        </div>
    </div>

    <form class="card form-card form-stack" method="post" action="/admin/mfa">
        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">

        <label>
            <input type="checkbox" name="enforce_global" value="1" <?= !empty($policy['enforce_global']) ? 'checked' : '' ?>>
            <?= $e($t('Require MFA for every user')) ?>
        </label>

        <fieldset>
            <legend><?= $e($t('Require MFA for roles')) ?></legend>
            <div class="checkbox-grid">
                <?php foreach ($roles as $role): ?>
                    <label>
                        <input type="checkbox" name="role_ids[]" value="<?= (int) $role['id'] ?>" <?= in_array((int) $role['id'], $selected, true) ? 'checked' : '' ?>>
                        <?= $e($role['name']) ?> <small><?= $e($role['slug']) ?></small>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="form-actions">
            <button class="button button--primary" type="submit"><?= $e($t('Save MFA policy')) ?></button>
        </div>
    </form>
</section>
