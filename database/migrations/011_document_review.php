<?php
declare(strict_types=1);

use OpenWiki\Database\MigrationStep;

$table = static fn (string $name, string $sql, int $columns): array => [
    'sql' => $sql,
    'check' => "SELECT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$name') = $columns AND EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$name' AND ENGINE = 'InnoDB')",
    'exists' => "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$name'",
];

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
        CONSTRAINT fk_review_actor FOREIGN KEY (actor_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 7),
    $table('page_revision_attachments', 'CREATE TABLE page_revision_attachments (
        page_id BIGINT UNSIGNED NOT NULL, revision_number INT UNSIGNED NOT NULL,
        attachment_id BIGINT UNSIGNED NOT NULL, version_number INT UNSIGNED NOT NULL, name VARCHAR(255) NOT NULL,
        PRIMARY KEY (page_id, revision_number, attachment_id),
        CONSTRAINT fk_review_file_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
        CONSTRAINT fk_review_file FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 5),
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
