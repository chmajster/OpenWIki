<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$bytes = static function (?int $value): string {
    if ($value === null) {
        return 'Unavailable';
    }
    if ($value < 1024) {
        return $value . ' B';
    }
    if ($value < 1024 * 1024) {
        return number_format($value / 1024, 1) . ' KiB';
    }
    if ($value < 1024 * 1024 * 1024) {
        return number_format($value / 1024 / 1024, 1) . ' MiB';
    }
    return number_format($value / 1024 / 1024 / 1024, 2) . ' GiB';
};
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Administration</div>
            <h1>System information</h1>
            <p class="muted">Runtime, database, storage, scheduler and migration diagnostics. No secrets are exposed.</p>
        </div>
    </div>

    <div class="stat-grid">
        <div class="card stat-card">
            <span>OpenWiki</span>
            <strong><?= $e($system['application']['version']) ?></strong>
        </div>
        <div class="card stat-card">
            <span>PHP</span>
            <strong><?= $e($system['runtime']['php_version']) ?></strong>
        </div>
        <div class="card stat-card">
            <span>MySQL / MariaDB</span>
            <strong><?= $e($system['database']['version'] ?? 'Unknown') ?></strong>
        </div>
        <div class="card stat-card">
            <span>Pending migrations</span>
            <strong><?= (int) $system['migrations']['pending'] ?></strong>
        </div>
    </div>

    <div class="card panel">
        <div class="panel__header"><h2>Runtime</h2></div>
        <dl class="definition-list">
            <dt>Application name</dt><dd><?= $e($system['application']['name']) ?></dd>
            <dt>Application version</dt><dd><?= $e($system['application']['version']) ?></dd>
            <dt>Installed</dt><dd><?= $system['application']['installed'] ? 'yes' : 'no' ?></dd>
            <dt>PHP SAPI</dt><dd><?= $e($system['runtime']['sapi']) ?></dd>
            <dt>Memory limit</dt><dd><?= $e($system['runtime']['memory_limit']) ?></dd>
            <dt>Upload max filesize</dt><dd><?= $e($system['runtime']['upload_max_filesize']) ?></dd>
            <dt>POST max size</dt><dd><?= $e($system['runtime']['post_max_size']) ?></dd>
        </dl>
    </div>

    <div class="card panel">
        <div class="panel__header"><h2>Database and storage</h2></div>
        <dl class="definition-list">
            <dt>Database</dt><dd><?= $e($system['database']['name'] ?? 'Unknown') ?></dd>
            <dt>Database size</dt><dd><?= $e($bytes($system['database']['size_bytes'])) ?></dd>
            <dt>Attachments size</dt><dd><?= $e($bytes($system['storage']['attachments_bytes'])) ?></dd>
            <dt>Backups size</dt><dd><?= $e($bytes($system['storage']['backups_bytes'])) ?></dd>
            <dt>Disk total</dt><dd><?= $e($bytes($system['storage']['disk_total_bytes'])) ?></dd>
            <dt>Disk free</dt><dd><?= $e($bytes($system['storage']['disk_free_bytes'])) ?></dd>
        </dl>
    </div>

    <div class="card panel">
        <div class="panel__header">
            <h2>Scheduler</h2>
            <span class="status-pill"><?= $system['scheduler']['stale'] ? 'stale' : 'healthy' ?></span>
        </div>
        <p>
            Last successful <code>cron:run</code>:
            <strong><?= $e($system['scheduler']['last_run'] ?? 'Never') ?></strong>
        </p>
        <?php if ($system['scheduler']['stale']): ?>
            <div class="alert alert--error">
                Scheduler has not reported a successful run in the last 10 minutes.
            </div>
        <?php endif; ?>
    </div>

    <div class="card panel">
        <div class="panel__header"><h2>Required PHP extensions</h2></div>
        <div class="item-list">
            <?php foreach ($system['runtime']['extensions'] as $extension => $loaded): ?>
                <div class="item-row">
                    <strong><?= $e($extension) ?></strong>
                    <span class="status-pill"><?= $loaded ? 'loaded' : 'missing' ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card panel">
        <div class="panel__header"><h2>Writable directories</h2></div>
        <div class="item-list">
            <?php foreach ($system['storage']['writable'] as $path => $writable): ?>
                <div class="item-row">
                    <code><?= $e($path) ?></code>
                    <span class="status-pill"><?= $writable ? 'writable' : 'not writable' ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card panel">
        <div class="panel__header">
            <h2>Database migrations</h2>
            <span class="count"><?= (int) $system['migrations']['total'] ?></span>
        </div>
        <div class="item-list">
            <?php foreach ($system['migrations']['items'] as $migration): ?>
                <div class="item-row">
                    <code><?= $e($migration['version']) ?></code>
                    <span class="status-pill"><?= $migration['applied'] ? 'applied' : 'pending' ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
