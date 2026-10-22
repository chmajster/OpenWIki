<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
require __DIR__ . '/../partials/ui-icons.php';
$applicationShell = $app->installed() && !in_array($template, ['auth/login', 'auth/mfa-challenge'], true);
$workspaceShell = $applicationShell && empty($editorWorkspace);
$navigationSpace = $data['space'] ?? null;
$navigationPageId = isset($data['page']['id']) ? (int) $data['page']['id'] : null;
$navigationSpaces = $workspaceShell
    ? ($data['navigationSpaces'] ?? (new \OpenWiki\Repositories\SpaceRepository($app->database()))->visibleFor($currentUser === null ? null : (int) $currentUser['id'], $app->auth()->can('*'), 12))
    : [];
if ($workspaceShell && $navigationSpace !== null && !array_filter($navigationSpaces, static fn (array $item): bool => ($item['space_key'] ?? null) === ($navigationSpace['space_key'] ?? null))) {
    if (count($navigationSpaces) >= 12) array_pop($navigationSpaces);
    $navigationSpaces[] = $navigationSpace;
}
$navigationCanCreate = $applicationShell && $navigationSpace !== null && $app->auth()->can('page.create') && (new \OpenWiki\Permissions\SpaceAccessService($app))->canEdit($navigationSpace);
$isAdminView = str_starts_with((string) $template, 'admin/');
$adminSection = $isAdminView ? explode('/', (string) $template)[1] : '';
$activeAdminPath = match ($adminSection) {
    'dashboard' => '/admin', 'users' => '/admin/users', 'groups' => '/admin/groups', 'roles' => '/admin/roles',
    'broken-links' => '/admin/broken-links', 'backups' => '/admin/backups', 'webhooks' => '/admin/webhooks',
    'mfa' => '/admin/mfa', 'ldap' => '/admin/ldap', 'system' => '/admin/system', 'templates' => '/admin/templates',
    default => '',
};
$adminNavigation = [
    ['Mission Control', '/admin', 'settings.manage'], ['Users', '/admin/users', 'user.manage'],
    ['Groups', '/admin/groups', 'group.manage'], ['Roles', '/admin/roles', 'role.manage'],
    ['Spaces', '/?view=spaces', 'settings.manage'], ['Pages', '/search?type=page', 'page.view'],
    ['Broken links', '/admin/broken-links', 'settings.manage'], ['Backups', '/admin/backups', '*'],
    ['Webhooks', '/admin/webhooks', 'webhook.manage'], ['MFA policy', '/admin/mfa', '*'],
    ['LDAP / AD', '/admin/ldap', '*'], ['System', '/admin/system', 'settings.manage'],
    ['Templates', '/admin/templates', 'template.manage'],
];
$pageTitle = isset($title) ? (($isAdminView || $template === 'search/index' || str_starts_with((string) $template, 'account/')) ? $t($title) : $title) . ' · ' . $app->name() : $app->name();
$appCssVersion = (string) (@filemtime($app->basePath('public/assets/app.css')) ?: 1);
$workspaceCssVersion = (string) (@filemtime($app->basePath('public/assets/workspace-shell.css')) ?: 1);
$documentWorkspaceCssVersion = (string) (@filemtime($app->basePath('public/assets/document-workspace.css')) ?: 1);
$searchJsVersion = (string) (@filemtime($app->basePath('public/assets/search.js')) ?: 1);
$documentReaderJsVersion = (string) (@filemtime($app->basePath('public/assets/document-reader.js')) ?: 1);
$commentMentionsJsVersion = (string) (@filemtime($app->basePath('public/assets/comment-mentions.js')) ?: 1);
$faviconVersion = (string) (@filemtime($app->basePath('public/assets/openwiki-favicon.svg')) ?: 1);
$notificationCount = $currentUser === null
    ? 0
    : (new \OpenWiki\Wiki\PageEngagementService($app->database()))->unreadCount((int) $currentUser['id']);
