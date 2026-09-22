<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="auth-layout">
    <div class="card auth-card">
        <div class="eyebrow">OpenWiki</div>
        <h1>Sign in</h1>
        <p class="muted">Use your local OpenWiki account.</p>

        <?php if (!empty($loginError)): ?>
            <div class="alert alert--error" role="alert"><?= $e($loginError) ?></div>
        <?php endif; ?>

        <form method="post" action="/login" class="form-stack">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <label>Username or email
                <input name="identifier" required autofocus autocomplete="username" value="<?= $e($identifier ?? '') ?>">
            </label>
            <label>Password
                <input name="password" type="password" required autocomplete="current-password">
            </label>
            <button class="button button--primary button--large" type="submit">Sign in</button>
        </form>
    </div>
</section>
