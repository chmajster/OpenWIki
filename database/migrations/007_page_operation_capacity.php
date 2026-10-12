<?php

declare(strict_types=1);

return [
    'version' => '007_page_operation_capacity',
    'up' => [[
        'sql' => 'ALTER TABLE editor_page_operations MODIFY result_json LONGTEXT NOT NULL',
        'check' => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'editor_page_operations' AND COLUMN_NAME = 'result_json' AND DATA_TYPE = 'longtext' AND IS_NULLABLE = 'NO'",
        'repeatable' => true,
    ]],
];
