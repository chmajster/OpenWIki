<?php
$editorIcon = static function (string $name): string {
    $paths = [
        'back' => 'm14 5-7 7 7 7M7 12h13', 'panel' => 'M3 4h18v16H3zM8 4v16',
        'preview' => 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7zm10-3a3 3 0 1 0 0 6 3 3 0 0 0 0-6',
        'document' => 'M6 3h8l4 4v14H6zM14 3v5h4M9 12h6M9 16h6',
        'table' => 'M3 4h18v16H3zM3 9h18M3 14h18M9 4v16M15 4v16',
        'link' => 'm9 15 6-6M8 14l-2 2a4 4 0 0 1-6-6l5-5a4 4 0 0 1 6 0m2 5 2-2a4 4 0 0 1 6 6l-5 5a4 4 0 0 1-6 0',
        'code' => 'm8 6-6 6 6 6m8-12 6 6-6 6M14 3l-4 18',
        'image' => 'M3 4h18v16H3zM3 16l6-6 5 5 3-3 4 4M17 8h.01',
        'macro' => 'm12 2 3 7 7 3-7 3-3 7-3-7-7-3 7-3z',
        'undo' => 'M7 5 2 10l5 5M2 10h12a6 6 0 0 1 0 12', 'redo' => 'm17 5 5 5-5 5M22 10H10a6 6 0 0 0 0 12',
        'list' => 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
        'check' => 'M3 4h18v16H3zM7 12l3 3 7-7', 'callout' => 'M3 4h18v16H3zM12 10v6M12 7h.01',
        'close' => 'm6 6 12 12M6 18 18 6', 'plus' => 'M12 4v16M4 12h16',
    ];
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . ($paths[$name] ?? $paths['document']) . '"/></svg>';
};
