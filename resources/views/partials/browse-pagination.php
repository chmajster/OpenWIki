<?php if ($browseMeta['total_pages'] > 1):
$browseNumbers = array_values(array_unique([1, ...range(max(1, $browseMeta['page'] - 2), min($browseMeta['total_pages'], $browseMeta['page'] + 2)), $browseMeta['total_pages']]));
sort($browseNumbers);
?>
<nav class="browse-pagination" aria-label="<?= $e($t('Pagination')) ?>">
    <?php if ($browseMeta['page'] > 1): ?><a class="button button--secondary" rel="prev" href="<?= $e($browseUrl(['page' => $browseMeta['page'] - 1])) ?>"><?= $e($t('Previous')) ?></a><?php else: ?><span class="button button--ghost" aria-disabled="true"><?= $e($t('Previous')) ?></span><?php endif; ?>
    <div class="browse-page-numbers">
    <?php $previousNumber = 0; foreach ($browseNumbers as $number): ?>
        <?php if ($number > $previousNumber + 1): ?><span aria-hidden="true">…</span><?php endif; ?>
        <?php if ($number === $browseMeta['page']): ?><span class="button browse-current" aria-current="page" aria-label="<?= $e($t('Page')) ?> <?= $number ?>"><?= $number ?></span><?php else: ?><a class="button button--ghost" href="<?= $e($browseUrl(['page' => $number])) ?>" aria-label="<?= $e($t('Page')) ?> <?= $number ?>"><?= $number ?></a><?php endif; ?>
    <?php $previousNumber = $number; endforeach; ?>
    </div>
    <?php if ($browseMeta['page'] < $browseMeta['total_pages']): ?><a class="button button--secondary" rel="next" href="<?= $e($browseUrl(['page' => $browseMeta['page'] + 1])) ?>"><?= $e($t('Next')) ?></a><?php else: ?><span class="button button--ghost" aria-disabled="true"><?= $e($t('Next')) ?></span><?php endif; ?>
</nav>
<?php endif; ?>
