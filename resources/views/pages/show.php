<?php
require __DIR__ . '/../partials/ui-icons.php';
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pageUrl = '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($page['slug']);
?>
<section class="wiki-layout reader-layout">
    <article class="wiki-content">
        <div class="reader-toolbar">
                <div class="breadcrumbs">
                    <a href="/spaces/<?= rawurlencode($space['space_key']) ?>"><?= $e($space['name']) ?></a>
                    <?php foreach ($breadcrumbs as $crumb): ?><span>/</span><?php if ($crumb['url'] === null): ?><span><?= $e($crumb['title']) ?></span><?php else: ?><a href="<?= $e($crumb['url']) ?>"><?= $e($crumb['title']) ?></a><?php endif; ?><?php endforeach; ?>
                    <span>/</span>
                    <span><?= $e($page['title']) ?></span>
                </div>
            <div class="page-actions">
                <?php if ($currentUser !== null): ?>
                    <form class="inline-form" method="post" action="<?= $e($pageUrl) ?>/favorite">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="enabled" value="<?= $isFavorite ? '0' : '1' ?>">
                        <button class="shell-icon-button favorite-action" type="submit" aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>" aria-label="<?= $e($t($isFavorite ? 'Unfavorite' : 'Favorite')) ?>"><?= $uiIcon('star') ?><span class="sr-only"><?= $e($t($isFavorite ? 'Unfavorite' : 'Favorite')) ?></span></button>
                    </form>
                <?php endif; ?>
                <?php if ($canEdit): ?>
                    <a class="button button--ghost" href="<?= $e($pageUrl) ?>/edit"><?= $uiIcon('edit') ?><?= $e($t('Edit')) ?></a>
                <?php endif; ?>

                <details class="reader-share" data-reader-share data-close-on-escape>
                    <summary class="button button--ghost"><?= $uiIcon('link') ?><?= $e($t('Share')) ?></summary>
                    <div class="reader-share-panel">
                        <strong><?= $e($t('Share this page')) ?></strong>
                        <p><?= $e($t('People need access to this page to open the link.')) ?></p>
                        <label data-reader-share-field hidden><?= $e($t('Page link')) ?><input type="url" data-reader-share-url readonly></label>
                        <button class="button button--primary" type="button" data-reader-copy hidden><?= $e($t('Copy link')) ?></button>
                        <a href="<?= $e($pageUrl) ?>" data-reader-page-link><?= $e($t('Open page link')) ?></a>
                        <span class="reader-share-feedback" data-reader-copy-status role="status" aria-live="polite"></span>
                    </div>
                </details>
                <a class="button button--ghost" href="<?= $e($pageUrl) ?>/history"><?= $e($t('History')) ?></a>
                <?php if (!empty($canManagePermissions)): ?>
                    <a class="button button--ghost" href="<?= $e($pageUrl) ?>/permissions"><?= $e($t('Permissions')) ?></a>
                <?php endif; ?>
                <details class="page-menu" data-close-on-escape><summary class="button button--ghost" aria-label="<?= $e($t('More actions')) ?>" title="<?= $e($t('More actions')) ?>"><span aria-hidden="true">•••</span></summary><div class="menu-items">
                <?php if ($canMove || $canCopy): ?><button type="button" class="button button--ghost" data-structure-page="<?= $e(json_encode(['id' => (int) $page['id'], 'title' => $page['title'], 'version' => $headVersion, 'parent_id' => $visibleParentId, 'space_id' => (int) $space['id'], 'can_move' => $canMove, 'can_copy' => $canCopy], JSON_THROW_ON_ERROR)) ?>">Przenieś lub kopiuj</button><?php endif; ?>
                <?php if ($currentUser !== null): ?>

                    <form class="inline-form" method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/watch">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="enabled" value="<?= $isWatching ? '0' : '1' ?>">
                        <button class="button button--ghost" type="submit"><?= $e($t($isWatching ? 'Stop watching' : 'Watch')) ?></button>
                    </form>
                <?php endif; ?>
                <details class="export-menu" data-close-on-escape>
                    <summary class="button button--ghost"><?= $e($t('Export')) ?></summary>
                    <div class="export-menu__items">
                        <a href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/export/html">HTML</a>
                        <a href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/export/markdown">Markdown</a>
                        <a href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/export/pdf">PDF</a>
                    </div>
                </details>
                <?php if ($canDeletePage): ?>
                    <form class="inline-form" method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/delete" data-confirm="<?= $e($t('Move this page and all its descendants to trash?')) ?>">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <button class="button button--ghost" type="submit"><?= $e($t('Delete')) ?></button>
                    </form>
                <?php endif; ?>
                </div></details>
            </div>
        </div>
        <header class="reader-heading">
                <h1><?= $e($page['title']) ?></h1>
                <div class="page-meta">
                    <span class="reader-author-avatar" aria-hidden="true"><?= $e(mb_substr($page['author_username'], 0, 1)) ?></span>
                    <span><?= $e($t('Created by')) ?> <strong><?= $e($page['author_username']) ?></strong></span>
                    <span class="reader-meta-separator" aria-hidden="true">·</span>
                    <span><?= $e($t('Updated')) ?> <time datetime="<?= $e(str_replace(' ', 'T', $page['updated_at'])) ?>Z"><?= $e($page['updated_at']) ?></time></span>
                    <span class="reader-meta-separator" aria-hidden="true">·</span>
                    <span class="status-pill status-pill--<?= $e($page['status']) ?>"><?= $e($t($page['status'])) ?></span>
                    <span><?= $e($t('Version')) ?> <?= (int) $page['version'] ?></span>
                </div>
                <?php if ($tags !== []): ?>
                    <div class="tag-list" aria-label="<?= $e($t('Tags')) ?>">
                        <?php foreach ($tags as $tag): ?>
                            <a class="tag-chip" href="/tags/<?= rawurlencode($tag['slug']) ?>">#<?= $e($tag['name']) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
        </header>

        <details class="knowledge-status" data-knowledge-status><summary><?= $e($t('Content health')) ?></summary><div><p>Właściciel merytoryczny: <?= $e($contentOwnerName) ?>. Termin przeglądu: <?= $e($page['review_due_at'] ?? 'Nie wyznaczono') ?>. <?= $page['review_due_at'] !== null && $page['review_due_at'] < gmdate('Y-m-d') ? 'Dokument wymaga przeglądu.' : '' ?> Potwierdzenie aktualności: <?= (int) ($page['verified_revision'] ?? 0) === (int) $page['version'] ? $e($page['verified_at']) : 'Brak dla tej rewizji' ?>.</p>
        <?php if (!empty($canReadWorking)): ?><a class="button" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/review">Obieg i aktualność · robocza rewizja <?= (int) $headVersion ?></a><?php endif; ?>
        <a href="/knowledge/overdue">Dokumenty po terminie przeglądu</a></div></details>
        <div class="document-layout document-layout--with-toc">
            <div class="document card document-content" data-document-content>
                <?= $renderedContent ?>
            </div>
            <aside class="page-toc card" aria-label="<?= $e($t('Page navigation')) ?>">
                <details class="reader-outline" data-reader-outline open>
                    <summary><?= $e($t('On this page')) ?></summary>
                    <div class="reader-outline-links">
                        <?= $tableOfContents ?>
                        <nav class="reader-section-links" aria-label="<?= $e($t('Page sections')) ?>">
                            <?php if ($backlinks !== []): ?><a href="#backlinks"><?= $e($t('Referenced by')) ?></a><?php endif; ?>
                            <a href="#attachments"><?= $e($t('Attachments')) ?></a>
                            <a href="#comments"><?= $e($t('Comments')) ?></a>
                        </nav>
                    </div>
                </details>
            </aside>
        </div>

        <?php if ($currentUser !== null): $collaborationSource = $page; $collaborationSelector = '[data-document-content]'; require __DIR__ . '/../partials/document-collaboration.php'; endif; ?>

        <?php if ($backlinks !== []): ?>
            <section class="backlinks-section" id="backlinks">
                <div class="panel__header">
                    <h2><?= $e($t('Referenced by')) ?></h2>
                    <span class="count"><?= count($backlinks) ?></span>
                </div>
                <div class="card panel">
                    <ul class="item-list">
                        <?php foreach ($backlinks as $backlink): ?>
                            <li>
                                <a href="/spaces/<?= rawurlencode($backlink['space_key']) ?>/pages/<?= rawurlencode($backlink['slug']) ?>">
                                    <strong><?= $e($backlink['title']) ?></strong>
                                    <span><?= $e($backlink['space_name']) ?> · <?= $e($backlink['updated_at']) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        <?php endif; ?>

        <section class="attachments-section" id="attachments">
            <div class="panel__header">
                <h2><?= $e($t('Attachments')) ?></h2>
                <span class="count"><?= count($attachments) ?></span>
            </div>

            <?php if ($canUploadAttachment): ?>
                <form class="card form-card attachment-upload" method="post" enctype="multipart/form-data" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/attachments">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <label><?= $e($t('Upload attachment')) ?>
                        <input type="file" name="attachment" required>
                    </label>
                    <button class="button button--secondary" type="submit"><?= $e($t('Upload')) ?></button>
                </form>
            <?php endif; ?>

            <?php if ($attachments === []): ?>
                <div class="card empty-state"><?= $e($t('No attachments.')) ?></div>
            <?php else: ?>
                <div class="attachment-list">
                    <?php foreach ($attachments as $attachment): ?>
                        <article class="card attachment-row" id="attachment-<?= (int) $attachment['id'] ?>">
                            <div class="attachment-row__main">
                                <strong><?= $e($attachment['name']) ?></strong>
                                <span><?= $e($attachment['mime_type']) ?> · <?= number_format((int) $attachment['size_bytes'] / 1024, 1) ?> KiB · v<?= (int) $attachment['current_version'] ?></span>
                                <small>SHA-256 <?= $e($attachment['sha256']) ?> · uploaded by <?= $e($attachment['uploader_username']) ?></small>
                            </div>
                            <div class="attachment-row__actions">
                                <?php if (in_array($attachment['mime_type'], ['image/png','image/jpeg','image/gif','image/webp','application/pdf','text/plain'], true)): ?>
                                    <a class="button button--ghost" href="/attachments/<?= (int) $attachment['id'] ?>/preview" target="_blank" rel="noopener"><?= $e($t('Preview')) ?></a>
                                <?php endif; ?>
                                <a class="button button--ghost" href="/attachments/<?= (int) $attachment['id'] ?>/download"><?= $e($t('Download')) ?></a>

                                <?php if ($canUploadAttachment): ?>
                                    <details>
                                        <summary class="button button--ghost"><?= $e($t('New version')) ?></summary>
                                        <form class="form-stack compact-form" method="post" enctype="multipart/form-data" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/attachments/<?= (int) $attachment['id'] ?>/version">
                                            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                            <input type="file" name="attachment" required>
                                            <button class="button button--secondary" type="submit"><?= $e($t('Upload version')) ?></button>
                                        </form>
                                    </details>
                                    <details>
                                        <summary class="button button--ghost"><?= $e($t('Rename')) ?></summary>
                                        <form class="form-stack compact-form" method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/attachments/<?= (int) $attachment['id'] ?>/rename">
                                            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                            <input name="name" maxlength="255" value="<?= $e($attachment['name']) ?>" required>
                                            <button class="button button--secondary" type="submit"><?= $e($t('Rename')) ?></button>
                                        </form>
                                    </details>
                                <?php endif; ?>

                                <?php if ($canDeleteAttachment): ?>
                                    <form class="inline-form" method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/attachments/<?= (int) $attachment['id'] ?>/delete" data-confirm="<?= $e($t('Delete this attachment?')) ?>">
                                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                        <button class="button button--ghost" type="submit"><?= $e($t('Delete')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="comments-section" id="comments">
            <div class="panel__header">
                <h2><?= $e($t('Comments')) ?></h2>
                <span class="count"><?= count($comments) ?></span>
            </div>

            <?php if ($canComment): ?>
                <form class="card form-card comment-form" method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/comments">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <label><?= $e($t('Add comment')) ?>
                        <textarea name="body" rows="4" maxlength="10000" required placeholder="<?= $e($t('Write a comment. Use @username to mention someone.')) ?>"></textarea>
                    </label>
                    <div class="form-actions">
                        <button class="button button--primary" type="submit"><?= $e($t('Comment')) ?></button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($comments === []): ?>
                <div class="card empty-state"><?= $e($t('No comments yet.')) ?></div>
            <?php else: ?>
                <div class="comment-list">
                    <?php foreach ($comments as $comment): ?>
                        <?php
                        $isOwnComment = $currentUser !== null && (int) $comment['author_id'] === (int) $currentUser['id'];
                        $plainBody = preg_replace('/<br\s*\/?\s*>/i', "\n", (string) $comment['body_html']);
                        $plainBody = html_entity_decode(strip_tags((string) $plainBody), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        ?>
                        <article class="card comment <?= $comment['parent_id'] !== null ? 'comment--reply' : '' ?>" id="comment-<?= (int) $comment['id'] ?>">
                            <div class="comment__header">
                                <strong><?= $e($comment['username']) ?></strong>
                                <span><?= $e($comment['created_at']) ?></span>
                                <?php if ($comment['updated_at'] !== $comment['created_at']): ?><span><?= $e($t('edited')) ?></span><?php endif; ?>
                            </div>
                            <div class="comment__body"><?= $comment['body_html'] ?></div>

                            <div class="comment__actions">
                                <?php if ($canComment): ?>
                                    <details>
                                        <summary class="button button--ghost"><?= $e($t('Reply')) ?></summary>
                                        <form method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/comments" class="form-stack compact-form">
                                            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                            <input type="hidden" name="parent_id" value="<?= (int) $comment['id'] ?>">
                                            <textarea name="body" rows="3" maxlength="10000" required placeholder="Reply to <?= $e($comment['username']) ?>"></textarea>
                                            <button class="button button--secondary" type="submit"><?= $e($t('Reply')) ?></button>
                                        </form>
                                    </details>
                                <?php endif; ?>

                                <?php if ($isOwnComment && $canComment): ?>
                                    <details>
                                        <summary class="button button--ghost"><?= $e($t('Edit')) ?></summary>
                                        <form method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/comments/<?= (int) $comment['id'] ?>/edit" class="form-stack compact-form">
                                            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                            <textarea name="body" rows="3" maxlength="10000" required><?= $e($plainBody) ?></textarea>
                                            <button class="button button--secondary" type="submit"><?= $e($t('Save')) ?></button>
                                        </form>
                                    </details>
                                <?php endif; ?>

                                <?php if (($isOwnComment && $canComment) || $canDeleteComment): ?>
                                    <form class="inline-form" method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/comments/<?= (int) $comment['id'] ?>/delete" data-confirm="<?= $e($t('Delete this comment?')) ?>">
                                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                        <button class="button button--ghost" type="submit"><?= $e($t('Delete')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </article>
</section>
