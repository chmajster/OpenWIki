<section class="error-page">
    <div class="card error-card">
        <div class="error-code">404</div>
        <h1><?= $e($t('Page not found')) ?></h1>
        <p><?= $e($t('The requested resource does not exist or is no longer available.')) ?></p>
        <a class="button button--primary" href="/"><?= $e($t('Return to dashboard')) ?></a>
    </div>
</section>
