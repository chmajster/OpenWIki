<?php

declare(strict_types=1);

return [
    'version' => '008_security_boundaries',
    'up' => [
    'CREATE TABLE IF NOT EXISTS security_locks (name VARCHAR(100) NOT NULL PRIMARY KEY) ENGINE=InnoDB',
    'INSERT IGNORE INTO security_locks (name) VALUES ("administration")',
    'ALTER TABLE pages ADD COLUMN trash_batch_id CHAR(36) NULL, ADD INDEX idx_pages_trash_batch (space_id, trash_batch_id)',
    ],
];
