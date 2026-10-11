<section class="error-page">
    <div class="card error-card">
        <div class="error-code">419</div>
        <h1><?= $e($t('Session expired')) ?></h1>
        <p><?= $e($t('The form security token is no longer valid. Reload the form and submit it again.')) ?></p>
        <a class="button button--primary" href="/"><?= $e($t('Return to dashboard')) ?></a>
    </div>
</section>
