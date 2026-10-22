<details class="theme-menu" data-close-on-escape data-theme-menu hidden>
    <summary class="button button--ghost" aria-label="<?= $e($t('Color theme')) ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/></svg>
        <span><?= $e($t('Theme')) ?></span>
    </summary>
    <div class="menu-items theme-menu__panel">
        <header class="theme-menu__heading">
            <span class="theme-menu__heading-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M12 3a9 9 0 1 0 9 9c0-.5-.04-1-.12-1.48A7 7 0 0 1 13.5 3.1 9 9 0 0 0 12 3Z"/><path d="M16.5 4.5v4m-2-2h4"/></svg></span>
            <span class="theme-menu__heading-copy"><span class="theme-menu__eyebrow"><?= $e($t('Appearance')) ?></span><strong><?= $e($t('Color theme')) ?></strong><span><?= $e($t('Choose how OpenWiki looks on this device.')) ?></span></span>
        </header>
        <div class="theme-menu__choices" role="group" aria-label="<?= $e($t('Color theme')) ?>">
            <button class="theme-menu__choice" type="button" data-theme-choice="system" aria-pressed="false">
                <span class="theme-menu__choice-icon theme-menu__choice-icon--system" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8m-4-4v4M12 6v9m0-4.5h7"/></svg></span>
                <span class="theme-menu__choice-copy"><strong><?= $e($t('Follow system')) ?></strong><small><?= $e($t('Match your device settings')) ?></small></span>
                <span class="theme-menu__choice-check" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none"><path d="m3.5 8 3 3 6-6"/></svg></span>
            </button>
            <button class="theme-menu__choice" type="button" data-theme-choice="light" aria-pressed="false">
                <span class="theme-menu__choice-icon theme-menu__choice-icon--light" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="3.5"/><path d="M12 2.5v2m0 15v2M2.5 12h2m15 0h2M5.3 5.3l1.4 1.4m10.6 10.6 1.4 1.4m0-13.4-1.4 1.4M6.7 17.3l-1.4 1.4"/></svg></span>
                <span class="theme-menu__choice-copy"><strong><?= $e($t('Light')) ?></strong><small><?= $e($t('Always use the light theme')) ?></small></span>
                <span class="theme-menu__choice-check" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none"><path d="m3.5 8 3 3 6-6"/></svg></span>
            </button>
            <button class="theme-menu__choice" type="button" data-theme-choice="dark" aria-pressed="false">
                <span class="theme-menu__choice-icon theme-menu__choice-icon--dark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M19.5 15.2A8.4 8.4 0 0 1 8.8 4.5 8.8 8.8 0 1 0 19.5 15.2Z"/><path d="m17.5 3 .6 1.4 1.4.6-1.4.6-.6 1.4-.6-1.4-1.4-.6 1.4-.6.6-1.4Z"/></svg></span>
                <span class="theme-menu__choice-copy"><strong><?= $e($t('Dark')) ?></strong><small><?= $e($t('Always use the dark theme')) ?></small></span>
                <span class="theme-menu__choice-check" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none"><path d="m3.5 8 3 3 6-6"/></svg></span>
            </button>
        </div>
    </div>
</details>
