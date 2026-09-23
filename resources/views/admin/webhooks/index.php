<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Administration</div>
            <h1>Webhooks</h1>
            <p class="muted">Signed outbound events with retry and delivery history.</p>
        </div>
    </div>

    <?php if (is_array($newSecret)): ?>
        <div class="alert alert--success">
            Signing secret for webhook #<?= (int) $newSecret['id'] ?>:
            <code><?= $e($newSecret['secret']) ?></code>
            <strong>It will not be shown again.</strong>
        </div>
    <?php endif; ?>

    <form class="card form-card form-stack" method="post" action="/admin/webhooks">
        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
        <h2>Create webhook</h2>
        <label>Name
            <input name="name" maxlength="191" required>
        </label>
        <label>Target URL
            <input name="target_url" type="url" placeholder="https://example.com/openwiki-hook" required>
        </label>
        <label>Signing secret
            <input name="secret" type="password" minlength="16" maxlength="512" autocomplete="new-password">
            <span class="field-help">Leave empty to generate a strong secret automatically.</span>
        </label>
        <fieldset>
            <legend>Events</legend>
            <div class="checkbox-grid">
                <?php foreach ($events as $event): ?>
                    <label><input type="checkbox" name="events[]" value="<?= $e($event) ?>"> <?= $e($event) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="form-actions">
            <button class="button button--primary" type="submit">Create webhook</button>
        </div>
    </form>

    <section class="card panel">
        <div class="panel__header">
            <h2>Configured webhooks</h2>
            <span class="count"><?= count($webhooks) ?></span>
        </div>
        <?php if ($webhooks === []): ?>
            <div class="empty-state">No webhooks configured.</div>
        <?php else: ?>
            <div class="item-list">
                <?php foreach ($webhooks as $webhook): ?>
                    <?php $configuredEvents = json_decode((string) $webhook['events_json'], true) ?: []; ?>
                    <div class="item-row">
                        <div>
                            <strong><?= $e($webhook['name']) ?></strong>
                            <div><?= $e($webhook['target_url']) ?></div>
                            <small><?= $e(implode(', ', $configuredEvents)) ?> · <?= $e($webhook['status']) ?></small>
                        </div>
                        <div class="form-actions">
                            <form method="post" action="/admin/webhooks/<?= (int) $webhook['id'] ?>/status">
                                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="status" value="<?= $webhook['status'] === 'active' ? 'disabled' : 'active' ?>">
                                <button class="button button--ghost" type="submit"><?= $webhook['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                            </form>
                            <form method="post" action="/admin/webhooks/<?= (int) $webhook['id'] ?>/delete" onsubmit="return confirm('Delete this webhook and its delivery history?')">
                                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                <button class="button button--ghost" type="submit">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card panel">
        <div class="panel__header">
            <h2>Delivery history</h2>
            <span class="count"><?= count($deliveries) ?></span>
        </div>
        <?php if ($deliveries === []): ?>
            <div class="empty-state">No deliveries yet.</div>
        <?php else: ?>
            <div class="item-list">
                <?php foreach ($deliveries as $delivery): ?>
                    <div class="item-row">
                        <div>
                            <strong><?= $e($delivery['webhook_name']) ?> · <?= $e($delivery['event_type']) ?></strong>
                            <div>
                                attempt <?= (int) $delivery['attempt_number'] ?>
                                · HTTP <?= $delivery['response_status'] === null ? '—' : (int) $delivery['response_status'] ?>
                            </div>
                            <small>
                                <?php if ($delivery['delivered_at'] !== null): ?>
                                    delivered <?= $e($delivery['delivered_at']) ?>
                                <?php elseif ($delivery['failed_at'] !== null): ?>
                                    failed <?= $e($delivery['failed_at']) ?> · <?= $e($delivery['last_error']) ?>
                                <?php else: ?>
                                    retry <?= $e($delivery['next_attempt_at'] ?? 'pending') ?>
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</section>
