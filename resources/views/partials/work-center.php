<?php
$work = $workCenter; $filters = $work['filters'];
$typeLabels = ['task'=>'Tasks','review'=>'Review and publication','freshness'=>'Freshness reviews','discussion'=>'Discussions','draft'=>'Private drafts','ownership'=>'Missing content owner'];
$statusLabels = ['active'=>'Active','all'=>'All','todo'=>'To do','in_progress'=>'In progress','blocked'=>'Blocked','done'=>'Done','cancelled'=>'Cancelled','open'=>'Open thread','resolved'=>'Resolved','in_review'=>'In review','approved'=>'Approved','draft'=>'Draft','review_due'=>'Review due','no_owner'=>'Missing content owner'];
$workLink = static function(int $number) use($filters):string { $query=$filters; $query['page']=$number; $query['attention']=$filters['attention']?'1':'0'; return '/?'.http_build_query($query); };
?>
<section class="card panel work-center" data-work-center>
    <div class="panel__header"><h2><?= $e($t($filters['attention'] ? 'Documents needing attention' : 'Your work')) ?></h2><span class="count"><?= $work['meta']['total'] ?></span></div>
    <form method="get" action="/" class="work-filters">
        <?php if ($filters['attention']): ?><input type="hidden" name="attention" value="1"><?php endif; ?>
        <label><?= $e($t('Space')) ?><select name="space"><option value=""><?= $e($t('All spaces')) ?></option><?php if($filters['space'] !== null && !in_array($filters['space'], array_map('intval', array_column($work['spaces'], 'id')), true)): ?><option value="<?= (int)$filters['space'] ?>" selected><?= $e($t('Selected space')) ?></option><?php endif; ?><?php foreach($work['spaces'] as $option): ?><option value="<?= (int)$option['id'] ?>" <?= $filters['space']===(int)$option['id']?'selected':'' ?>><?= $e($option['name']) ?></option><?php endforeach; ?></select></label>
        <label><?= $e($t('Action type')) ?><select name="type"><option value="all"><?= $e($t('All')) ?></option><?php foreach($typeLabels as $value=>$label): ?><option value="<?= $value ?>" <?= $filters['type']===$value?'selected':'' ?>><?= $e($t($label)) ?></option><?php endforeach; ?></select></label>
        <label><?= $e($t('Status')) ?><select name="status"><?php foreach($statusLabels as $value=>$label): ?><option value="<?= $value ?>" <?= $filters['status']===$value?'selected':'' ?>><?= $e($t($label)) ?></option><?php endforeach; ?></select></label>
        <label><?= $e($t('Due date')) ?><select name="deadline"><?php foreach(['all'=>'Any deadline','overdue'=>'Overdue','today'=>'Today','week'=>'Next seven days','none'=>'No due date'] as $value=>$label): ?><option value="<?= $value ?>" <?= $filters['deadline']===$value?'selected':'' ?>><?= $e($t($label)) ?></option><?php endforeach; ?></select></label>
        <label><?= $e($t('Sort')) ?><select name="sort"><?php foreach(['priority'=>'Action priority','due'=>'Due date','updated'=>'Recently updated','title'=>'Title'] as $value=>$label): ?><option value="<?= $value ?>" <?= $filters['sort']===$value?'selected':'' ?>><?= $e($t($label)) ?></option><?php endforeach; ?></select></label>
        <button class="button" type="submit"><?= $e($t('Apply filters')) ?></button><a class="button button--ghost" href="/<?= $filters['attention']?'?attention=1&space='.(int)$filters['space']:'' ?>"><?= $e($t('Reset')) ?></a>
    </form>
    <?php if($work['data']===[]): ?><p role="status"><?= $e($t('No work matches these filters.')) ?></p><?php else: ?>
    <div class="table-scroll"><table><thead><tr><th><?= $e($t('Action type')) ?></th><th><?= $e($t('Document')) ?></th><th><?= $e($t('Space')) ?></th><th><?= $e($t('Status')) ?></th><th><?= $e($t('Due date')) ?></th><th><?= $e($t('Action')) ?></th></tr></thead><tbody>
    <?php foreach($work['data'] as $item): ?><tr data-work-item="<?= $e($item['item_key']) ?>">
        <td><?= $e($t($typeLabels[$item['type']])) ?></td><td><a href="<?= $e($item['url']) ?>"><?= $e($item['title']) ?></a><?php if($item['type']==='task'): ?><small class="work-document"><?= $e($item['document_title']) ?></small><?php endif; ?><?php if($item['working']): ?><small class="work-document"><?= $e($t('Working content')) ?></small><?php endif; ?></td>
        <td><?= $e($item['space_name']) ?></td><td><span class="status-pill"><?= $e($t($statusLabels[$item['status']]??$item['status'])) ?></span></td><td><?= $e($item['due_date']??'—') ?></td><td><a class="button button--ghost" href="<?= $e($item['url']) ?>"><?= $e($t($item['action'])) ?></a></td>
    </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    <nav class="collaboration-actions" aria-label="<?= $e($t('Work pages')) ?>"><?php if($work['meta']['page']>1): ?><a class="button" href="<?= $e($workLink($work['meta']['page']-1)) ?>"><?= $e($t('Previous')) ?></a><?php endif; ?><span><?= $work['meta']['page'] ?> · <?= $work['meta']['total'] ?></span><?php if($work['meta']['page']*$work['meta']['per_page']<$work['meta']['total']): ?><a class="button" href="<?= $e($workLink($work['meta']['page']+1)) ?>"><?= $e($t('Next')) ?></a><?php endif; ?></nav>
</section>
