<?php
declare(strict_types=1);

use OpenWiki\Database\MigrationStep;

// A completed CREATE is checked on resume; an incompatible existing table is never silently accepted.
$table = static function (string $name, array $columns, array $keys): array {
    $checks = ["(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$name') = " . count($columns),
        "EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$name' AND ENGINE = 'InnoDB' AND TABLE_COLLATION = 'utf8mb4_unicode_ci')"];
    foreach ($columns as $column => $definition) {
        $checks[] = '(' . MigrationStep::column($name, $column, $definition)['check'] . ') = 1';
        if (str_contains($definition, 'AUTO_INCREMENT')) $checks[] = "EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name' AND COLUMN_NAME='$column' AND EXTRA='auto_increment' AND COLUMN_KEY='PRI')";
    }
    foreach ($keys as $key => [$definition, $condition]) $checks[] = $condition;
    return ['sql' => 'CREATE TABLE `' . $name . '` (' . implode(', ', array_map(static fn ($column, $definition) => '`' . $column . '` ' . $definition, array_keys($columns), $columns)) . ', ' . implode(', ', array_column($keys, 0)) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'check' => 'SELECT ' . implode(' AND ', $checks),
        'exists' => "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$name'"];
};
$index = static fn (string $table, string $name, string $columns, bool $unique = false): string => "(SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND INDEX_NAME = '$name' AND NON_UNIQUE = " . ($unique ? 0 : 1) . ") = '$columns'";
$fk = static fn (string $table, string $column, string $target, string $rule): string => "EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE k INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = '$table' AND k.COLUMN_NAME = '$column' AND k.REFERENCED_TABLE_NAME = '$target' AND k.REFERENCED_COLUMN_NAME = 'id' AND r.DELETE_RULE = '$rule')";

return ['version' => '013_document_collaboration', 'up' => [
    MigrationStep::column('comments', 'thread_id', 'BIGINT UNSIGNED NULL'),
    MigrationStep::column('comments', 'revision_number', 'INT UNSIGNED NULL'),
    MigrationStep::column('comments', 'visibility', 'VARCHAR(20) NOT NULL DEFAULT "page"'),
    MigrationStep::column('comments', 'anchor_json', 'LONGTEXT NULL'),
    MigrationStep::column('comments', 'thread_status', 'VARCHAR(20) NOT NULL DEFAULT "open"'),
    MigrationStep::column('comments', 'version', 'INT UNSIGNED NOT NULL DEFAULT 1'),
    MigrationStep::index('comments', 'idx_comment_thread', ['page_id', 'thread_id', 'deleted_at']),
    MigrationStep::column('notifications', 'page_id', 'BIGINT UNSIGNED NULL'),
    MigrationStep::column('notifications', 'revision_number', 'INT UNSIGNED NULL'),
    MigrationStep::column('notifications', 'resource_type', 'VARCHAR(20) NULL'),
    MigrationStep::column('notifications', 'resource_id', 'BIGINT UNSIGNED NULL'),
    MigrationStep::column('notifications', 'working', 'TINYINT UNSIGNED NOT NULL DEFAULT 0'),
    MigrationStep::column('notifications', 'event_key', 'VARCHAR(150) NULL'),
    ['sql' => 'ALTER TABLE notifications ADD UNIQUE INDEX uq_notification_event (user_id, event_key)', 'check' => 'SELECT ' . $index('notifications', 'uq_notification_event', 'user_id,event_key', true), 'exists' => "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'uq_notification_event'"],
    $table('page_tasks', ['id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'page_id' => 'BIGINT UNSIGNED NOT NULL', 'thread_id' => 'BIGINT UNSIGNED NULL', 'revision_number' => 'INT UNSIGNED NULL', 'visibility' => 'VARCHAR(20) NOT NULL DEFAULT "page"',
        'title' => 'VARCHAR(255) NOT NULL', 'description' => 'TEXT NOT NULL', 'author_id' => 'BIGINT UNSIGNED NOT NULL', 'assignee_id' => 'BIGINT UNSIGNED NULL', 'due_date' => 'DATE NULL', 'status' => 'VARCHAR(20) NOT NULL DEFAULT "todo"', 'version' => 'INT UNSIGNED NOT NULL DEFAULT 1', 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'], [
        'page' => ['INDEX idx_task_page (page_id, status, id)', $index('page_tasks', 'idx_task_page', 'page_id,status,id')],
        'assignee' => ['INDEX idx_task_assignee (assignee_id, status, due_date, id)', $index('page_tasks', 'idx_task_assignee', 'assignee_id,status,due_date,id')],
        'page_fk' => ['CONSTRAINT fk_task_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE', $fk('page_tasks', 'page_id', 'pages', 'CASCADE')],
        'thread_fk' => ['CONSTRAINT fk_task_thread FOREIGN KEY (thread_id) REFERENCES comments(id) ON DELETE SET NULL', $fk('page_tasks', 'thread_id', 'comments', 'SET NULL')],
        'author_fk' => ['CONSTRAINT fk_task_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT', $fk('page_tasks', 'author_id', 'users', 'RESTRICT')],
        'assignee_fk' => ['CONSTRAINT fk_task_assignee FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL', $fk('page_tasks', 'assignee_id', 'users', 'SET NULL')],
    ]),
    $table('page_task_history', ['id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'task_id' => 'BIGINT UNSIGNED NOT NULL', 'actor_id' => 'BIGINT UNSIGNED NOT NULL', 'version' => 'INT UNSIGNED NOT NULL', 'changes_json' => 'TEXT NOT NULL', 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'], [
        'task' => ['INDEX idx_task_history (task_id, version)', $index('page_task_history', 'idx_task_history', 'task_id,version')],
        'task_fk' => ['CONSTRAINT fk_task_history FOREIGN KEY (task_id) REFERENCES page_tasks(id) ON DELETE CASCADE', $fk('page_task_history', 'task_id', 'page_tasks', 'CASCADE')],
        'actor_fk' => ['CONSTRAINT fk_task_history_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT', $fk('page_task_history', 'actor_id', 'users', 'RESTRICT')],
    ]),
    $table('collaboration_operations', ['user_id' => 'BIGINT UNSIGNED NOT NULL', 'operation_id' => 'CHAR(36) NOT NULL', 'kind' => 'VARCHAR(30) NOT NULL', 'input_hash' => 'CHAR(64) NOT NULL', 'resource_id' => 'BIGINT UNSIGNED NOT NULL', 'version' => 'INT UNSIGNED NOT NULL', 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'], [
        'primary' => ['PRIMARY KEY (user_id, operation_id)', $index('collaboration_operations', 'PRIMARY', 'user_id,operation_id', true)],
        'user_fk' => ['CONSTRAINT fk_collaboration_actor FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', $fk('collaboration_operations', 'user_id', 'users', 'CASCADE')],
    ]),
    'INSERT IGNORE INTO permissions (name) VALUES ("task.create"), ("task.manage"), ("task.update_own"), ("comment.resolve")',
    'INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.is_system = 1 AND ((r.slug IN ("admin", "space-admin") AND p.name IN ("task.create", "task.manage", "task.update_own", "comment.resolve")) OR (r.slug IN ("editor", "author", "reviewer", "publisher") AND p.name IN ("task.create", "task.update_own", "comment.create", "comment.resolve")) OR (r.slug = "viewer" AND p.name = "task.update_own"))',
]];
