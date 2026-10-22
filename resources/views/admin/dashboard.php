<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
require __DIR__ . '/../partials/ui-icons.php';
$overview = $missionControl['counts'] ?? [];
$canViewContent = $app->auth()->can('page.view') || $app->auth()->can('*');
$alertCopy = [
    'system' => ['System checks need attention', 'Review pending migrations or the background scheduler.', 'Review system'],
    'broken_links' => ['Broken links need repair', 'References in the knowledge base no longer resolve.', 'Review broken links'],
    'overdue_reviews' => ['Content reviews are overdue', 'Published pages have passed their scheduled review date.', 'Review pages'],
    'overdue_tasks' => ['Tasks are past their due date', 'Open page tasks have passed their deadline.', 'Review tasks'],
    'stale_pages' => ['Pages may be out of date', 'Published pages have not changed in over six months.', 'Review pages'],
    'ownerless_pages' => ['Pages need an owner', 'Published pages do not have a content owner assigned.', 'Assign owners'],
];
$alerts = $missionControl['alerts'] ?? [];
$healthItems = [
    ['Broken links', 'broken_links', '/admin/broken-links', 'link'],
    ['Overdue reviews', 'overdue_reviews', '/?view=work&attention=1&type=freshness', 'clock'],
    ['Overdue tasks', 'overdue_tasks', '/?view=work&attention=1&type=task&deadline=overdue', 'check'],
    ['Pages needing an owner', 'ownerless_pages', '/?view=work&attention=1&type=ownership', 'users'],
    ['Pages not reviewed recently', 'stale_pages', '/?view=work&attention=1&type=freshness', 'clock'],
    ['Draft pages', 'draft_pages', '/search?type=page&visibility=working', 'document'],
];
$sections = [
    ['Identity & access', 'Accounts, roles, and memberships.', [
        ['Users', '/admin/users', 'users', $counts['users'], 'user.manage', 'Manage accounts, status, and sign-in requirements.'],
        ['Groups', '/admin/groups', 'spaces', $counts['groups'], 'group.manage', 'Organize users into reusable groups.'],
        ['Roles', '/admin/roles', 'check', $counts['roles'], 'role.manage', 'Control what people can do with role permissions.'],
    ]],
    ['Knowledge and content', 'Browse spaces, find pages, and keep the Wiki connected.', [
        ['Spaces', '/?view=spaces', 'spaces', $counts['spaces'], 'settings.manage', 'Browse all spaces available to your account.'],
        ['Pages', '/search?type=page', 'document', $counts['pages'], 'page.view', 'Search pages across the knowledge base.'],
        ['Broken links', '/admin/broken-links', 'link', $counts['broken_links'], 'settings.manage', 'Find Wiki references that no longer resolve.'],
        ['Templates', '/admin/templates', 'document', $counts['templates'], 'template.manage', 'Maintain reusable page templates.'],
    ]],
    ['Platform and integrations', 'Review platform health, backups, and connected services.', [
        ['Backups', '/admin/backups', 'book', $counts['backups'], '*', 'Create and download protected system backups.'],
        ['Webhooks', '/admin/webhooks', 'link', $counts['webhooks'], 'webhook.manage', 'Configure signed events and inspect delivery history.'],
        ['MFA policy', '/admin/mfa', 'check', $counts['mfa_policy'], '*', 'Require authenticator codes for selected accounts.'],
        ['LDAP / AD', '/admin/ldap', 'panel', $counts['ldap'], '*', 'Connect directory sign-in and role mapping.'],
        ['System', '/admin/system', 'book', $counts['system'], 'settings.manage', 'Inspect runtime health, storage, and migrations.'],
    ]],
];
?>
<section class="content-container admin-page admin-dashboard mission-control">
    <header class="mission-control__hero">
        <div>
            <div class="eyebrow"><?= $e($t('Administration')) ?></div>
            <h1><?= $e($t('Mission Control')) ?></h1>
            <p class="muted"><?= $e($t('See site activity, content health, access, and system signals in one place.')) ?></p>
        </div>
        <a class="button button--secondary" href="/admin/system"><?= $uiIcon('settings') ?><?= $e($t('System status')) ?></a>
    </header>

    <section class="mission-control__metrics" aria-label="<?= $e($t('Site overview')) ?>">
        <?php foreach ([
            ['Active spaces', $counts['active_spaces'] ?? 0, 'spaces'],
            ['Published pages', $overview['published_pages'] ?? 0, 'document'],
            ['Active accounts', $overview['active_users'] ?? 0, 'users'],
            ['Updates in the last 30 days', $overview['recent_edits'] ?? 0, 'clock'],
        ] as [$label, $value, $icon]): ?>
            <div class="mission-metric">
                <span class="mission-metric__icon"><?= $uiIcon($icon) ?></span>
                <span class="mission-metric__label"><?= $e($t($label)) ?></span>
                <strong><?= number_format((int) $value) ?></strong>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="mission-panel mission-panel--alerts" aria-labelledby="mission-alerts-title">
        <header class="mission-panel__heading">
            <div><h2 id="mission-alerts-title"><?= $e($t('Needs attention')) ?></h2><p><?= $e($t('Signals that may need an administrator action.')) ?></p></div>
            <span class="mission-count<?= $alerts === [] ? ' mission-count--good' : '' ?>"><?= count($alerts) ?></span>
        </header>
        <?php if ($alerts === []): ?>
            <div class="mission-healthy"><?= $uiIcon('check') ?><div><strong><?= $e($t('Everything looks healthy')) ?></strong><span><?= $e($t('No outstanding content or system alerts were found.')) ?></span></div></div>
        <?php else: ?>
            <div class="mission-alert-list">
                <?php foreach ($alerts as $alert): [$title, $description, $action] = $alertCopy[$alert['key']] ?? ['Review this alert', 'Open the related area for details.', 'Open']; ?>
                    <article class="mission-alert mission-alert--<?= $e($alert['priority']) ?>">
                        <span class="mission-alert__count"><?= (int) $alert['count'] ?></span>
                        <div class="mission-alert__copy"><strong><?= $e($t($title)) ?></strong><p><?= $e($t($description)) ?></p></div>
                        <?php if (!empty($alert['href'])): ?><a class="button button--secondary" href="<?= $e($alert['href']) ?>"><?= $e($t($action)) ?><?= $uiIcon('arrow') ?></a><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="mission-control__columns">
        <section class="mission-panel" aria-labelledby="mission-health-title">
            <header class="mission-panel__heading"><div><h2 id="mission-health-title"><?= $e($t('Knowledge health')) ?></h2><p><?= $e($t('Review the quality and upkeep of published content.')) ?></p></div></header>
            <div class="mission-health-list">
                <?php foreach ($healthItems as [$label, $key, $href, $icon]): $value = (int) ($overview[$key] ?? 0); $canOpen = $key === 'broken_links' || $canViewContent; ?>
                    <?php if ($canOpen): ?><a class="mission-health-item" href="<?= $e($href) ?>"><?php else: ?><div class="mission-health-item mission-health-item--static"><?php endif; ?>
                    <span class="mission-health-item__icon"><?= $uiIcon($icon) ?></span><span><?= $e($t($label)) ?></span><strong class="<?= $value > 0 ? 'is-attention' : '' ?>"><?= number_format($value) ?></strong>
                    <?php if ($canOpen): ?></a><?php else: ?></div><?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="mission-panel" aria-labelledby="mission-access-title">
            <header class="mission-panel__heading"><div><h2 id="mission-access-title"><?= $e($t('Access snapshot')) ?></h2><p><?= $e($t('A quick view of who can use this site.')) ?></p></div></header>
            <div class="mission-access-list">
                <div><span><?= $e($t('Active accounts')) ?></span><strong><?= number_format((int) ($overview['active_users'] ?? 0)) ?></strong></div>
                <div><span><?= $e($t('Global administrators')) ?></span><strong><?= number_format((int) ($overview['global_admins'] ?? 0)) ?></strong></div>
                <div><span><?= $e($t('Guest accounts')) ?></span><strong><?= number_format((int) ($overview['guest_accounts'] ?? 0)) ?></strong></div>
                <div><span><?= $e($t('Public spaces')) ?></span><strong><?= number_format((int) ($overview['public_spaces'] ?? 0)) ?></strong></div>
            </div>
            <?php if ($app->auth()->can('user.manage')): ?><a class="mission-panel__link" href="/admin/users"><?= $e($t('Manage users and access')) ?><?= $uiIcon('arrow') ?></a><?php endif; ?>
        </section>
    </div>

    <section class="mission-panel" aria-labelledby="mission-activity-title">
        <header class="mission-panel__heading"><div><h2 id="mission-activity-title"><?= $e($t('Recent page activity')) ?></h2><p><?= $e($t('Latest saved page revisions across the site.')) ?></p></div><span class="mission-panel__period"><?= $e($t('Last 30 days')) ?></span></header>
        <?php if (($missionControl['recent_pages'] ?? []) === []): ?>
            <p class="mission-empty"><?= $e($t('Recent page details are available to global administrators.')) ?></p>
        <?php else: ?>
            <div class="mission-activity-list">
                <?php foreach ($missionControl['recent_pages'] as $revision): ?>
                    <a class="mission-activity-item" href="/spaces/<?= rawurlencode((string) $revision['space_key']) ?>/pages/<?= rawurlencode((string) $revision['slug']) ?>">
                        <span class="mission-activity-item__icon"><?= $uiIcon('document') ?></span>
                        <span class="mission-activity-item__main"><strong><?= $e($revision['title']) ?></strong><small><?= $e($revision['space_name']) ?> · <?= $e($revision['space_key']) ?></small></span>
                        <span class="mission-activity-item__byline"><?= $e($revision['username'] ?? $t('Unknown user')) ?> · <?= $e($revision['created_at']) ?></span>
                        <span class="status-pill"><?= $e($t(ucfirst((string) $revision['status']))) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php foreach ($sections as [$sectionTitle, $sectionDescription, $cards]): ?>
        <?php $visibleCards = array_values(array_filter($cards, static fn (array $card): bool => $app->auth()->can($card[4]))); ?>
        <?php if ($visibleCards === []) continue; ?>
        <section class="admin-dashboard-section" aria-labelledby="admin-section-<?= $e(strtolower(str_replace(' ', '-', $sectionTitle))) ?>">
            <header class="admin-dashboard-section__heading">
                <div><h2 id="admin-section-<?= $e(strtolower(str_replace(' ', '-', $sectionTitle))) ?>"><?= $e($t($sectionTitle)) ?></h2><p class="muted"><?= $e($t($sectionDescription)) ?></p></div>
                <span class="count"><?= count($visibleCards) ?></span>
            </header>
            <div class="admin-link-grid">
                <?php foreach ($visibleCards as [$label, $url, $icon, $count, $permission, $description]): ?>
                    <a class="admin-link-card" href="<?= $e($url) ?>">
                        <span class="admin-link-card__top"><span class="admin-link-card__icon"><?= $uiIcon($icon) ?></span><span class="admin-link-card__count"><?= (int) $count ?></span></span>
                        <strong><?= $e($t($label)) ?></strong>
                        <span class="admin-link-card__description"><?= $e($t($description)) ?></span>
                        <span class="admin-link-card__arrow" aria-hidden="true"><?= $uiIcon('arrow') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</section>
