<?php
declare(strict_types=1);

use OpenWiki\Database\MigrationStep;

$index = static fn (string $table, string $name, string $columns, bool $unique = false): string => "(SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND INDEX_NAME = '$name' AND NON_UNIQUE = " . ($unique ? 0 : 1) . ") = '$columns'";
$foreignKey = static fn (string $table, string $column, string $target, string $rule): string => "EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE k INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='$table' AND k.COLUMN_NAME='$column' AND k.REFERENCED_TABLE_NAME='$target' AND k.REFERENCED_COLUMN_NAME='id' AND r.DELETE_RULE='$rule')";
$table = static function (string $name, array $columns, array $keys): array {
    $checks = ["(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name') = " . count($columns),
        "EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name' AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_unicode_ci')"];
    foreach ($columns as $column => $definition) {
        $checks[] = '(' . MigrationStep::column($name, $column, $definition)['check'] . ') = 1';
        if (str_contains($definition, 'AUTO_INCREMENT')) $checks[] = "EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name' AND COLUMN_NAME='$column' AND EXTRA='auto_increment' AND COLUMN_KEY='PRI')";
    }
    foreach ($keys as [, $condition]) $checks[] = $condition;
    return ['sql' => 'CREATE TABLE `' . $name . '` (' . implode(', ', array_map(static fn ($column, $definition) => '`' . $column . '` ' . $definition, array_keys($columns), $columns)) . ', ' . implode(', ', array_column($keys, 0)) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'check' => 'SELECT ' . implode(' AND ', $checks),
        'exists' => "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name'"];
};

return ['version' => '015_space_news_reactions', 'up' => [
    $table('space_news', [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
        'space_id' => 'BIGINT UNSIGNED NOT NULL',
        'author_id' => 'BIGINT UNSIGNED NOT NULL',
        'title' => 'VARCHAR(200) NOT NULL',
        'body' => 'TEXT NOT NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'deleted_at' => 'DATETIME NULL',
    ], [
        'space_feed' => ['INDEX idx_space_news_feed (space_id, deleted_at, created_at, id)', $index('space_news', 'idx_space_news_feed', 'space_id,deleted_at,created_at,id')],
        'author' => ['INDEX idx_space_news_author (author_id, created_at)', $index('space_news', 'idx_space_news_author', 'author_id,created_at')],
        'space_fk' => ['CONSTRAINT fk_space_news_space FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE', $foreignKey('space_news', 'space_id', 'spaces', 'CASCADE')],
        'author_fk' => ['CONSTRAINT fk_space_news_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT', $foreignKey('space_news', 'author_id', 'users', 'RESTRICT')],
    ]),
    $table('page_reactions', [
        'page_id' => 'BIGINT UNSIGNED NOT NULL',
        'user_id' => 'BIGINT UNSIGNED NOT NULL',
        'emoji' => 'VARCHAR(16) NOT NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ], [
        'primary' => ['PRIMARY KEY (page_id, user_id)', $index('page_reactions', 'PRIMARY', 'page_id,user_id', true)],
        'emoji' => ['INDEX idx_page_reactions_emoji (page_id, emoji)', $index('page_reactions', 'idx_page_reactions_emoji', 'page_id,emoji')],
        'popular' => ['INDEX idx_page_reactions_created (created_at)', $index('page_reactions', 'idx_page_reactions_created', 'created_at')],
        'page_fk' => ['CONSTRAINT fk_page_reactions_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE', $foreignKey('page_reactions', 'page_id', 'pages', 'CASCADE')],
        'user_fk' => ['CONSTRAINT fk_page_reactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', $foreignKey('page_reactions', 'user_id', 'users', 'CASCADE')],
    ]),
    $table('comment_reactions', [
        'comment_id' => 'BIGINT UNSIGNED NOT NULL',
        'user_id' => 'BIGINT UNSIGNED NOT NULL',
        'emoji' => 'VARCHAR(16) NOT NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ], [
        'primary' => ['PRIMARY KEY (comment_id, user_id)', $index('comment_reactions', 'PRIMARY', 'comment_id,user_id', true)],
        'emoji' => ['INDEX idx_comment_reactions_emoji (comment_id, emoji)', $index('comment_reactions', 'idx_comment_reactions_emoji', 'comment_id,emoji')],
        'comment_fk' => ['CONSTRAINT fk_comment_reactions_comment FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE', $foreignKey('comment_reactions', 'comment_id', 'comments', 'CASCADE')],
        'user_fk' => ['CONSTRAINT fk_comment_reactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', $foreignKey('comment_reactions', 'user_id', 'users', 'CASCADE')],
    ]),
]];
