<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Administration</div>
            <h1>Groups</h1>
        </div>
        <a class="button button--primary" href="/admin/groups/create">Create group</a>
    </div>
    <section class="card panel">
        <div class="table-scroll">
            <table>
                <thead><tr><th>Group</th><th>Source</th><th>Members</th><th>Description</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($groups as $group): ?>
                    <tr>
                        <td><strong><?= $e($group['name']) ?></strong><br><small><?= $e($group['slug']) ?></small></td>
                        <td><?= $e($group['source']) ?></td>
                        <td><?= (int) $group['member_count'] ?></td>
                        <td><?= $e($group['description'] ?: '—') ?></td>
                        <td><a class="button button--ghost" href="/admin/groups/<?= (int) $group['id'] ?>/edit">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
