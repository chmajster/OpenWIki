<details class="theme-menu" data-close-on-escape data-theme-menu hidden>
    <summary class="button button--ghost" aria-label="<?= $e($t('Color theme')) ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/></svg>
        <span><?= $e($t('Theme')) ?></span>
    </summary>
    <div class="menu-items">
        <label><?= $e($t('Color theme')) ?><select data-theme-select><option value="system"><?= $e($t('Follow system')) ?></option><option value="light"><?= $e($t('Light')) ?></option><option value="dark"><?= $e($t('Dark')) ?></option></select></label>
    </div>
</details>
