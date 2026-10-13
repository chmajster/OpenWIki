<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="wiki-layout">
    <details class="wiki-sidebar" data-page-panel open>
        <summary class="sidebar-toggle"><?= $e($t('Pages')) ?></summary>
        <div class="sidebar-body">
        <div class="sidebar-heading">
            <a href="/spaces/<?= rawurlencode($space['space_key']) ?>">
                <span class="space-key"><?= $e($space['space_key']) ?></span>
                <strong><?= $e($space['name']) ?></strong>
            </a>
        </div>
        <nav aria-label="<?= $e($t('Pages')) ?>">
            <?php $activePageId = null; require __DIR__ . '/../partials/page-tree.php'; ?>
        </nav>
        <?php if ($canCreatePage): ?>
            <a class="button button--secondary button--block" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/create"><?= $e($t('Create page')) ?></a>
        <?php endif; ?>
        </div>
    </details>

    <article class="wiki-content">
        <div class="page-heading">
            <div>
                <div class="eyebrow"><?= $e(['public' => 'Publiczna', 'private' => 'Prywatna', 'restricted' => 'Ograniczona'][$space['visibility']]) ?> przestrzeń · <?= $space['status'] === 'archived' ? 'Archiwum' : 'Aktywna' ?></div>
                <h1><?= $e($space['icon'] ?? '') ?> <?= $e($space['name']) ?></h1>
                <?php if ($space['description']): ?>
                    <p class="lead"><?= nl2br($e($space['description'])) ?></p>
                <?php endif; ?>
            </div>
            <div class="page-actions">
                <?php if ($canSettings): ?><a class="button button--secondary" href="/spaces/<?= rawurlencode($space['space_key']) ?>/settings">Ustawienia przestrzeni</a><?php endif; ?>
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
                    <a class="button button--primary" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/create"><?= $e($t('Create page')) ?></a>
                <?php endif; ?>
            </div>
        </div>

        <div class="card panel">
            <?php if ($homePage !== null): ?><h2>Strona startowa</h2><a href="<?= $e(\OpenWiki\Spaces\DocumentationTreeService::url($space, $homePage)) ?>"><?= $e($homePage['title']) ?></a><?php else: ?><h2>Dokumentacja</h2><p>Wybierz stronę z drzewa lub wyszukaj ją w tej przestrzeni. Strzałki rozwijają gałęzie; F2 otwiera operacje strony.</p><?php endif; ?>
            <?php if ($space['status'] === 'archived'): ?><p role="status">Archiwum jest dostępne do odczytu zgodnie z dotychczasowymi uprawnieniami. Aby edytować dokumenty, przywróć przestrzeń w ustawieniach.</p><?php endif; ?>
        </div>
    </article>
</section>
