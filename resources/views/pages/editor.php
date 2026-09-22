<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$old = $old ?? [];
$serverDraft = $serverDraft ?? null;
$isEdit = $page !== null;
$field = static function (string $key, mixed $fallback = '') use ($old): mixed {
    return array_key_exists($key, $old) ? $old[$key] : $fallback;
};

$titleValue = $field('title', $page['title'] ?? '');
$slugValue = $field('slug', $page['slug'] ?? '');
$parentValue = $field('parent_id', $page['parent_id'] ?? '');
$statusValue = $field('status', $page['status'] ?? 'draft');
$formatValue = $field('content_format', $page['content_format'] ?? 'visual');
$markdownValue = $field('content_markdown', $page['content_markdown'] ?? '');
$trustedVisual = !array_key_exists('content_html', $old);
$visualValue = $field('content_html', $page['content_html'] ?? '<p></p>');
$changeSummary = $field('change_summary', '');
$tagValue = $field('tags', isset($tags) ? implode(', ', array_column($tags, 'name')) : '');

$children = [];
foreach ($pages as $candidate) {
    $parentKey = $candidate['parent_id'] === null ? 0 : (int) $candidate['parent_id'];
    $children[$parentKey][] = $candidate;
}

$blocked = [];
if ($isEdit) {
    $collectBlocked = function (int $id) use (&$collectBlocked, &$blocked, $children): void {
        $blocked[$id] = true;
        foreach ($children[$id] ?? [] as $child) {
            $collectBlocked((int) $child['id']);
        }
    };
    $collectBlocked((int) $page['id']);
}

$options = [];
$walk = function (int $parentId, int $depth) use (&$walk, &$options, $children, $blocked): void {
    foreach ($children[$parentId] ?? [] as $candidate) {
        if (isset($blocked[(int) $candidate['id']])) {
            continue;
        }
        $candidate['_depth'] = $depth;
        $options[] = $candidate;
        $walk((int) $candidate['id'], $depth + 1);
    }
};
$walk(0, 0);
?>
<section
    class="editor-shell"
    data-wiki-editor
    data-is-edit="<?= $isEdit ? '1' : '0' ?>"
    <?php if ($isEdit): ?>
        data-lock-url="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/edit-lock"
        data-unlock-url="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/edit-unlock"
        data-autosave-url="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/autosave"
    <?php endif; ?>