?>
<!doctype html>
<html lang="<?= $e($locale) ?>" data-user-id="<?= (int) ($currentUser['id'] ?? 0) ?>" data-instance-id="<?= substr(hash('sha256', $app->basePath() . \OpenWiki\Core\Env::get('APP_URL', '')), 0, 16) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($pageTitle) ?></title>
    <link rel="icon" href="/favicon.ico?v=<?= $e($faviconVersion) ?>" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/openwiki-favicon-32.png?v=<?= $e($faviconVersion) ?>">
    <link rel="icon" type="image/svg+xml" sizes="any" href="/assets/openwiki-favicon.svg?v=<?= $e($faviconVersion) ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/openwiki-apple-touch-icon.png">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0C66E4">
    <script src="/assets/theme.js"></script>
    <link rel="stylesheet" href="/assets/app.css?v=<?= $e($appCssVersion) ?>">
    <link rel="stylesheet" href="/assets/workspace-shell.css?v=<?= $e($workspaceCssVersion) ?>">
    <?php if (!empty($editorWorkspace)): ?><link rel="stylesheet" href="/assets/document-workspace.css?v=<?= $e($documentWorkspaceCssVersion) ?>"><?php endif; ?>
    <script src="/assets/app.js" defer></script>
    <script src="/assets/search.js?v=<?= $e($searchJsVersion) ?>" defer></script>
    <?php if ($template === 'pages/show'): ?><script src="/assets/comment-mentions.js?v=<?= $e($commentMentionsJsVersion) ?>" defer></script><?php endif; ?>
    <?php if ($workspaceShell): ?><script src="/assets/navigation.js" defer></script><?php endif; ?>
    <script src="/assets/document-reader.js?v=<?= $e($documentReaderJsVersion) ?>" defer></script>
    <?php if ($template === 'auth/mfa-setup'): ?><script src="/assets/mfa-setup.js" defer></script><?php endif; ?>
