<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Account</div>
            <h1>Notifications</h1>
            <p class="muted"><?= (int) $unreadCount ?> unread notification<?= (int) $unreadCount === 1 ? '' : 's' ?>.</p>
        </div>
        <?php if ((int) $unreadCount > 0): ?>
            <form method="post" action="/notifications/read-all">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <button class="button button--secondary" type="submit">Mark all as read</button>
            </form>
        <?php endif; ?>
    </div>

    <section class="card panel">
        <?php if ($notifications === []): ?>
            <div class="empty-state">No notifications.</div>
        <?php else: ?>
            <ul class="notification-list">
                <?php foreach ($notifications as $notification): ?>
                    <li class="notification-item <?= $notification['read_at'] === null ? 'is-unread' : '' ?>">
                        <div>
                            <strong><?= $e($notification['title']) ?></strong>
                            <?php if ($notification['body']): ?>
                                <p><?= $e($notification['body']) ?></p>
                            <?php endif; ?>
                            <small><?= $e($notification['created_at']) ?> · <?= $e($notification['event_type']) ?></small>
                        </div>
                        <?php if ($notification['read_at'] === null): ?>
                            <form method="post" action="/notifications/<?= (int) $notification['id'] ?>/read">
                                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="target_url" value="<?= $e($notification['target_url'] ?: '/notifications') ?>">
                                <button class="button button--ghost" type="submit">Open</button>
                            </form>
                        <?php elseif ($notification['target_url']): ?>
                            <a class="button button--ghost" href="<?= $e($notification['target_url']) ?>">Open</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</section>
