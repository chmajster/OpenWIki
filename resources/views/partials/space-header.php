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
                <details class="space-action-menu" data-close-on-escape><summary class="button button--secondary"><?= $e($t('More actions')) ?><?= $uiIcon('chevron') ?></summary><div class="menu-items space-action-menu__items">
                <div class="space-action-menu__heading"><span><?= $e($t('Space actions')) ?></span><strong><?= $e($space['name']) ?></strong></div>
                <?php if ($canSettings): ?><a class="space-action-menu__item" href="/spaces/<?= rawurlencode($space['space_key']) ?>/settings"><?= $uiIcon('settings') ?><span><?= $e($t('Space settings')) ?></span></a><?php endif; ?>
                <?php if ($currentUser !== null): ?>
                    <a class="space-action-menu__item" href="/?attention=1&amp;space=<?= (int)$space['id'] ?>"><?= $uiIcon('check') ?><span><?= $e($t('Documents needing attention')) ?></span></a>
                    <form class="inline-form space-action-menu__form" method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/watch">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="enabled" value="<?= $isWatchingSpace ? '0' : '1' ?>">
                        <button class="space-action-menu__item" type="submit"><?= $uiIcon('eye') ?><span><?= $e($t($isWatchingSpace ? 'Stop watching' : 'Watch space')) ?></span></button>
                    </form>
                <?php endif; ?>
                <?php if ($canSettings || $currentUser !== null): ?><div class="space-action-menu__divider" aria-hidden="true"></div><?php endif; ?>
                <a class="space-action-menu__item" href="/spaces/<?= rawurlencode($space['space_key']) ?>/export"><?= $uiIcon('download') ?><span><?= $e($t('Export ZIP')) ?></span></a>
                <?php if ($canCreatePage): ?>
                    <a class="space-action-menu__item" href="/spaces/<?= rawurlencode($space['space_key']) ?>/import"><?= $uiIcon('upload') ?><span><?= $e($t('Import')) ?></span></a>
                <?php endif; ?>
                </div></details>
                <?php if ($canCreatePage): ?><a class="button button--primary" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/create"><?= $e($t('Create page')) ?></a><?php endif; ?>
            </div>
        </div>

        <?php require __DIR__ . '/../partials/space-tabs.php'; ?>
