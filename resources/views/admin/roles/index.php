<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow"><?= $e($t('Administration')) ?></div>
            <h1><?= $e($t('Roles and RBAC')) ?></h1>
        </div>
        <a class="button button--primary" href="/admin/roles/create"><?= $e($t('Create role')) ?></a>
    </div>
    <section class="card panel">
        <div class="table-scroll">
            <table>
                <thead><tr><th><?= $e($t('Role')) ?></th><th><?= $e($t('Type')) ?></th><th><?= $e($t('Users')) ?></th><th><?= $e($t('Permissions')) ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($roles as $role): ?>
                    <tr>
                        <td><strong><?= $e($role['name']) ?></strong><br><small><?= $e($role['slug']) ?></small></td>
                        <td><?= (int) $role['is_system'] === 1 ? 'System' : 'Custom' ?></td>
                        <td><?= (int) $role['user_count'] ?></td>
                        <td><?= (int) $role['permission_count'] ?></td>
                        <td><a class="button button--ghost" href="/admin/roles/<?= (int) $role['id'] ?>/edit"><?= $e($t('Edit')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
