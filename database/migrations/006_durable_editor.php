<?php

declare(strict_types=1);

return [
    'version' => '006_durable_editor',
    'up' => [
        'ALTER TABLE pages ADD COLUMN document_json MEDIUMTEXT NULL',
        'ALTER TABLE page_revisions ADD COLUMN document_json MEDIUMTEXT NULL',
        "UPDATE pages SET document_json = JSON_OBJECT('version', 1, 'format', IF(content_format = 'markdown' AND COALESCE(content_markdown, '') = '' AND content_html <> '', 'visual', content_format), 'source', IF(content_format = 'markdown' AND COALESCE(content_markdown, '') <> '', content_markdown, content_html))",
        "UPDATE page_revisions SET document_json = JSON_OBJECT('version', 1, 'format', IF(content_format = 'markdown' AND COALESCE(content_markdown, '') = '' AND content_html <> '', 'visual', content_format), 'source', IF(content_format = 'markdown' AND COALESCE(content_markdown, '') <> '', content_markdown, content_html))",
        'ALTER TABLE page_editor_drafts ADD COLUMN draft_version BIGINT UNSIGNED NOT NULL DEFAULT 0',
        <<<'SQL'
CREATE TABLE editor_draft_operations (
    space_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    document_key VARCHAR(80) NOT NULL,
    editor_id VARCHAR(64) NOT NULL,
    operation_id CHAR(36) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    result_json TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (space_id, user_id, document_key, editor_id, operation_id),
    CONSTRAINT fk_draft_operation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_operation_space FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE editor_recovery_points (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    document_key VARCHAR(80) NOT NULL,
    editor_id VARCHAR(64) NOT NULL,
    draft_version BIGINT UNSIGNED NOT NULL,
    sequence BIGINT UNSIGNED NOT NULL,
    base_version INT UNSIGNED NOT NULL,
    payload_json MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_recovery_owner (space_id, user_id, document_key, editor_id, id),
    CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_recovery_space FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE editor_page_operations (
    user_id BIGINT UNSIGNED NOT NULL,
    operation_id CHAR(36) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    result_json MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, operation_id),
    CONSTRAINT fk_page_operation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE editor_draft_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    document_key VARCHAR(80) NOT NULL,
    operation_id CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    storage_key VARCHAR(191) NOT NULL,
    mime_type VARCHAR(191) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,
    attachment_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NOT NULL,
    UNIQUE KEY uq_draft_upload (user_id, operation_id),
    KEY idx_draft_file_owner (space_id, user_id, document_key),
    CONSTRAINT fk_draft_file_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_file_space FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_file_attachment FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
