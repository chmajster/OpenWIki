<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$overview = $missionControl['counts'] ?? [];
$spaceBase = '/spaces/' . rawurlencode((string) $space['space_key']);
$checks = [
    ['Overdue reviews', 'overdue_reviews', 'clock', 'Review dates have passed for published pages.'],
    ['Overdue tasks', 'overdue_tasks', 'check', 'Open page tasks are past their due date.'],
    ['Broken links', 'broken_links', 'link', 'Page references in this space no longer resolve.'],
    ['Pages without an owner', 'ownerless_pages', 'users', 'Assign a person responsible for published pages.'],
    ['Pages not updated recently', 'stale_pages', 'clock', 'Published pages have not changed in over six months.'],
];
require __DIR__ . '/../partials/ui-icons.php';
?>
<section class="content-container space-mission-control mission-control">
    <?php require __DIR__ . '/../partials/space-header.php'; ?>

    <header class="mission-control__hero mission-control__hero--space">
        <div><div class="eyebrow"><?= $e($t('Space overview')) ?></div><h2><?= $e($t('Mission Control')) ?></h2><p class="muted"><?= $e($t('Keep this space healthy, current, and easy to navigate.')) ?></p></div>
        <a class="button button--secondary" href="<?= $e($spaceBase) ?>/settings"><?= $uiIcon('settings') ?><?= $e($t('Space settings')) ?></a>
    </header>

    <section class="mission-control__metrics" aria-label="<?= $e($t('Space overview')) ?>">
        <?php foreach ([
            ['Pages in this space', $overview['pages'] ?? 0, 'document', $spaceBase . '?tab=content'],
            ['Published pages', $overview['published_pages'] ?? 0, 'book', $spaceBase . '?tab=content'],
            ['Draft pages', $overview['draft_pages'] ?? 0, 'edit', $spaceBase . '?tab=content'],
            ['Updates in the last 30 days', $overview['recent_edits'] ?? 0, 'clock', $spaceBase . '?tab=content'],
        ] as [$label, $value, $icon, $href]): ?>
            <a class="mission-metric" href="<?= $e($href) ?>"><span class="mission-metric__icon"><?= $uiIcon($icon) ?></span><span class="mission-metric__label"><?= $e($t($label)) ?></span><strong><?= number_format((int) $value) ?></strong></a>
        <?php endforeach; ?>
    </section>

    <div class="mission-control__columns">
        <section class="mission-panel" aria-labelledby="space-mission-health-title">
            <header class="mission-panel__heading"><div><h3 id="space-mission-health-title"><?= $e($t('Content health')) ?></h3><p><?= $e($t('Items based on pages you can access in this space.')) ?></p></div></header>
            <div class="mission-health-list">
                <?php foreach ($checks as [$label, $key, $icon, $description]): $value = (int) ($overview[$key] ?? 0); ?>
                    <div class="mission-health-item mission-health-item--static"><span class="mission-health-item__icon"><?= $uiIcon($icon) ?></span><span class="mission-health-item__copy"><strong><?= $e($t($label)) ?></strong><small><?= $e($t($description)) ?></small></span><strong class="<?= $value > 0 ? 'is-attention' : '' ?>"><?= number_format($value) ?></strong></div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="mission-panel" aria-labelledby="space-mission-access-title">
            <header class="mission-panel__heading"><div><h3 id="space-mission-access-title"><?= $e($t('Space access')) ?></h3><p><?= $e($t('Current visibility and ownership for this space.')) ?></p></div></header>
            <div class="mission-space-access">
                <div><span><?= $e($t('Visibility')) ?></span><strong><?= $e($t(ucfirst((string) $space['visibility']))) ?></strong></div>
                <div><span><?= $e($t('Status')) ?></span><strong><?= $e($t($space['status'] === 'archived' ? 'Archived' : 'Active')) ?></strong></div>
                <div><span><?= $e($t('Space owner')) ?></span><strong><?= $e($space['owner_username'] ?? $t('Assigned')) ?></strong></div>
            </div>
            <a class="mission-panel__link" href="<?= $e($spaceBase) ?>/settings#access"><?= $e($t('Manage space access')) ?><?= $uiIcon('arrow') ?></a>
        </section>
    </div>

    <section class="mission-panel" aria-labelledby="space-mission-activity-title">
        <header class="mission-panel__heading"><div><h3 id="space-mission-activity-title"><?= $e($t('Recent page activity')) ?></h3><p><?= $e($t('Latest saved revisions on pages you can access.')) ?></p></div><a class="mission-panel__link" href="<?= $e($spaceBase) ?>?tab=content"><?= $e($t('Browse space content')) ?><?= $uiIcon('arrow') ?></a></header>
        <?php if (($missionControl['recent_pages'] ?? []) === []): ?>
            <p class="mission-empty"><?= $e($t('No page activity yet.')) ?></p>
        <?php else: ?>
            <div class="mission-activity-list">
                <?php foreach ($missionControl['recent_pages'] as $revision): ?>
                    <a class="mission-activity-item" href="<?= $e($spaceBase) ?>/pages/<?= rawurlencode((string) $revision['slug']) ?>">
                        <span class="mission-activity-item__icon"><?= $uiIcon('document') ?></span>
                        <span class="mission-activity-item__main"><strong><?= $e($revision['title']) ?></strong><small><?= $e($t(ucfirst((string) $revision['status']))) ?> · <?= $e($t('Version')) ?> <?= (int) $revision['revision_number'] ?></small></span>
                        <span class="mission-activity-item__byline"><?= $e($revision['username'] ?? $t('Unknown user')) ?> · <?= $e($revision['created_at']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</section>
