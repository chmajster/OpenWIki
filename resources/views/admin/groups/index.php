<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container admin-page">
    <div class="page-heading">
        <div>
            <div class="eyebrow"><?= $e($t('Administration')) ?></div>
            <h1><?= $e($t('Groups')) ?></h1>
        </div>
        <a class="button button--primary" href="/admin/groups/create"><?= $e($t('Create group')) ?></a>
    </div>
    <section class="card panel">
        <div class="table-scroll">
            <table>
                <thead><tr><th><?= $e($t('Group')) ?></th><th><?= $e($t('Source')) ?></th><th><?= $e($t('Members')) ?></th><th><?= $e($t('Description')) ?></th><th><?= $e($t('Actions')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($groups as $group): ?>
                    <tr>
                        <td><strong><?= $e($group['name']) ?></strong><br><small><?= $e($group['slug']) ?></small></td>
                        <td><?= $e($t($group['source'] === 'ldap' ? 'LDAP' : 'Local')) ?></td>
                        <td><?= (int) $group['member_count'] ?></td>
                        <td><?= $e($group['description'] ?: '—') ?></td>
                        <td><a class="button button--ghost" href="/admin/groups/<?= (int) $group['id'] ?>/edit"><?= $e($t('Edit')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($groups === []): ?><tr><td colspan="5"><div class="empty-state"><?= $e($t('No groups have been created yet.')) ?></div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
