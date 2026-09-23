<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="wiki-layout">
    <aside class="wiki-sidebar">
        <div class="sidebar-heading">
            <a href="/spaces/<?= rawurlencode($space['space_key']) ?>">
                <span class="space-key"><?= $e($space['space_key']) ?></span>
                <strong><?= $e($space['name']) ?></strong>
            </a>
        </div>
        <nav aria-label="Pages">
            <?php $activePageId = (int) $page['id']; require __DIR__ . '/../partials/page-tree.php'; ?>
        </nav>
    </aside>

    <article class="wiki-content">
        <div class="page-heading page-heading--compact">
            <div>
                <div class="breadcrumbs">
                    <a href="/spaces/<?= rawurlencode($space['space_key']) ?>"><?= $e($space['name']) ?></a>
                    <span>/</span>
                    <span><?= $e($page['title']) ?></span>
                </div>
                <h1><?= $e($page['title']) ?></h1>
                <div class="page-meta">
                    <span class="status-pill status-pill--<?= $e($page['status']) ?>"><?= $e($page['status']) ?></span>
                    <span>Version <?= (int) $page['version'] ?></span>
                    <span>Created by <?= $e($page['author_username']) ?></span>
                    <span>Updated <?= $e($page['updated_at']) ?></span>
                </div>
                <?php if ($tags !== []): ?>
                    <div class="tag-list" aria-label="Tags">
                        <?php foreach ($tags as $tag): ?>
                            <a class="tag-chip" href="/tags/<?= rawurlencode($tag['slug']) ?>">#<?= $e($tag['name']) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="page-actions">
                <?php if ($currentUser !== null): ?>
                    <form class="inline-form" method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/favorite">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="enabled" value="<?= $isFavorite ? '0' : '1' ?>">
                        <button class="button button--ghost" type="submit"><?= $isFavorite ? 'Unfavorite' : 'Favorite' ?></button>
                    </form>
                    <form class="inline-form" method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/watch">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="enabled" value="<?= $isWatching ? '0' : '1' ?>">
                        <button class="button button--ghost" type="submit"><?= $isWatching ? 'Stop watching' : 'Watch' ?></button>
                    </form>
                <?php endif; ?>
                <details class="export-menu">
                    <summary class="button button--ghost">Export</summary>
                    <div class="export-menu__items">
                        <a href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/export/html">HTML</a>
                        <a href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/export/markdown">Markdown</a>
                        <a href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/export/pdf">PDF</a>
                    </div>
                </details>
                <a class="button button--ghost" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/history">History</a>
                <?php if (!empty($canManagePermissions)): ?>
                    <a class="button button--ghost" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/permissions">Permissions</a>
                <?php endif; ?>
                <?php if ($canEdit): ?>
                    <a class="button button--primary" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/edit">Edit</a>
                <?php endif; ?>
                <?php if ($canDeletePage): ?>
                    <form class="inline-form" method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/delete" onsubmit="return confirm('Move this page to trash?')">
                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                        <button class="button button--ghost" type="submit">Delete</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="document-layout <?= !empty($tableOfContents) ? 'document-layout--with-toc' : '' ?>">
            <div class="document card" data-document-content>
                <?= $renderedContent ?>
            </div>
            <?php if (!empty($tableOfContents)): ?>
                <aside class="page-toc card">
                    <?= $tableOfContents ?>
                </aside>
            <?php endif; ?>
        </div>

        <?php if ($backlinks !== []): ?>
            <section class="backlinks-section" id="backlinks">
                <div class="panel__header">
                    <h2>Referenced by</h2>
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
                <h2>Attachments</h2>
                <span class="count"><?= count($attachments) ?></span>
            </div>

            <?php if ($canUploadAttachment): ?>
                <form class="card form-card attachment-upload" method="post" enctype="multipart/form-data" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/attachments">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <label>Upload attachment
                        <input type="file" name="attachment" required>
                    </label>
                    <button class="button button--secondary" type="submit">Upload</button>
                </form>
            <?php endif; ?>

            <?php if ($attachments === []): ?>
                <div class="card empty-state">No attachments.</div>
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
                                    <a class="button button--ghost" href="/attachments/<?= (int) $attachment['id'] ?>/preview" target="_blank" rel="noopener">Preview</a>
                                <?php endif; ?>
                                <a class="button button--ghost" href="/attachments/<?= (int) $attachment['id'] ?>/download">Download</a>

                                <?php if ($canUploadAttachment): ?>
                                    <details>
                                        <summary class="button button--ghost">New version</summary>
                                        <form class="form-stack compact-form" method="post" enctype="multipart/form-data" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/attachments/<?= (int) $attachment['id'] ?>/version">
                                            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                            <input type="file" name="attachment" required>
                                            <button class="button button--secondary" type="submit">Upload version</button>
                                        </form>
                                    </details>
                                    <details>
                                        <summary class="button button--ghost">Rename</summary>
                                        <form class="form-stack compact-form" method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/attachments/<?= (int) $attachment['id'] ?>/rename">
                                            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                            <input name="name" maxlength="255" value="<?= $e($attachment['name']) ?>" required>
                                            <button class="button button--secondary" type="submit">Rename</button>
                                        </form>
                                    </details>
                                <?php endif; ?>

                                <?php if ($canDeleteAttachment): ?>
                                    <form class="inline-form" method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/attachments/<?= (int) $attachment['id'] ?>/delete" onsubmit="return confirm('Delete this attachment?')">
                                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                        <button class="button button--ghost" type="submit">Delete</button>
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
                <h2>Comments</h2>
                <span class="count"><?= count($comments) ?></span>
            </div>

            <?php if ($canComment): ?>
                <form class="card form-card comment-form" method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/comments">
                    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                    <label>Add comment
                        <textarea name="body" rows="4" maxlength="10000" required placeholder="Write a comment. Use @username to mention someone."></textarea>
                    </label>
                    <div class="form-actions">
                        <button class="button button--primary" type="submit">Comment</button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($comments === []): ?>
                <div class="card empty-state">No comments yet.</div>
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
                                <?php if ($comment['updated_at'] !== $comment['created_at']): ?><span>edited</span><?php endif; ?>
                            </div>
                            <div class="comment__body"><?= $comment['body_html'] ?></div>

                            <div class="comment__actions">
                                <?php if ($canComment): ?>
                                    <details>
                                        <summary class="button button--ghost">Reply</summary>
                                        <form method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/comments" class="form-stack compact-form">
                                            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                            <input type="hidden" name="parent_id" value="<?= (int) $comment['id'] ?>">
                                            <textarea name="body" rows="3" maxlength="10000" required placeholder="Reply to <?= $e($comment['username']) ?>"></textarea>
                                            <button class="button button--secondary" type="submit">Reply</button>
                                        </form>
                                    </details>
                                <?php endif; ?>

                                <?php if ($isOwnComment): ?>
                                    <details>
                                        <summary class="button button--ghost">Edit</summary>
                                        <form method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/comments/<?= (int) $comment['id'] ?>/edit" class="form-stack compact-form">
                                            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                            <textarea name="body" rows="3" maxlength="10000" required><?= $e($plainBody) ?></textarea>
                                            <button class="button button--secondary" type="submit">Save</button>
                                        </form>
                                    </details>
                                <?php endif; ?>

                                <?php if ($isOwnComment || $canDeleteComment): ?>
                                    <form class="inline-form" method="post" action="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/comments/<?= (int) $comment['id'] ?>/delete" onsubmit="return confirm('Delete this comment?')">
                                        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
                                        <button class="button button--ghost" type="submit">Delete</button>
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
