<?php

declare(strict_types=1);

use OpenWiki\Database\MigrationStep;

return [
    'version' => '002_webhook_delivery_state',
    'up' => [
        MigrationStep::column('webhook_deliveries', 'failed_at', 'DATETIME NULL AFTER delivered_at'),
        MigrationStep::column('webhook_deliveries', 'last_error', 'VARCHAR(1000) NULL AFTER response_body'),
        MigrationStep::index('webhook_deliveries', 'idx_webhook_delivery_state', ['delivered_at', 'failed_at', 'next_attempt_at']),
    ],
];
