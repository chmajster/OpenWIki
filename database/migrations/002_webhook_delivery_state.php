<?php

declare(strict_types=1);

return [
    'version' => '002_webhook_delivery_state',
    'up' => [
        <<<'SQL'
ALTER TABLE webhook_deliveries
    ADD COLUMN failed_at DATETIME NULL AFTER delivered_at,
    ADD COLUMN last_error VARCHAR(1000) NULL AFTER response_body,
    ADD KEY idx_webhook_delivery_state (delivered_at, failed_at, next_attempt_at)
SQL
    ],
];
