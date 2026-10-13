<?php $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
<section class="content-container">
    <div class="page-heading"><div><h1><?= $e($revision['title']) ?></h1><p>Wersja <?= (int) $revision['revision_number'] ?> · <?= $e($revision['created_at']) ?> · <?= $e($revision['change_summary']) ?></p></div>
        <a class="button button--ghost" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/history">Historia strony</a></div>
    <article class="card panel document-content" data-revision-content><?= $html ?></article>
    <?php if($currentUser !== null): $collaborationSource=$revision; $collaborationSource['version']=(int)$revision['revision_number']; $collaborationSelector='[data-revision-content]'; require __DIR__.'/../partials/document-collaboration.php'; endif; ?>
</section>
