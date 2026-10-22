<?php
$collaborationRevision = (int) $collaborationSource['version'];
$collaborationText = \OpenWiki\Wiki\CommentAnchor::text($collaborationSource['content_html']);
$collaborationMutable = $space['status'] === 'active' && $page['status'] !== 'archived';
$collaborationConfig = ['page' => (int) $page['id'], 'revision' => $collaborationRevision, 'user' => (int) $currentUser['id'], 'token' => $csrfToken,
    'text' => $collaborationText, 'hash' => hash('sha256', $collaborationText), 'selector' => $collaborationSelector, 'timezone' => date_default_timezone_get(), 'locale' => $locale,
    'task_revision' => $collaborationSelector === '[data-document-content]' ? null : $collaborationRevision,
    'can_comment' => $collaborationMutable && $app->auth()->can('comment.create'), 'can_moderate' => $collaborationMutable && $app->auth()->can('comment.delete'),
    'can_create_task' => $collaborationMutable && $app->auth()->can('task.create')];
?>
<section class="collaboration" data-collaboration data-config="<?= $e(json_encode($collaborationConfig, JSON_THROW_ON_ERROR)) ?>">
    <div class="collaboration-actions collaboration-toolbar">
        <button type="button" class="button button--ghost" data-discussions-open><?= $e($t('Discussions')) ?></button>
        <?php if ($collaborationConfig['can_comment']): ?><button type="button" class="button" data-comment-selection disabled><?= $e($t('Add comment to selection')) ?></button><?php endif; ?>
        <?php if ($collaborationConfig['can_create_task']): ?><button type="button" class="button button--ghost" data-task-create><?= $e($t('Create task')) ?></button><?php endif; ?>
    </div>
    <section class="card panel collaboration-tasks" aria-labelledby="page-tasks-title" id="tasks">
        <div class="panel__header"><h2 id="page-tasks-title"><?= $e($t('Page tasks')) ?></h2><span class="count" data-tasks-count></span></div>
        <p data-tasks-feedback role="status" aria-live="polite"><?= $e($t('Loading…')) ?></p><div data-tasks-list></div><nav data-tasks-pagination aria-label="<?= $e($t('Task pages')) ?>"></nav>
    </section>
    <dialog class="collaboration-panel" data-discussions-panel aria-labelledby="discussions-title">
        <header class="panel__header"><h2 id="discussions-title"><?= $e($t('Discussions')) ?></h2><button type="button" class="button button--ghost" data-panel-close aria-label="<?= $e($t('Close')) ?>">×</button></header>
        <label><?= $e($t('Status')) ?><select data-discussion-filter><option value="all"><?= $e($t('All')) ?></option><option value="open"><?= $e($t('Open thread')) ?></option><option value="resolved"><?= $e($t('Resolved')) ?></option></select></label>
        <button type="button" class="button button--ghost" data-discussions-all><?= $e($t('All discussions')) ?></button><p data-discussions-feedback role="status" aria-live="polite"></p><div data-discussions-list></div><nav data-discussion-pagination aria-label="<?= $e($t('Discussion pages')) ?>"></nav>
        <form data-comment-form hidden>
            <h3 data-comment-form-title><?= $e($t('New discussion')) ?></h3><blockquote data-comment-quote></blockquote><small><?= $e($t('Source revision')) ?> <span data-comment-source-revision><?= $collaborationRevision ?></span></small>
            <label><?= $e($t('Message')) ?><textarea name="body" rows="4" maxlength="10000" required></textarea></label>
            <p data-comment-feedback role="status" aria-live="polite"></p><div class="collaboration-actions"><button class="button button--primary" type="submit"><?= $e($t('Save comment')) ?></button><button class="button button--ghost" type="button" data-comment-cancel><?= $e($t('Cancel')) ?></button></div>
        </form>
    </dialog>
    <dialog class="collaboration-task-dialog" data-task-dialog aria-labelledby="task-dialog-title">
        <header class="panel__header"><h2 id="task-dialog-title"><?= $e($t('Page task')) ?></h2><button type="button" class="button button--ghost" data-task-close aria-label="<?= $e($t('Close')) ?>">×</button></header>
        <form data-task-form>
            <label><?= $e($t('Title')) ?><input name="title" maxlength="255" required></label>
            <label><?= $e($t('Status')) ?><select name="status"><?php foreach (['todo'=>'To do','in_progress'=>'In progress','blocked'=>'Blocked','done'=>'Done','cancelled'=>'Cancelled'] as $value=>$label): ?><option value="<?= $value ?>"><?= $e($t($label)) ?></option><?php endforeach; ?></select></label>
            <details data-task-details><summary><?= $e($t('Task details')) ?></summary>
                <label><?= $e($t('Description')) ?><textarea name="description" maxlength="10000" rows="3"></textarea></label>
                <label><?= $e($t('Find assignee')) ?><input type="search" data-assignee-query autocomplete="off"></label>
                <label><?= $e($t('Assignee')) ?><select name="assignee_id"><option value=""><?= $e($t('Unassigned')) ?></option></select></label>
                <label><?= $e($t('Due date')) ?><input name="due_date" type="date"></label>
                <p data-task-source></p>
            </details>
            <p data-task-feedback role="status" aria-live="polite"></p><div class="collaboration-actions"><button class="button button--primary" type="submit" data-task-save><?= $e($t('Save task')) ?></button><button class="button button--ghost" type="button" data-task-reload hidden><?= $e($t('Load current version')) ?></button></div>
        </form>
        <details data-task-history-section hidden><summary><?= $e($t('Task history')) ?></summary><div data-task-history></div><nav data-history-pagination></nav></details>
    </dialog>
</section>
