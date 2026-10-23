<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
require __DIR__ . '/../partials/ui-icons.php';
?>
<section class="wiki-layout space-overview space-news-page">
    <article class="wiki-content">
        <?php require __DIR__ . '/../partials/space-header.php'; ?>
        <section class="space-news-stream" aria-labelledby="space-news-heading">
            <div class="space-news-stream__heading">
                <div><span class="eyebrow"><?= $e($t('Space news')) ?></span><h2 id="space-news-heading"><?= $e($t('Latest updates')) ?></h2><p class="muted"><?= $e($t('Announcements, summaries, and changes from this team.')) ?></p></div>
                <?php if ($canCreateNews): ?><a class="button button--primary" href="/spaces/<?= rawurlencode($space['space_key']) ?>/news/create"><?= $uiIcon('plus') ?><?= $e($t('Write an update')) ?></a><?php endif; ?>
            </div>
            <?php if ($news === []): ?>
                <div class="card space-news-empty"><span class="space-news-empty__icon"><?= $uiIcon('megaphone') ?></span><h3><?= $e($t('No updates yet')) ?></h3><p><?= $e($t('Share the first announcement or summary with this space.')) ?></p><?php if ($canCreateNews): ?><a class="button button--secondary" href="/spaces/<?= rawurlencode($space['space_key']) ?>/news/create"><?= $e($t('Write an update')) ?></a><?php endif; ?></div>
            <?php else: ?>
                <div class="space-news-list">
                    <?php foreach ($news as $post): $canManagePost = $canManageNews || ($currentUser !== null && (int) $post['author_id'] === (int) $currentUser['id']); ?>
                        <article class="card space-news-post" id="news-<?= (int) $post['id'] ?>">
                            <header class="space-news-post__header">
                                <div class="space-news-post__author"><span class="reader-author-avatar" aria-hidden="true"><?= $e(mb_substr($post['username'], 0, 1)) ?></span><div><strong><?= $e($post['username']) ?></strong><small><time datetime="<?= $e(\OpenWiki\Core\DateFormatter::machineValue($post['created_at'])) ?>"><?= $e(\OpenWiki\Core\DateFormatter::formatDateTime($post['created_at'], $dateFormat ?? 'locale', $locale)) ?></time><?php if ($post['updated_at'] !== $post['created_at']): ?> · <?= $e($t('Updated')) ?> <?= $e(\OpenWiki\Core\DateFormatter::formatDateTime($post['updated_at'], $dateFormat ?? 'locale', $locale)) ?><?php endif; ?></small></div></div>
                                <?php if ($canManagePost && $space['status'] === 'active'): ?><details class="space-news-post__menu"><summary class="button button--ghost" aria-label="<?= $e($t('More actions')) ?>">···</summary><div class="menu-items"><a href="/spaces/<?= rawurlencode($space['space_key']) ?>/news/<?= (int) $post['id'] ?>/edit"><?= $uiIcon('edit') ?><?= $e($t('Edit')) ?></a><form method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/news/<?= (int) $post['id'] ?>/delete" data-confirm="<?= $e($t('Delete this update?')) ?>"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><button type="submit"><?= $uiIcon('trash') ?><?= $e($t('Delete')) ?></button></form></div></details><?php endif; ?>
                            </header>
                            <h3><?= $e($post['title']) ?></h3>
                            <div class="space-news-post__body"><?= nl2br($e($post['body'])) ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </article>
</section>
