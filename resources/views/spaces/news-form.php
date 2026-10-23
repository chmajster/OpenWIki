<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
require __DIR__ . '/../partials/ui-icons.php';
$editing = $postId !== null;
$action = $editing
    ? '/spaces/' . rawurlencode($space['space_key']) . '/news/' . (int) $postId
    : '/spaces/' . rawurlencode($space['space_key']) . '/news';
?>
<section class="wiki-layout space-overview space-news-page">
    <article class="wiki-content">
        <?php require __DIR__ . '/../partials/space-header.php'; ?>
        <section class="space-news-editor card" aria-labelledby="space-news-editor-title">
            <div class="space-news-editor__heading"><span class="page-support-icon"><?= $uiIcon('megaphone') ?></span><div><h2 id="space-news-editor-title"><?= $e($t($editing ? 'Edit space update' : 'Write a space update')) ?></h2><p><?= $e($t('Share a short announcement, summary, or change with this space.')) ?></p></div></div>
            <?php if ($formError !== null): ?><div class="flash flash--error" role="alert"><?= $e($t($formError)) ?></div><?php endif; ?>
            <form method="post" action="<?= $e($action) ?>" class="form-stack">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                <label><?= $e($t('Title')) ?><input name="title" maxlength="200" required value="<?= $e($old['title'] ?? '') ?>" placeholder="<?= $e($t('Give this update a clear title')) ?>"></label>
                <label><?= $e($t('Update')) ?><textarea name="body" rows="9" maxlength="5000" required placeholder="<?= $e($t('What changed? What should the team know?')) ?>"><?= $e($old['body'] ?? '') ?></textarea><small class="muted"><?= $e($t('Up to 5000 characters. Plain text with line breaks.')) ?></small></label>
                <div class="form-actions"><a class="button button--secondary" href="/spaces/<?= rawurlencode($space['space_key']) ?>/news"><?= $e($t('Cancel')) ?></a><button class="button button--primary" type="submit"><?= $e($t($editing ? 'Save update' : 'Publish update')) ?></button></div>
            </form>
        </section>
    </article>
</section>
