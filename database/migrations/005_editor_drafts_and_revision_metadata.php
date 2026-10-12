<?php

declare(strict_types=1);

use OpenWiki\Database\MigrationStep;

return [
    'version' => '005_editor_drafts_and_revision_metadata',
    'up' => [
        MigrationStep::column('page_revisions', 'slug', 'VARCHAR(255) NULL'),
        MigrationStep::column('page_revisions', 'parent_id', 'BIGINT UNSIGNED NULL'),
        MigrationStep::column('page_revisions', 'tags_json', 'TEXT NULL'),
        MigrationStep::column('page_revisions', 'conversion_backup_json', 'MEDIUMTEXT NULL'),
        <<<'SQL'
CREATE TABLE IF NOT EXISTS page_editor_drafts (
    space_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    document_key VARCHAR(80) NOT NULL,
    editor_id VARCHAR(64) NOT NULL,
    sequence BIGINT UNSIGNED NOT NULL,
    acknowledged_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    base_version INT UNSIGNED NOT NULL,
    payload_json MEDIUMTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (space_id, user_id, document_key, editor_id),
    CONSTRAINT fk_editor_draft_space FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_editor_draft_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO page_editor_drafts (space_id, user_id, document_key, editor_id, sequence, base_version, payload_json, updated_at)
SELECT p.space_id, d.user_id, CONCAT('page:', d.page_id), 'legacy', 1, d.base_version,
       JSON_OBJECT('title', p.title, 'slug', p.slug, 'parentId', COALESCE(CAST(p.parent_id AS CHAR), ''),
                   'status', p.status, 'tags', COALESCE((SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') FROM page_tags pt INNER JOIN tags t ON t.id = pt.tag_id WHERE pt.page_id = p.id), ''), 'changeSummary', '', 'format', d.content_format,
                   'html', d.content_html, 'markdown', COALESCE(d.content_markdown, ''), 'baseVersion', d.base_version), d.updated_at
FROM draft_autosaves d INNER JOIN pages p ON p.id = d.page_id
ON DUPLICATE KEY UPDATE document_key = VALUES(document_key)
SQL,
    ],
];
