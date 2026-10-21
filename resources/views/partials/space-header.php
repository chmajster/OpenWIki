<?php require __DIR__ . '/ui-icons.php'; ?>
        <div class="page-heading" data-space-header>
            <div>
                <div class="breadcrumbs"><a href="/?view=spaces"><?= $e($t('Spaces')) ?></a><span>/</span><span><?= $e($space['space_key']) ?></span></div>
                <div class="space-overview-badge"><span class="space-avatar space-avatar--large" aria-hidden="true"><?= $e($space['icon'] ?: mb_substr($space['name'], 0, 1)) ?></span><span class="status-pill"><?= $e($t(ucfirst($space['visibility']))) ?> · <?= $e($t($space['status'] === 'archived' ? 'Archived' : 'Active')) ?></span></div>
                <h1><?= $e($space['name']) ?></h1>
                <?php if ($space['description']): ?>
                    <p class="lead"><?= nl2br($e($space['description'])) ?></p>
                <?php endif; ?>
            </div>
            <div class="page-actions">
                <details class="space-action-menu" data-close-on-escape><summary class="button button--secondary"><?= $e($t('More actions')) ?></summary><div class="menu-items">
                <?php if ($canSettings): ?><a class="button button--secondary" href="/spaces/<?= rawurlencode($space['space_key']) ?>/settings"><?= $e($t('Space settings')) ?></a><?php endif; ?>
                <?php if ($currentUser !== null): ?>
                    <a class="button button--ghost" href="/?attention=1&amp;space=<?= (int)$space['id'] ?>"><?= $e($t('Documents needing attention')) ?></a>
                    <form class="inline-form" method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/watch">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="enabled" value="<?= $isWatchingSpace ? '0' : '1' ?>">
                        <button class="button button--ghost" type="submit"><?= $e($t($isWatchingSpace ? 'Stop watching' : 'Watch space')) ?></button>
                    </form>
                <?php endif; ?>
                <a class="button button--ghost" href="/spaces/<?= rawurlencode($space['space_key']) ?>/export"><?= $e($t('Export ZIP')) ?></a>
                <?php if ($canCreatePage): ?>
                    <a class="button button--ghost" href="/spaces/<?= rawurlencode($space['space_key']) ?>/import"><?= $e($t('Import')) ?></a>
                <?php endif; ?>
                </div></details>
                <?php if ($canCreatePage): ?><a class="button button--primary" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/create"><?= $e($t('Create page')) ?></a><?php endif; ?>
            </div>
        </div>

        <?php require __DIR__ . '/../partials/space-tabs.php'; ?>
