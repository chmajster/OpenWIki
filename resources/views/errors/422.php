<section class="narrow-container">
    <div class="page-heading"><div><h1><?= $e($t('Invalid filters')) ?></h1><p class="muted"><?= $e($t('Check the filters and try again.')) ?></p></div></div>
    <div class="alert alert--error" role="alert"><?= $e($t($message)) ?></div>
    <a class="button button--secondary" href="<?= $e($resetUrl ?? '/?view=work') ?>"><?= $e($t('Reset filters')) ?></a>
</section>
