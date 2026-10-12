<?php

declare(strict_types=1);

return [
    'version' => '008_security_boundaries',
    'up' => [
    'CREATE TABLE IF NOT EXISTS security_locks (name VARCHAR(100) NOT NULL PRIMARY KEY) ENGINE=InnoDB',
    'INSERT IGNORE INTO security_locks (name) VALUES ("administration")',
    'SET @openwiki_trash_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "pages" AND COLUMN_NAME = "trash_batch_id"), "DO 0", "ALTER TABLE pages ADD COLUMN trash_batch_id CHAR(36) NULL")',
    'PREPARE openwiki_trash_migration FROM @openwiki_trash_ddl',
    'EXECUTE openwiki_trash_migration',
    'DEALLOCATE PREPARE openwiki_trash_migration',
    'SET @openwiki_trash_ddl = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "pages" AND INDEX_NAME = "idx_pages_trash_batch"), "DO 0", "ALTER TABLE pages ADD INDEX idx_pages_trash_batch (space_id, trash_batch_id)")',
    'PREPARE openwiki_trash_migration FROM @openwiki_trash_ddl',
    'EXECUTE openwiki_trash_migration',
    'DEALLOCATE PREPARE openwiki_trash_migration',
    ],
];
