<?php require __DIR__ . '/ui-icons.php'; ?>
<section class="workspace-recovery" data-recovery-banner hidden aria-labelledby="recovery-heading" aria-describedby="recovery-description">
    <div class="workspace-recovery-heading">
        <span class="workspace-recovery-icon" aria-hidden="true"><?= $uiIcon('clock') ?></span>
        <div><h2 id="recovery-heading"><?= $e($t('Recovered content is available.')) ?></h2>
            <p id="recovery-description" data-recovery-text><?= $e($t('Choose a recovery copy. Restoring retains its original base version.')) ?></p>
        </div>
    </div>
    <label for="recovery-copy"><?= $e($t('Recovery copy')) ?></label>
    <select id="recovery-copy" data-recovery-select aria-describedby="recovery-copy-meta"></select>
    <p class="workspace-recovery-meta" id="recovery-copy-meta" data-recovery-meta aria-live="polite"></p>
    <div class="workspace-recovery-actions">
        <button class="button button--primary" type="button" data-restore-recovery><?= $e($t('Restore copy')) ?></button>
        <button class="button button--secondary" type="button" data-compare-recovery><?= $e($t('Compare copy')) ?></button>
        <button class="button button--ghost" type="button" data-hide-recovery><?= $e($t('Ignore')) ?></button>
    </div>
</section>
