<?php
/** Decorative icons; the surrounding control supplies the accessible name. */
$uiIcon = static function (string $name): string {
    $paths = [
        'book' => '<path d="M12 6c-3-2-6-2-9-1v15c3-1 6-1 9 1 3-2 6-2 9-1V5c-3-1-6-1-9 1Z"/><path d="M12 6v15"/>',
        'home' => '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'star' => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9Z"/>',
        'check' => '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="m8 12 3 3 5-6"/>',
        'spaces' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'list' => '<path d="M9 5h12M9 12h12M9 19h12M3 5h1M3 12h1M3 19h1"/>',
        'document' => '<path d="M14 3H5v18h14V8Z"/><path d="M14 3v5h5M8 12h8M8 16h6"/>',
        'message' => '<path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5 8.7 8.7 0 0 1-4-.98L3 21l1.98-5.5a8.7 8.7 0 0 1-.98-4A8.5 8.5 0 0 1 12.5 3h.5a8.5 8.5 0 0 1 8 8v.5Z"/><path d="M8 12h.01M12 12h.01M16 12h.01"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
        'panel' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'bell' => '<path d="M5 17h14l-2-3V9a5 5 0 0 0-10 0v5Zm5 3h4"/>',
        'trash' => '<path d="M3 6h18M8 6V4h8v2m3 0-1 15H6L5 6m4 4v7m6-7v7"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.7a7.9 7.9 0 0 1-1.5.9l-.3 1.8h-2.8l-.3-1.8a7.9 7.9 0 0 1-1.5-.9l-1.7.7-1.4-2.4 1.4-1.1a7.9 7.9 0 0 1 0-1.8l-1.4-1.1 1.4-2.4 1.7.7a7.9 7.9 0 0 1 1.5-.9l.3-1.8h2.8l.3 1.8a7.9 7.9 0 0 1 1.5.9l1.7-.7 1.4 2.4-1.4 1.1a7.9 7.9 0 0 1 0 1.8Z"/>',
        'eye' => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
        'chevron' => '<path d="m9 5 7 7-7 7"/>',
        'edit' => '<path d="m15 4 5 5-11 11H4v-5ZM12 7l5 5"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.1 0l2-2a5 5 0 0 0-7.1-7.1l-1.2 1.2M14 11a5 5 0 0 0-7.1 0l-2 2a5 5 0 0 0 7.1 7.1l1.2-1.2"/>',
        'arrow' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'megaphone' => '<path d="m3 11 18-5v12L3 13v-2Z"/><path d="M11.6 15.4 13 21l3-1-1.5-4.3M5 13.5V18h4"/>',
        'trending' => '<path d="m3 17 6-6 4 4 8-9M15 6h6v6"/>',
        'shield' => '<path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/>',
        'move' => '<rect x="4" y="4" width="10" height="10" rx="2"/><path d="M14 9h5m-2-2 2 2-2 2M10 14v5m-2-2 2 2 2-2"/>',
    ];
    return '<svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['document']) . '</svg>';
};
