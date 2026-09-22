<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pageTitle = isset($title) ? $title . ' · ' . $app->name() : $app->name();
$notificationCount = $currentUser === null
    ? 0
    : (new \OpenWiki\Wiki\PageEngagementService($app->database()))->unreadCount((int) $currentUser['id']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
</head>
<body>
<header class="topbar">
    <div class="topbar__inner">
        <a class="brand" href="/"><?= $e($app->name()) ?></a>
        <?php if ($app->installed()): ?>
            <form class="top-search" method="get" action="/search" role="search">
                <label class="sr-only" for="global-search">Search</label>
                <input id="global-search" name="q" type="search" placeholder="Search documentation…" value="<?= $e($_GET['q'] ?? '') ?>" autocomplete="off">
                <span class="shortcut">Ctrl K</span>
            </form>
            <nav class="top-actions" aria-label="Account actions">
                <?php if ($currentUser !== null): ?>
                    <?php if ($app->auth()->can('space.create')): ?>
                        <a class="button button--secondary" href="/spaces/create">Create space</a>
                    <?php endif; ?>
                    <a class="button button--ghost notification-link" href="/notifications">
                        Notifications<?php if ($notificationCount > 0): ?><span class="notification-badge"><?= (int) $notificationCount ?></span><?php endif; ?>
                    </a>
                    <a class="button button--ghost" href="/account/api-tokens">API tokens</a>
                    <?php if ($app->auth()->can('settings.manage') || $app->auth()->can('user.manage') || $app->auth()->can('group.manage') || $app->auth()->can('role.manage')): ?>
                        <a class="button button--ghost" href="/admin">Administration</a>
                    <?php endif; ?>
                    <?php if ($app->auth()->can('page.delete')): ?>
                        <a class="button button--ghost" href="/trash">Trash</a>
                    <?php endif; ?>
                    <?php if ($app->auth()->can('settings.manage')): ?>
                        <a class="button button--ghost" href="/admin/broken-links">Broken links</a>
                    <?php endif; ?>
                    <span class="user-chip"><?= $e($currentUser['username']) ?></span>
                    <form method="post" action="/logout">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <button class="button button--ghost" type="submit">Sign out</button>
                    </form>
                <?php else: ?>
                    <a class="button button--primary" href="/login">Sign in</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
</header>

<?php if ($flashSuccess): ?>
    <div class="flash flash--success" role="status"><?= $e($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
    <div class="flash flash--error" role="alert"><?= $e($flashError) ?></div>
<?php endif; ?>

<main class="page-shell">
    <?= $content ?>
</main>
</body>
</html>
