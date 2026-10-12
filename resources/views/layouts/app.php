<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pageTitle = isset($title) ? $title . ' · ' . $app->name() : $app->name();
$notificationCount = $currentUser === null
    ? 0
    : (new \OpenWiki\Wiki\PageEngagementService($app->database()))->unreadCount((int) $currentUser['id']);
?>
<!doctype html>
<html lang="<?= $e($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
    <script src="/assets/document-reader.js" defer></script>
</head>
<body data-user-id="<?= (int) ($currentUser['id'] ?? 0) ?>">
<textarea hidden data-ui-translations><?= $e(json_encode($locale === 'pl' ? \OpenWiki\Core\I18n::catalog() : new \stdClass(), JSON_THROW_ON_ERROR)) ?></textarea>
<header class="topbar">
    <div class="topbar__inner">
        <a class="brand" href="/"><?= $e($app->name()) ?></a>
        <?php if ($app->installed()): ?>
            <form class="top-search" method="get" action="/search" role="search">
                <label class="sr-only" for="global-search"><?= $e($t('Search')) ?></label>
                <input id="global-search" name="q" type="search" placeholder="<?= $e($t('Search documentation…')) ?>" value="<?= $e($_GET['q'] ?? '') ?>" autocomplete="off">
                <span class="shortcut">Ctrl K</span>
            </form>
            <nav class="top-actions" aria-label="<?= $e($t('Account actions')) ?>">
                <?php if ($currentUser !== null): ?>
                    <a class="button button--ghost notification-link" href="/notifications"><?= $e($t('Notifications')) ?><?php if ($notificationCount > 0): ?><span class="notification-badge"><?= (int) $notificationCount ?></span><?php endif; ?></a>
                    <details class="account-menu" data-close-on-escape><summary class="button button--secondary"><?= $e($currentUser['username']) ?></summary><div class="menu-items">
                    <?php if ($app->auth()->can('space.create')): ?>
                        <a class="button button--secondary" href="/spaces/create"><?= $e($t('Create space')) ?></a>
                    <?php endif; ?>
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
                    <?php require __DIR__ . '/../partials/language-form.php'; ?>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
</header>

<?php if ($flashSuccess): ?>
    <div class="flash flash--success" role="status"><?= $e($t($flashSuccess)) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
    <div class="flash flash--error" role="alert"><?= $e($t($flashError)) ?></div>
<?php endif; ?>

<main class="page-shell">
    <?= $content ?>
</main>
</body>
</html>
