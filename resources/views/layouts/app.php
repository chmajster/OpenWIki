<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
require __DIR__ . '/../partials/ui-icons.php';
$workspaceShell = $app->installed() && empty($editorWorkspace) && !in_array($template, ['auth/login', 'auth/mfa-challenge'], true);
$navigationSpace = $data['space'] ?? null;
$navigationPageId = isset($data['page']['id']) ? (int) $data['page']['id'] : null;
$navigationSpaces = $workspaceShell && $navigationSpace === null
    ? ($template === 'dashboard/index' ? $data['spaces'] : (new \OpenWiki\Repositories\SpaceRepository($app->database()))->visibleFor($currentUser === null ? null : (int) $currentUser['id'], $app->auth()->can('*'), 12))
    : [];
$navigationCanCreate = $workspaceShell && $navigationSpace !== null && $app->auth()->can('page.create') && (new \OpenWiki\Permissions\SpaceAccessService($app))->canEdit($navigationSpace);
$pageTitle = isset($title) ? $title . ' · ' . $app->name() : $app->name();
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
    <script src="/assets/theme.js"></script>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/workspace-shell.css">
    <?php if (!empty($editorWorkspace)): ?><link rel="stylesheet" href="/assets/document-workspace.css"><?php endif; ?>
    <script src="/assets/app.js" defer></script>
    <script src="/assets/search.js" defer></script>
    <?php if ($workspaceShell): ?><script src="/assets/navigation.js" defer></script><?php endif; ?>
    <script src="/assets/document-reader.js" defer></script>
</head>
<body class="<?= !empty($editorWorkspace) ? 'document-workspace' : ($workspaceShell ? 'workspace-shell' : '') ?>" data-user-id="<?= (int) ($currentUser['id'] ?? 0) ?>">
<textarea hidden data-ui-translations><?= $e(json_encode($locale === 'pl' ? \OpenWiki\Core\I18n::catalog() : new \stdClass(), JSON_THROW_ON_ERROR)) ?></textarea>
<a class="skip-link" href="#main-content"><?= $e($t('Skip to content')) ?></a>
<header class="topbar">
    <div class="topbar__inner">
        <?php if ($workspaceShell): ?><button class="shell-icon-button" type="button" data-navigation-toggle aria-controls="workspace-navigation" aria-expanded="true" aria-label="<?= $e($t('Toggle navigation')) ?>" hidden><?= $uiIcon('panel') ?></button><?php endif; ?>
        <a class="brand" href="/"><span class="brand-mark" aria-hidden="true"><?= $uiIcon('book') ?></span><?= $e($app->name()) ?></a>
        <?php if ($app->installed()): ?>
            <form class="top-search search-combobox" method="get" action="/search" role="search" data-search-form>
                <label class="sr-only" for="global-search"><?= $e($t('Search')) ?></label>
                <span class="search-icon"><?= $uiIcon('search') ?></span>
                <input id="global-search" name="q" type="search" placeholder="<?= $e($t('Search documentation…')) ?>" value="<?= $e(is_scalar($_GET['q'] ?? '') ? ($_GET['q'] ?? '') : '') ?>" autocomplete="off" data-search-query aria-autocomplete="list" aria-controls="global-search-suggestions" aria-expanded="false">
                <ul id="global-search-suggestions" class="search-suggestions" role="listbox" aria-label="Podpowiedzi wyszukiwania" hidden></ul>
                <span class="sr-only" data-search-suggestion-status role="status" aria-live="polite"></span>
                <span class="shortcut">Ctrl K</span>
            </form>
            <?php if ($navigationCanCreate): ?><a class="button button--primary shell-create" href="/spaces/<?= rawurlencode($navigationSpace['space_key']) ?>/pages/create"><?= $uiIcon('plus') ?><span><?= $e($t('Create page')) ?></span></a><?php elseif ($workspaceShell && $app->auth()->can('space.create')): ?><a class="button button--primary shell-create" href="/spaces/create"><?= $uiIcon('plus') ?><span><?= $e($t('Create space')) ?></span></a><?php endif; ?>
            <nav class="top-actions" aria-label="<?= $e($t('Account actions')) ?>">
                <?php require __DIR__ . '/../partials/theme-selector.php'; ?>
                <?php if ($currentUser !== null): ?>
                    <a class="button button--ghost notification-link" href="/notifications" aria-label="<?= $e($t('Notifications')) ?>"><?= $uiIcon('bell') ?><?php if ($notificationCount > 0): ?><span class="notification-badge"><?= (int) $notificationCount ?></span><?php endif; ?></a>
                    <details class="account-menu" data-close-on-escape><summary class="button button--secondary account-avatar" aria-label="<?= $e($currentUser['username']) ?>"><?= $e(mb_substr($currentUser['username'], 0, 1)) ?></summary><div class="menu-items">
                    <strong class="account-name"><?= $e($currentUser['username']) ?></strong>
                    <?php if ($app->auth()->can('space.create')): ?>
                        <a class="button button--secondary" href="/spaces/create"><?= $e($t('Create space')) ?></a>
                    <?php endif; ?>
                    <a class="button button--ghost" href="/?view=work"><?= $e($t('Work center')) ?></a>
                    <a class="button button--ghost" href="/account/mfa/setup">MFA</a>
                    <a class="button button--ghost" href="/account/sessions"><?= $e($t('Sessions')) ?></a>
                    <a class="button button--ghost" href="/account/api-tokens"><?= $e($t('API tokens')) ?></a>
                    <?php if ($app->auth()->can('settings.manage') || $app->auth()->can('user.manage') || $app->auth()->can('group.manage') || $app->auth()->can('role.manage')): ?>
                        <a class="button button--ghost" href="/admin"><?= $e($t('Administration')) ?></a>
                    <?php endif; ?>
                    <?php if ($app->auth()->can('page.delete')): ?>
                        <a class="button button--ghost" href="/trash"><?= $e($t('Trash')) ?></a>
                    <?php endif; ?>
                    <?php if ($app->auth()->can('settings.manage')): ?>
                        <a class="button button--ghost" href="/admin/broken-links"><?= $e($t('Broken links')) ?></a>
                    <?php endif; ?>

                    <form method="post" action="/logout">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <button class="button button--ghost" type="submit"><?= $e($t('Sign out')) ?></button>
                    </form>
                    <?php require __DIR__ . '/../partials/language-form.php'; ?>
                    </div></details>
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
<div class="workspace-body" data-workspace-body>
<?php if ($flashSuccess): ?>
    <div class="flash flash--success" role="status"><?= $e($t($flashSuccess)) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
    <div class="flash flash--error" role="alert"><?= $e($t($flashError)) ?></div>
<?php endif; ?>

<main class="page-shell" id="main-content" tabindex="-1">
    <?= $content ?>
</main>
</div>
<?php if ((($structureEnabled ?? false) || ($workspaceShell && $navigationSpace !== null)) && $currentUser !== null && ($app->auth()->can('page.move') || $app->auth()->can('page.create'))): require __DIR__ . '/../partials/structure-dialog.php'; endif; ?>
</body>
</html>
