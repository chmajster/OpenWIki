<section class="error-page">
    <div class="card error-card">
        <div class="error-code">403</div>
        <h1><?= $e($t('Permission denied')) ?></h1>
        <p><?= $e($t('You do not have permission to access this resource.')) ?></p>
        <a class="button button--primary" href="/"><?= $e($t('Return to dashboard')) ?></a>
    </div>
</section>
