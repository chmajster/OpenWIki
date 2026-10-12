<?php
declare(strict_types=1);

use OpenWiki\Database\MigrationStep;

$table = static function (string $name, string $sql, array $columns, string $primary, array $foreign): array {
    $where = "TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$name'";
    $matches = [];
    foreach ($columns as $column => $type) {
        $unsigned = in_array($type, ['bigint', 'int'], true) ? " AND COLUMN_TYPE LIKE '%unsigned%'" : '';
        $size = $column === 'action' ? ' AND CHARACTER_MAXIMUM_LENGTH = 20' : ($column === 'name' ? ' AND CHARACTER_MAXIMUM_LENGTH = 255' : '');
        $matches[] = "(COLUMN_NAME = '$column' AND DATA_TYPE = '$type' AND IS_NULLABLE = 'NO'$unsigned$size)";
    }
    $checks = ["(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE $where) = " . count($columns),
        "(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE $where AND (" . implode(' OR ', $matches) . ')) = ' . count($columns),
        "EXISTS (SELECT 1 FROM information_schema.TABLES WHERE $where AND ENGINE = 'InnoDB' AND TABLE_COLLATION = 'utf8mb4_unicode_ci')",
        "(SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE $where AND INDEX_NAME = 'PRIMARY' AND NON_UNIQUE = 0) = '$primary'"];
    foreach ($foreign as $column => [$target, $deletion]) $checks[] = "EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE k INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = '$name' AND k.COLUMN_NAME = '$column' AND k.REFERENCED_TABLE_NAME = '$target' AND k.REFERENCED_COLUMN_NAME = 'id' AND r.DELETE_RULE = '$deletion')";
    if ($name === 'page_review_decisions') {
        $checks[] = "EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE $where AND COLUMN_NAME = 'id' AND EXTRA LIKE '%auto_increment%')";
        $checks[] = "(SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE $where AND INDEX_NAME = 'idx_review_revision') = 'page_id,revision_number,id'";
    }
    return ['sql' => $sql, 'check' => 'SELECT ' . implode(' AND ', $checks), 'exists' => "SELECT COUNT(*) FROM information_schema.TABLES WHERE $where"];
};

return ['version' => '011_document_review', 'up' => [
    MigrationStep::column('spaces', 'review_workflow', 'TINYINT UNSIGNED NOT NULL DEFAULT 0'),
    MigrationStep::column('spaces', 'allow_self_review', 'TINYINT UNSIGNED NOT NULL DEFAULT 0'),
    MigrationStep::column('pages', 'published_revision', 'INT UNSIGNED NULL'),
    MigrationStep::column('pages', 'workflow_state', 'VARCHAR(20) NOT NULL DEFAULT "draft"'),
    MigrationStep::column('pages', 'content_owner_id', 'BIGINT UNSIGNED NULL'),
    MigrationStep::column('pages', 'review_due_at', 'DATE NULL'),
    MigrationStep::column('pages', 'verified_at', 'DATETIME NULL'),
    MigrationStep::column('pages', 'verified_revision', 'INT UNSIGNED NULL'),
    MigrationStep::column('page_revisions', 'was_published', 'TINYINT UNSIGNED NOT NULL DEFAULT 0'),
    MigrationStep::column('page_revisions', 'knowledge_json', 'LONGTEXT NULL'),
    $table('page_review_decisions', 'CREATE TABLE page_review_decisions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, page_id BIGINT UNSIGNED NOT NULL,
        revision_number INT UNSIGNED NOT NULL, action VARCHAR(20) NOT NULL,
        actor_id BIGINT UNSIGNED NOT NULL, comment TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_review_revision (page_id, revision_number, id),
        CONSTRAINT fk_review_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
        CONSTRAINT fk_review_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', ['id' => 'bigint', 'page_id' => 'bigint', 'revision_number' => 'int', 'action' => 'varchar', 'actor_id' => 'bigint', 'comment' => 'text', 'created_at' => 'datetime'], 'id', ['page_id' => ['pages', 'CASCADE'], 'actor_id' => ['users', 'RESTRICT']]),
    $table('page_revision_attachments', 'CREATE TABLE page_revision_attachments (
        page_id BIGINT UNSIGNED NOT NULL, revision_number INT UNSIGNED NOT NULL,
        attachment_id BIGINT UNSIGNED NOT NULL, version_number INT UNSIGNED NOT NULL, name VARCHAR(255) NOT NULL,
        PRIMARY KEY (page_id, revision_number, attachment_id),
        CONSTRAINT fk_review_file_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
        CONSTRAINT fk_review_file FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', ['page_id' => 'bigint', 'revision_number' => 'int', 'attachment_id' => 'bigint', 'version_number' => 'int', 'name' => 'varchar'], 'page_id,revision_number,attachment_id', ['page_id' => ['pages', 'CASCADE'], 'attachment_id' => ['attachments', 'CASCADE']]),
    'INSERT INTO page_revisions (page_id, revision_number, title, slug, parent_id, content_html, content_markdown, content_text, content_format, document_json, status, author_id, change_summary, created_at)
        SELECT p.id, p.version, p.title, p.slug, p.parent_id, p.content_html, p.content_markdown, p.content_text, p.content_format, p.document_json, p.status, p.author_id, "Migration: preserved published page snapshot", p.updated_at
        FROM pages p WHERE p.status = "published" AND NOT EXISTS (SELECT 1 FROM page_revisions r WHERE r.page_id = p.id AND r.revision_number = p.version)',
    'UPDATE page_revisions SET was_published = 1 WHERE status = "published"',
    'UPDATE pages SET published_revision = version, workflow_state = "published", updated_at = updated_at WHERE status = "published"',
    'UPDATE pages SET content_owner_id = owner_id, updated_at = updated_at',
    'UPDATE page_revisions r INNER JOIN pages p ON p.id = r.page_id SET r.knowledge_json = JSON_OBJECT("content_owner_id", p.content_owner_id, "review_due_at", NULL)',
    'INSERT IGNORE INTO page_revision_attachments (page_id, revision_number, attachment_id, version_number, name) SELECT a.page_id, p.published_revision, a.id, a.current_version, a.name FROM attachments a INNER JOIN pages p ON p.id = a.page_id WHERE p.published_revision IS NOT NULL AND a.deleted_at IS NULL',
    'INSERT IGNORE INTO permissions (name) VALUES ("page.review"), ("page.verify"), ("space.view"), ("page.view"), ("page.publish")',
    'INSERT IGNORE INTO roles (name, slug, description, is_system) VALUES ("Reviewer", "reviewer", "Review specific revisions; no editing or publication", 1), ("Publisher", "publisher", "Publish approved revisions; no editing or review", 1)',
    'INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.is_system = 1 AND ((r.slug IN ("admin", "space-admin") AND p.name IN ("page.review", "page.verify")) OR (r.slug IN ("author", "editor") AND p.name = "page.verify") OR (r.slug = "reviewer" AND p.name IN ("space.view", "page.view", "page.review", "page.verify")) OR (r.slug = "publisher" AND p.name IN ("space.view", "page.view", "page.publish")))',
]];
