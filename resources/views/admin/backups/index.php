<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container admin-page admin-page--backups">
    <div class="page-heading">
        <div>
            <div class="eyebrow"><?= $e($t('Administration')) ?></div>
            <h1><?= $e($t('Backups')) ?></h1>
            <p class="muted"><?= $e($t('Database, attachments and non-secret configuration are stored in a downloadable TAR archive.')) ?></p>
        </div>
        <form method="post" action="/admin/backups">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <button class="button button--primary" type="submit"><?= $e($t('Create backup')) ?></button>
        </form>
    </div>

    <div class="card panel">
        <div class="panel__header">
            <h2><?= $e($t('Available backups')) ?></h2>
            <span class="count"><?= count($backups) ?></span>
        </div>

        <?php if ($backups === []): ?>
            <div class="empty-state"><?= $e($t('No backups have been created yet.')) ?></div>
        <?php else: ?>
            <div class="item-list">
                <?php foreach ($backups as $backup): ?>
                    <div class="item-row">
                        <div>
                            <strong><?= $e($backup['name']) ?></strong>
                            <div class="muted">
                                <?= $e($backup['created_at']) ?> ·
                                <?= number_format((int) $backup['size_bytes'] / 1024 / 1024, 2) ?> MiB
                            </div>
                        </div>
                        <a class="button button--secondary" href="/admin/backups/<?= rawurlencode($backup['name']) ?>/download"><?= $e($t('Download')) ?></a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card panel">
        <div class="panel__header"><h2><?= $e($t('Included')) ?></h2></div>
        <ul>
            <li><?= $e($t('MySQL schema and data.')) ?></li>
            <li><?= $e($t('Files from protected attachment storage.')) ?></li>
            <li><?= $e($t('Application configuration excluding APP_KEY, DB_USERNAME and DB_PASSWORD.')) ?></li>
            <li><?= $e($t('Backup manifest with format version and creation timestamp.')) ?></li>
        </ul>
    </div>
</section>
