<?php
declare(strict_types=1);
use OpenWiki\Database\MigrationStep;
return ['version' => '010_structure_operations', 'up' => [
    ['sql' => 'CREATE TABLE structure_operations (
        user_id BIGINT UNSIGNED NOT NULL, operation_id CHAR(36) NOT NULL, request_hash CHAR(64) NOT NULL,
        result_json LONGTEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, operation_id), CONSTRAINT fk_structure_actor FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'check' => "SELECT COUNT(*) = 5 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'structure_operations' AND COLUMN_NAME IN ('user_id', 'operation_id', 'request_hash', 'result_json', 'created_at')",
    'exists' => "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'structure_operations'"],
]];