</head>
<body class="<?= $applicationShell ? 'application-shell ' : '' ?><?= !empty($editorWorkspace) ? 'document-workspace' : ($workspaceShell ? 'workspace-shell' : '') ?>" data-user-id="<?= (int) ($currentUser['id'] ?? 0) ?>">
<textarea hidden data-ui-translations><?= $e(json_encode($locale === 'pl' ? \OpenWiki\Core\I18n::catalog() : new \stdClass(), JSON_THROW_ON_ERROR)) ?></textarea>
<a class="skip-link" href="#main-content"><?= $e($t('Skip to content')) ?></a>
<header class="topbar">
    <div class="topbar__inner">
        <?php if ($workspaceShell): ?><button class="shell-icon-button" type="button" data-navigation-toggle aria-controls="workspace-navigation" aria-expanded="true" aria-label="<?= $e($t('Toggle navigation')) ?>" hidden><?= $uiIcon('panel') ?></button><?php endif; ?>
        <a class="brand" href="/"><span class="brand-mark" aria-hidden="true"><img src="/assets/openwiki-favicon-32.png?v=<?= $e($faviconVersion) ?>" width="27" height="27" alt=""></span><?= $e($app->name()) ?></a>
        <?php if ($app->installed()): ?>
            <form class="top-search search-combobox" method="get" action="/search" role="search" data-search-form data-search-global>
                <label class="sr-only" for="global-search"><?= $e($t('Search')) ?></label>
                <span class="search-icon"><?= $uiIcon('search') ?></span>
                <input id="global-search" name="q" type="search" role="combobox" aria-haspopup="listbox" placeholder="<?= $e($t('Search documentation…')) ?>" value="<?= $e($template === 'search/index' ? ($data['query'] ?? '') : '') ?>" autocomplete="off" data-search-query aria-autocomplete="list" aria-controls="global-search-suggestions" aria-expanded="false">
                <div id="global-search-panel" class="global-search-panel" data-global-search-panel hidden>
                    <nav class="global-search-tabs" role="tablist" aria-label="<?= $e($t('Search categories')) ?>">
                        <button type="button" role="tab" aria-selected="true" data-search-tab="all"><?= $e($t('All')) ?></button>
                        <button type="button" role="tab" aria-selected="false" data-search-tab="page"><?= $e($t('Pages')) ?></button>
                        <button type="button" role="tab" aria-selected="false" data-search-tab="space"><?= $e($t('Spaces')) ?></button>
                        <button type="button" role="tab" aria-selected="false" data-search-tab="user"><?= $e($t('People')) ?></button>
                    </nav>
                    <div class="global-search-panel__body">
                        <section class="global-search-panel__results" aria-label="<?= $e($t('Search results')) ?>">
                            <div class="global-search-panel__section-title"><span><?= $e($t('Content')) ?></span><span data-global-search-count></span></div>
                            <ul id="global-search-suggestions" class="search-suggestions global-search-results" data-global-search-results role="listbox" aria-label="<?= $e($t('Search suggestions')) ?>" hidden></ul>
                        </section>
                        <aside class="global-search-filters" aria-label="<?= $e($t('Filters')) ?>">
                            <section class="global-search-filter-group">
                                <h2><?= $e($t('Last updated')) ?></h2>
                                <div class="global-search-chips" data-search-date-filters>
                                    <button type="button" data-search-date="any" aria-pressed="true"><?= $e($t('Any time')) ?></button>
                                    <button type="button" data-search-date="today" aria-pressed="false"><?= $e($t('Today')) ?></button>
                                    <button type="button" data-search-date="yesterday" aria-pressed="false"><?= $e($t('Yesterday')) ?></button>
                                    <button type="button" data-search-date="7" aria-pressed="false"><?= $e($t('Past 7 days')) ?></button>
                                    <button type="button" data-search-date="30" aria-pressed="false"><?= $e($t('Past 30 days')) ?></button>
                                    <button type="button" data-search-date="365" aria-pressed="false"><?= $e($t('Past year')) ?></button>
                                </div>
                            </section>
                            <section class="global-search-filter-group">
                                <h2><?= $e($t('Filter by space')) ?></h2>
                                <div class="global-search-facet-list" data-search-spaces></div>
                                <button class="global-search-show-more" type="button" data-search-spaces-more hidden><?= $e($t('Show more')) ?></button>
                            </section>
                            <section class="global-search-filter-group">
                                <h2><?= $e($t('Filter by contributor')) ?></h2>
                                <div class="global-search-facet-list" data-search-authors></div>
                                <button class="global-search-show-more" type="button" data-search-authors-more hidden><?= $e($t('Show more')) ?></button>
                            </section>
                        </aside>
                    </div>
                    <footer class="global-search-panel__footer"><a href="/search" data-global-search-advanced><?= $uiIcon('search') ?><?= $e($t('Advanced search')) ?></a></footer>
                </div>
                <span class="sr-only" data-search-suggestion-status role="status" aria-live="polite"></span>
                <span class="shortcut">Ctrl K</span>
            </form>
            <?php if ($navigationCanCreate): ?><a class="button button--primary shell-create" href="/spaces/<?= rawurlencode($navigationSpace['space_key']) ?>/pages/create"><?= $uiIcon('plus') ?><span><?= $e($t('Create page')) ?></span></a><?php elseif ($workspaceShell && $app->auth()->can('space.create')): ?><a class="button button--primary shell-create" href="/spaces/create"><?= $uiIcon('plus') ?><span><?= $e($t('Create space')) ?></span></a><?php endif; ?>
            <nav class="top-actions" aria-label="<?= $e($t('Account actions')) ?>">
                <?php require __DIR__ . '/../partials/theme-selector.php'; ?>
                <?php if ($currentUser !== null): ?>
                    <a class="button button--ghost notification-link" href="/notifications" aria-label="<?= $e($t('Notifications')) ?>"><?= $uiIcon('bell') ?><?php if ($notificationCount > 0): ?><span class="notification-badge"><?= (int) $notificationCount ?></span><?php endif; ?></a>
                    <details class="account-menu" data-close-on-escape>
                        <summary class="button button--secondary account-avatar" aria-label="<?= $e($currentUser['username']) ?>" title="<?= $e($currentUser['username']) ?>"><span aria-hidden="true"><?= $e(mb_substr($currentUser['username'], 0, 1)) ?></span></summary>
                        <div class="menu-items account-menu__panel">
                            <header class="account-menu__header">
                                <span class="account-menu__profile-avatar" aria-hidden="true"><?= $e(mb_substr($currentUser['username'], 0, 1)) ?></span>
                                <span class="account-menu__identity"><strong><?= $e($currentUser['username']) ?></strong><small><?= $e($t('OpenWiki account')) ?></small></span>
                            </header>
                            <nav class="account-menu__links" aria-label="<?= $e($t('Account actions')) ?>">
                                <a class="account-menu__link" href="/account">
                                    <span class="account-menu__link-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.2"/><path d="M5 20c.6-3.2 3.1-5 7-5s6.4 1.8 7 5"/></svg></span>
                                    <span class="account-menu__link-copy"><strong><?= $e($t('Profile and settings')) ?></strong><small><?= $e($t('Manage your account and preferences.')) ?></small></span>
                                    <svg class="account-menu__chevron" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="m6 3 5 5-5 5"/></svg>
                                </a>
                                <?php if ($app->auth()->can('settings.manage') || $app->auth()->can('user.manage') || $app->auth()->can('group.manage') || $app->auth()->can('role.manage')): ?>
                                    <a class="account-menu__link" href="/admin">
                                        <span class="account-menu__link-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></svg></span>
                                        <span class="account-menu__link-copy"><strong><?= $e($t('Administration')) ?></strong><small><?= $e($t('Administration tools')) ?></small></span>
                                        <svg class="account-menu__chevron" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="m6 3 5 5-5 5"/></svg>
                                    </a>
                                <?php endif; ?>
                            </nav>
                            <footer class="account-menu__footer">
                                <form method="post" action="/logout">
                                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                    <button class="account-menu__sign-out" type="submit"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M10 4H5v16h5m4-4 4-4-4-4m4 4H9"/></svg><span><?= $e($t('Sign out')) ?></span></button>
                                </form>
                            </footer>
                        </div>
                    </details>
                <?php else: ?>
                    <a class="button button--primary" href="/login"><?= $e($t('Sign in')) ?></a>
                    <?php if ($workspaceShell): ?><details class="language-menu" data-close-on-escape><summary class="button button--ghost" aria-label="<?= $e($t('Interface language')) ?>"><?= $e(strtoupper($locale)) ?></summary><div class="menu-items"><?php require __DIR__ . '/../partials/language-form.php'; ?></div></details><?php else: require __DIR__ . '/../partials/language-form.php'; endif; ?>
                <?php endif; ?>
            </nav>
        <?php else: ?>
            <nav class="top-actions" aria-label="<?= $e($t('Appearance')) ?>"><?php require __DIR__ . '/../partials/theme-selector.php'; ?></nav>
        <?php endif; ?>
    </div>
