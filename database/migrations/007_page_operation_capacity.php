<?php

declare(strict_types=1);

return [
    'version' => '007_page_operation_capacity',
    'up' => ['ALTER TABLE editor_page_operations MODIFY result_json LONGTEXT NOT NULL'],
];
