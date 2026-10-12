<?php
declare(strict_types=1);
return ['version' => '010_structure_operations', 'up' => [
    ['sql' => 'CREATE TABLE structure_operations (
        user_id BIGINT UNSIGNED NOT NULL, operation_id CHAR(36) NOT NULL, request_hash CHAR(64) NOT NULL,
        result_json LONGTEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, operation_id), CONSTRAINT fk_structure_actor FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'check' => "SELECT
        (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'structure_operations' AND IS_NULLABLE = 'NO' AND (
            (COLUMN_NAME = 'user_id' AND DATA_TYPE = 'bigint' AND COLUMN_TYPE LIKE '%unsigned%') OR
            (COLUMN_NAME = 'operation_id' AND DATA_TYPE = 'char' AND CHARACTER_MAXIMUM_LENGTH = 36) OR
            (COLUMN_NAME = 'request_hash' AND DATA_TYPE = 'char' AND CHARACTER_MAXIMUM_LENGTH = 64) OR
            (COLUMN_NAME = 'result_json' AND DATA_TYPE = 'longtext') OR (COLUMN_NAME = 'created_at' AND DATA_TYPE = 'datetime'))) = 5
        AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'structure_operations' AND INDEX_NAME = 'PRIMARY' AND ((SEQ_IN_INDEX = 1 AND COLUMN_NAME = 'user_id') OR (SEQ_IN_INDEX = 2 AND COLUMN_NAME = 'operation_id'))) = 2
        AND EXISTS (SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'structure_operations' AND REFERENCED_TABLE_NAME = 'users' AND DELETE_RULE = 'CASCADE')",
    'exists' => "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'structure_operations'"],
]];