</header>

<?php if ($workspaceShell): require __DIR__ . '/../partials/workspace-navigation.php'; endif; ?>
<div class="workspace-main" data-workspace-body>
<?php if ($flashSuccess): ?>
    <div class="flash flash--success" role="status"><?= $e($t($flashSuccess)) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
    <div class="flash flash--error" role="alert"><?= $e($t($flashError)) ?></div>
<?php endif; ?>

<?php if ($isAdminView): ?>
    <nav class="admin-quick-nav" aria-label="<?= $e($t('Administration sections')) ?>">
        <div class="admin-quick-nav__inner">
            <?php foreach ($adminNavigation as [$label, $url, $permission]): ?>
                <?php if (!$app->auth()->can($permission)) continue; ?>
                <a class="admin-quick-nav__link<?= $activeAdminPath === $url ? ' is-active' : '' ?>" href="<?= $e($url) ?>"<?= $activeAdminPath === $url ? ' aria-current="page"' : '' ?>><?= $e($t($label)) ?></a>
            <?php endforeach; ?>
        </div>
    </nav>
<?php endif; ?>

<main class="page-shell" id="main-content" tabindex="-1">
    <?= $content ?>
</main>
</div>
<?php if ((($structureEnabled ?? false) || ($workspaceShell && $navigationSpace !== null)) && $currentUser !== null && ($app->auth()->can('page.move') || $app->auth()->can('page.create'))): require __DIR__ . '/../partials/structure-dialog.php'; endif; ?>
</body>
</html>
