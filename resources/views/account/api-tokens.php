<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Account</div>
            <h1>API tokens</h1>
            <p class="muted">Create scoped bearer tokens for automation and API access.</p>
        </div>
    </div>

    <?php if (is_string($newToken) && $newToken !== ''): ?>
        <div class="card panel token-once">
            <h2>New token</h2>
            <p>Copy this token now. OpenWiki stores only its hash and cannot display it again.</p>
            <code><?= $e($newToken) ?></code>
        </div>
    <?php endif; ?>

    <div class="dashboard-grid">
        <section class="card panel">
            <div class="panel__header"><h2>Create token</h2></div>
            <form method="post" action="/account/api-tokens" class="form-stack">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <label>Name
                    <input name="name" maxlength="191" required placeholder="Automation">
                </label>
                <label>Expires at
                    <input name="expires_at" type="datetime-local">
                    <span class="field-help">Leave empty for no expiry.</span>
                </label>
                <fieldset>
                    <legend>Scopes</legend>
                    <div class="scope-grid">
                        <?php foreach ($availableScopes as $scope): ?>
                            <label class="check-row">
                                <input type="checkbox" name="scopes[]" value="<?= $e($scope) ?>">
                                <span><?= $e($scope) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <button class="button button--primary" type="submit">Create token</button>
            </form>
        </section>

        <section class="card panel panel--wide">
            <div class="panel__header"><h2>Tokens</h2><span class="count"><?= count($tokens) ?></span></div>
            <?php if ($tokens === []): ?>
                <div class="empty-state">No API tokens.</div>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Name</th><th>Scopes</th><th>Created</th><th>Expires</th><th>Last used</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($tokens as $token): ?>
                            <tr>
                                <td><?= $e($token['name']) ?></td>
                                <td><?= $e(implode(', ', $token['scopes'])) ?></td>
                                <td><?= $e($token['created_at']) ?></td>
                                <td><?= $e($token['expires_at'] ?: 'Never') ?></td>
                                <td><?= $e($token['last_used_at'] ?: 'Never') ?></td>
                                <td><?= $token['revoked_at'] === null ? 'Active' : 'Revoked' ?></td>
                                <td>
                                    <?php if ($token['revoked_at'] === null): ?>
                                        <form method="post" action="/account/api-tokens/<?= (int) $token['id'] ?>/revoke">
                                            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                            <button class="button button--ghost" type="submit">Revoke</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</section>
