<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow"><?= $e($t('Account security')) ?></div>
            <h1><?= $e($t('Active sessions')) ?></h1>
            <p class="muted"><?= $e($t('Browser sessions are stored server-side and can be revoked immediately.')) ?></p>
        </div>
        <form method="post" action="/account/sessions/logout-all" data-confirm="<?= $e($t('Sign out every active browser session, including this one?')) ?>">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <button class="button button--secondary" type="submit"><?= $e($t('Sign out all sessions')) ?></button>
        </form>
    </div>

    <div class="card panel">
        <div class="panel__header">
            <h2><?= $e($t('Sessions')) ?></h2>
            <span class="count"><?= count($sessions) ?></span>
        </div>

        <?php if ($sessions === []): ?>
            <div class="empty-state"><?= $e($t('No active browser sessions.')) ?></div>
        <?php else: ?>
            <div class="item-list">
                <?php foreach ($sessions as $session): ?>
                    <div class="item-row">
                        <div>
                            <strong>
                                <?= $e($t('Session')) ?> <?= $e($session['label']) ?>
                                <?php if ($session['current']): ?><span class="status-pill"><?= $e($t('current')) ?></span><?php endif; ?>
                            </strong>
                            <div><?= $e($session['ip_address']) ?></div>
                            <small><?= $e($session['user_agent'] ?: 'Unknown user agent') ?></small>
                            <small><?= $e($t('Last activity')) ?> <?= $e($session['last_activity_at']) ?> · expires <?= $e($session['expires_at']) ?></small>
                        </div>
                        <form method="post" action="/account/sessions/<?= $e($session['fingerprint']) ?>/revoke" data-confirm="<?= $e($t('Revoke this session?')) ?>">
                            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                            <button class="button button--ghost" type="submit"><?= $session['current'] ? 'Revoke current' : 'Revoke' ?></button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
