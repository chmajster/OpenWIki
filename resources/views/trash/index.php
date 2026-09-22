<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Wiki</div>
            <h1>Trash</h1>
            <p class="muted">Deleted pages remain recoverable until permanently removed.</p>
        </div>
    </div>

    <section class="card panel">
        <div class="panel__header"><h2>Deleted pages</h2><span class="count"><?= count($pages) ?></span></div>
        <?php if ($pages === []): ?>
            <div class="empty-state">Trash is empty.</div>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Page</th><th>Space</th><th>Deleted</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($pages as $page): ?>
                        <tr>
                            <td><strong><?= $e($page['title']) ?></strong><br><small><?= $e($page['slug']) ?></small></td>
                            <td><?= $e($page['space_name']) ?> <span class="space-key"><?= $e($page['space_key']) ?></span></td>
                            <td><?= $e($page['deleted_at']) ?></td>
                            <td>
                                <div class="page-actions">
                                    <form method="post" action="/trash/<?= (int) $page['id'] ?>/restore">
                                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                        <button class="button button--secondary" type="submit">Restore</button>
                                    </form>
                                    <form method="post" action="/trash/<?= (int) $page['id'] ?>/delete" onsubmit="return confirm('Permanently delete this page and its attachments? This cannot be undone.')">
                                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                        <button class="button button--ghost" type="submit">Delete permanently</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
