<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$bytes = static function (?int $value) use ($t): string {
    if ($value === null) {
        return $t('Unavailable');
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
$searchStates = ['idle' => 'Not started', 'queued' => 'Queued', 'running' => 'In progress', 'completed' => 'Completed', 'failed' => 'Failed'];
$readinessStates = ['setup_required' => 'Setup required', 'maintenance' => 'Maintenance mode', 'ok' => 'Ready', 'degraded' => 'Needs attention', 'configuration_error' => 'Configuration error'];
$upgradeStates = ['idle' => 'Not running', 'running' => 'In progress', 'completed' => 'Completed', 'succeeded' => 'Completed', 'restored' => 'Restored', 'rolled_back' => 'Rolled back', 'failed' => 'Failed'];
$upgradeStageLabels = ['preflight' => 'Preflight check', 'fetch' => 'Fetching release', 'verify-unchanged' => 'Verifying current release', 'pause' => 'Pausing writes', 'backup' => 'Creating backup', 'prepare' => 'Preparing release', 'previous' => 'Preserving previous release', 'migrate' => 'Applying migrations', 'switch' => 'Switching release', 'readiness' => 'Verifying readiness', 'rollback' => 'Rolling back', 'restore' => 'Restoring backup'];
?>
<section class="content-container admin-page admin-page--system">
    <div class="page-heading">
        <div>
            <div class="eyebrow"><?= $e($t('Administration')) ?></div>
            <h1><?= $e($t('System information')) ?></h1>
            <p class="muted"><?= $e($t('Runtime, database, storage, scheduler and migration diagnostics. No secrets are exposed.')) ?></p>
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
            <strong><?= $e($system['database']['version'] ?? $t('Unknown')) ?></strong>
        </div>
        <div class="card stat-card">
            <span><?= $e($t('Pending migrations')) ?></span>
            <strong><?= (int) $system['migrations']['pending'] ?></strong>
        </div>
    </div>

    <div class="card panel" data-search-index>
        <div class="panel__header"><h2><?= $e($t('Search index')) ?></h2></div>
        <p role="status"><?= $e($t('State')) ?>: <span class="status-pill"><?= $e($t($searchStates[$searchIndex['state']] ?? ucfirst((string) $searchIndex['state']))) ?></span> · <?= $e(strtr($t('Pages processed: :scanned of :total'), [':scanned' => (string) (int) $searchIndex['scanned'], ':total' => (string) (int) $searchIndex['total']])) ?></p>
        <progress aria-label="<?= $e($t('Indexing progress')) ?>" value="<?= (int) $searchIndex['scanned'] ?>" max="<?= max(1, (int) $searchIndex['total']) ?>"></progress>
        <?php if (!empty($searchIndex['error'])): ?><p class="alert alert--error" role="alert"><?= $e($t($searchIndex['error'])) ?></p><?php endif; ?>
        <p class="muted"><?= $e($t('Page edits update the FULLTEXT index automatically. Rebuilding repairs indexed text in batches of 200 pages using cron:run or search:work. Revision snapshots are not changed.')) ?></p>
        <form method="post" action="/admin/system/search-index" class="form-actions">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <button class="button button--secondary" type="submit" <?= in_array($searchIndex['state'], ['queued','running'], true) ? 'disabled' : '' ?>><?= $e($t($searchIndex['state'] === 'failed' ? 'Resume indexing' : 'Rebuild index')) ?></button>
            <a class="button button--ghost" href="/admin/system"><?= $e($t('Refresh progress')) ?></a>
        </form>
    </div>

    <div class="card panel">
        <div class="panel__header"><h2><?= $e($t('Readiness and updates')) ?></h2></div>
        <dl class="definition-list">
            <dt><?= $e($t('PHP process')) ?></dt><dd><?= $e($t('Running')) ?></dd>
            <dt><?= $e($t('Database')) ?></dt><dd><?= $e($t($system['readiness']['checks']['database'] ? 'Available' : 'Unavailable')) ?></dd>
            <dt><?= $e($t('Application readiness')) ?></dt><dd><span class="status-pill"><?= $e($t($readinessStates[$system['readiness']['status']] ?? ucfirst((string) $system['readiness']['status']))) ?></span></dd>
            <dt><?= $e($t('Update status')) ?></dt><dd><?= $e($t($upgradeStates[$system['upgrade']['status']] ?? ucfirst((string) $system['upgrade']['status']))) ?></dd>
            <dt><?= $e($t('Stage')) ?></dt><dd><?= $e($t($upgradeStageLabels[$system['upgrade']['stage'] ?? ''] ?? (string) ($system['upgrade']['stage'] ?? '—'))) ?></dd>
            <dt><?= $e($t('Target version')) ?></dt><dd><code><?= $e($system['upgrade']['sha'] ?? '—') ?></code></dd>
        </dl>
        <?php if (!empty($system['upgrade']['error'])): ?>
            <div class="alert alert--error" role="alert"><?= $e($t($system['upgrade']['error'])) ?></div>
            <p><?= $e($t('The server administrator can inspect the private log and resume the operation with:')) ?> <code>sudo bash install.sh --resume</code></p>
        <?php endif; ?>
    </div>

    <div class="card panel">
        <div class="panel__header"><h2><?= $e($t('Runtime')) ?></h2></div>
        <dl class="definition-list">
            <dt><?= $e($t('Application name')) ?></dt><dd><?= $e($system['application']['name']) ?></dd>
            <dt><?= $e($t('Application version')) ?></dt><dd><?= $e($system['application']['version']) ?></dd>
            <dt><?= $e($t('Installed')) ?></dt><dd><?= $e($t($system['application']['installed'] ? 'Yes' : 'No')) ?></dd>
            <dt>PHP SAPI</dt><dd><?= $e($system['runtime']['sapi']) ?></dd>
            <dt><?= $e($t('Memory limit')) ?></dt><dd><?= $e($system['runtime']['memory_limit']) ?></dd>
            <dt><?= $e($t('Upload max filesize')) ?></dt><dd><?= $e($system['runtime']['upload_max_filesize']) ?></dd>
            <dt><?= $e($t('POST max size')) ?></dt><dd><?= $e($system['runtime']['post_max_size']) ?></dd>
        </dl>
    </div>

    <div class="card panel">
        <div class="panel__header"><h2><?= $e($t('Database and storage')) ?></h2></div>
        <dl class="definition-list">
            <dt><?= $e($t('Database')) ?></dt><dd><?= $e($system['database']['name'] ?? $t('Unknown')) ?></dd>
            <dt><?= $e($t('Database size')) ?></dt><dd><?= $e($bytes($system['database']['size_bytes'])) ?></dd>
            <dt><?= $e($t('Attachments size')) ?></dt><dd><?= $e($bytes($system['storage']['attachments_bytes'])) ?></dd>
            <dt><?= $e($t('Backups size')) ?></dt><dd><?= $e($bytes($system['storage']['backups_bytes'])) ?></dd>
            <dt><?= $e($t('Disk total')) ?></dt><dd><?= $e($bytes($system['storage']['disk_total_bytes'])) ?></dd>
            <dt><?= $e($t('Disk free')) ?></dt><dd><?= $e($bytes($system['storage']['disk_free_bytes'])) ?></dd>
        </dl>
    </div>

    <div class="card panel">
        <div class="panel__header">
            <h2><?= $e($t('Scheduler')) ?></h2>
            <span class="status-pill"><?= $e($t($system['scheduler']['stale'] ? 'Stale' : 'Healthy')) ?></span>
        </div>
        <p>
            <?= $e($t('Last successful')) ?> <code>cron:run</code>:
            <strong><?= $e($system['scheduler']['last_run'] ?? $t('Never')) ?></strong>
        </p>
        <?php if ($system['scheduler']['stale']): ?>
            <div class="alert alert--error">
                <?= $e($t('Scheduler has not reported a successful run in the last 10 minutes.')) ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card panel">
        <div class="panel__header"><h2><?= $e($t('Required PHP extensions')) ?></h2></div>
        <div class="item-list">
            <?php foreach ($system['runtime']['extensions'] as $extension => $loaded): ?>
                <div class="item-row">
                    <strong><?= $e($extension) ?></strong>
                    <span class="status-pill"><?= $e($t($loaded ? 'Loaded' : 'Missing')) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card panel">
        <div class="panel__header"><h2><?= $e($t('Writable directories')) ?></h2></div>
        <div class="item-list">
            <?php foreach ($system['storage']['writable'] as $path => $writable): ?>
                <div class="item-row">
                    <code><?= $e($path) ?></code>
                    <span class="status-pill"><?= $e($t($writable ? 'Writable' : 'Not writable')) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card panel">
        <div class="panel__header">
            <h2><?= $e($t('Database migrations')) ?></h2>
            <span class="count"><?= (int) $system['migrations']['total'] ?></span>
        </div>
        <div class="item-list">
            <?php foreach ($system['migrations']['items'] as $migration): ?>
                <div class="item-row">
                    <code><?= $e($migration['version']) ?></code>
                    <span class="status-pill"><?= $e($t($migration['applied'] ? 'Applied' : 'Pending')) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