>
    <div class="editor-heading">
        <div>
            <div class="breadcrumbs">
                <a href="/spaces/<?= rawurlencode($space['space_key']) ?>"><?= $e($space['name']) ?></a>
                <span>/</span>
                <span><?= $isEdit ? 'Edit page' : 'Create page' ?></span>
            </div>
            <h1><?= $isEdit ? 'Edit page' : 'Create page' ?></h1>
        </div>
        <?php if ($isEdit): ?>
            <a class="button button--ghost" href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>">Cancel</a>
        <?php else: ?>
            <a class="button button--ghost" href="/spaces/<?= rawurlencode($space['space_key']) ?>">Cancel</a>
        <?php endif; ?>
    </div>

    <?php if (!empty($formError)): ?>
        <div class="alert alert--error" role="alert"><?= $e($formError) ?></div>
    <?php endif; ?>

    <div class="alert alert--warning" data-edit-lock-warning role="alert" hidden></div>
    <div class="alert alert--warning editor-recovery" data-recovery-banner hidden>
        <span data-recovery-text>Recovered content is available.</span>
        <div class="recovery-actions">
            <button class="button button--secondary" type="button" data-restore-recovery>Restore recovered content</button>
            <button class="button button--ghost" type="button" data-hide-recovery>Ignore</button>
        </div>
    </div>
    <?php if ($serverDraft !== null): ?>
        <div hidden data-server-draft data-format="<?= $e($serverDraft['content_format']) ?>" data-saved-at="<?= $e($serverDraft['updated_at']) ?>" data-base-version="<?= (int) $serverDraft['base_version'] ?>">
            <textarea data-server-draft-html><?= $e($serverDraft['content_html']) ?></textarea>
            <textarea data-server-draft-markdown><?= $e($serverDraft['content_markdown'] ?? '') ?></textarea>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= $e($formAction) ?>" class="editor-form" data-editor-form>
        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
        <?php if ($isEdit): ?>
            <input type="hidden" name="base_version" value="<?= (int) $page['version'] ?>">
        <?php endif; ?>
        <input type="hidden" name="content_format" value="<?= $e($formatValue) ?>" data-content-format>

        <div class="editor-main card">
            <div class="editor-fields">
                <label class="sr-only" for="page-title">Title</label>
                <input id="page-title" class="title-input" name="title" required maxlength="255" placeholder="Page title" value="<?= $e($titleValue) ?>">

                <div class="editor-meta-grid">
                    <label>Slug
                        <input name="slug" maxlength="240" value="<?= $e($slugValue) ?>" placeholder="generated-from-title">
                    </label>
                    <label>Parent page
                        <select name="parent_id">
                            <option value="">No parent</option>
                            <?php foreach ($options as $option): ?>
                                <option value="<?= (int) $option['id'] ?>" <?= (string) $parentValue === (string) $option['id'] ? 'selected' : '' ?>>
                                    <?= $e(str_repeat('— ', (int) $option['_depth']) . $option['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Status
                        <select name="status">
                            <option value="draft" <?= $statusValue === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <?php if ($canPublish || $statusValue === 'published'): ?>
                                <option value="published" <?= $statusValue === 'published' ? 'selected' : '' ?>>Published</option>
                            <?php endif; ?>
                            <?php if ($app->auth()->can('page.archive') || $statusValue === 'archived'): ?>
                                <option value="archived" <?= $statusValue === 'archived' ? 'selected' : '' ?>>Archived</option>
                            <?php endif; ?>
                        </select>
                    </label>
                </div>

                <label>Tags
                    <input name="tags" maxlength="1500" value="<?= $e($tagValue) ?>" placeholder="linux, network, procedure">
                    <span class="field-help">Separate tags with commas. Maximum 30 tags.</span>
                </label>

                <?php if (!$isEdit && $templates !== []): ?>
                    <label>Start from template
                        <select data-template-selector>
                            <option value="">Blank content</option>
                            <?php foreach ($templates as $template): ?>
                                <option
                                    value="<?= (int) $template['id'] ?>"
                                    data-html="<?= $e($template['content_html']) ?>"
                                    data-markdown="<?= $e($template['content_markdown'] ?? '') ?>"
                                ><?= $e($template['name']) ?> — <?= $e($template['description'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
            </div>

            <div class="editor-tabs" role="tablist" aria-label="Editor mode">
                <button type="button" class="editor-tab" data-editor-mode="visual">Visual</button>
                <button type="button" class="editor-tab" data-editor-mode="markdown">Markdown</button>
            </div>

            <div class="editor-pane" data-pane="visual">
                <div class="editor-toolbar" aria-label="Formatting">
                    <button type="button" data-command="bold"><strong>B</strong></button>
                    <button type="button" data-command="italic"><em>I</em></button>
                    <button type="button" data-command="underline"><u>U</u></button>
                    <button type="button" data-command="strikeThrough"><s>S</s></button>
                    <button type="button" data-command="formatBlock" data-command-value="h2">H2</button>
                    <button type="button" data-command="formatBlock" data-command-value="blockquote">Quote</button>
                    <button type="button" data-command="insertUnorderedList">• List</button>
                    <button type="button" data-command="insertOrderedList">1. List</button>
                </div>
                <div class="visual-editor" contenteditable="true" data-visual-editor role="textbox" aria-multiline="true"><?php
                    if ($trustedVisual) {
                        echo $visualValue;
                    } else {
                        echo $e($visualValue);
                    }
                ?></div>
                <textarea name="content_html" class="hidden-field" data-html-field><?= $e($visualValue) ?></textarea>
            </div>

            <div class="editor-pane" data-pane="markdown">
                <textarea name="content_markdown" class="markdown-editor" rows="24" data-markdown-editor spellcheck="false"><?= $e($markdownValue) ?></textarea>
                <p class="field-help">Supports headings, lists, blockquotes, links, emphasis, inline code, fenced code blocks and wiki links such as [[Linux]].</p>
            </div>
        </div>

        <aside class="editor-side card">
            <h2>Save</h2>
            <label>Change summary
                <textarea name="change_summary" rows="3" maxlength="500" placeholder="Describe this change"><?= $e($changeSummary) ?></textarea>
            </label>
            <?php if ($isEdit): ?>
                <p class="field-help">Saving creates revision <?= (int) $page['version'] + 1 ?>. Concurrent changes are protected by optimistic locking.</p>
            <?php else: ?>
                <p class="field-help">The initial save creates revision 1.</p>
            <?php endif; ?>
            <details class="editor-macro-help">
                <summary>Macros</summary>
                <p class="field-help">Macros are resolved when the page is viewed and are not written into revisions as generated HTML.</p>
                <code>{{toc}}</code>
                <code>{{child-pages}}</code>
                <code>{{page-properties}}</code>
                <code>{{attachments}}</code>
                <code>{{recent-updates}}</code>
                <code>{{user-profile:username}}</code>
                <code>{{status:In progress}}</code>
                <code>{{info:Information}}</code>
                <code>{{warning:Warning}}</code>
                <code>{{note:Note}}</code>
                <code>{{code:echo "hello";}}</code>
            </details>
            <div class="editor-save-state" data-save-state>Recovery copy enabled.</div>
            <button class="button button--primary button--block" type="submit" data-save-button><?= $isEdit ? 'Save page' : 'Create page' ?></button>
        </aside>
    </form>
</section>
