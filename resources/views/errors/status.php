<?php
$errorStatus = (int) ($errorStatus ?? 500);
$errorDefinition = \OpenWiki\Core\HttpErrorResponse::definition($errorStatus);
$errorTitle = $title ?? $errorDefinition['title'];
$errorMessage = $message ?? $errorDefinition['message'];
$errorHelp = $errorDefinition['help'];
?>
<section class="error-page" aria-labelledby="http-error-title">
    <article class="card error-card">
        <p class="error-kicker">OpenWiki <span aria-hidden="true">·</span> HTTP <?= $errorStatus ?></p>
        <div class="error-code" aria-hidden="true"><?= $errorStatus ?></div>
        <h1 id="http-error-title"><?= $e($t($errorTitle)) ?></h1>
        <p class="error-message" role="status"><?= $e($t($errorMessage)) ?></p>
        <div class="error-help"><strong><?= $e($t('What you can do')) ?></strong><p><?= $e($t($errorHelp)) ?></p></div>
        <?php if ($errorStatus === 405 && isset($allowedMethods)): ?>
            <p class="error-methods"><span><?= $e($t('Allowed methods')) ?></span><code><?= $e(implode(', ', $allowedMethods)) ?></code></p>
        <?php endif; ?>
        <nav class="error-actions" aria-label="<?= $e($t('Error recovery')) ?>">
            <a class="button button--primary" href="<?= $e($errorDefinition['primaryUrl']) ?>"><?= $e($t($errorDefinition['primaryLabel'])) ?></a>
            <?php if ($errorDefinition['secondaryLabel'] !== null && $errorDefinition['secondaryUrl'] !== null): ?>
                <a class="button button--secondary" href="<?= $e($errorDefinition['secondaryUrl']) ?>"><?= $e($t($errorDefinition['secondaryLabel'])) ?></a>
            <?php endif; ?>
        </nav>
    </article>
</section>
