<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow"><?= $e($t('Administration')) ?></div>
            <h1><?= $e($t('Users')) ?></h1>
        </div>
        <a class="button button--primary" href="/admin/users/create"><?= $e($t('Create user')) ?></a>
    </div>

    <?php if (is_array($newPassword ?? null)): ?>
        <div class="card panel token-once">
            <h2><?= $e($t('Temporary password')) ?></h2>
            <p><?= $e($t('Copy it now. It will not be shown again. The user must change it after sign-in.')) ?></p>
            <code><?= $e($newPassword['password']) ?></code>
        </div>
    <?php endif; ?>

    <form class="search-page-form" method="get" action="/admin/users">
        <input type="search" name="q" value="<?= $e($query) ?>" placeholder="<?= $e($t('Search username, email or name')) ?>">
        <button class="button button--secondary" type="submit"><?= $e($t('Search')) ?></button>
    </form>

    <section class="card panel">
        <div class="table-scroll">
            <table>
                <thead><tr><th><?= $e($t('User')) ?></th><th><?= $e($t('Status')) ?></th><th><?= $e($t('Source')) ?></th><th><?= $e($t('Roles')) ?></th><th><?= $e($t('Groups')) ?></th><th><?= $e($t('Last login')) ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <strong><?= $e($user['username']) ?></strong><br>
                            <small><?= $e($user['email']) ?></small>
                        </td>
                        <td><?= $e($user['status']) ?><?= (int) $user['force_password_change'] === 1 ? ' · password change required' : '' ?></td>
                        <td><?= $e($user['auth_source']) ?></td>
                        <td><?= $e($user['role_names'] ?: '—') ?></td>
                        <td><?= $e($user['group_names'] ?: '—') ?></td>
                        <td><?= $e($user['last_login_at'] ?: 'Never') ?></td>
                        <td><a class="button button--ghost" href="/admin/users/<?= (int) $user['id'] ?>/edit"><?= $e($t('Edit')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
