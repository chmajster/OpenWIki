<?php
declare(strict_types=1);

use OpenWiki\Database\MigrationStep;

return ['version' => '009_space_management', 'up' => [
    MigrationStep::column('spaces', 'home_page_id', 'BIGINT UNSIGNED NULL'),
    MigrationStep::column('spaces', 'management_version', 'INT UNSIGNED NOT NULL DEFAULT 1'),
    'INSERT IGNORE INTO permissions (name) VALUES ("space.view"), ("space.edit"), ("space.manage_access"), ("space.manage_owner"), ("space.archive")',
    'INSERT IGNORE INTO roles (name, slug, description, is_system) VALUES ("Space owner", "space-owner", "Global capabilities limited by ownership or explicit space ACL", 1)',
    'INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.slug IN ("space-owner", "space-admin", "admin") AND p.name IN ("space.view", "space.edit", "space.manage_access", "space.manage_owner", "space.archive")',
    'INSERT IGNORE INTO user_roles (user_id, role_id) SELECT DISTINCT s.owner_id, r.id FROM spaces s CROSS JOIN roles r WHERE r.slug = "space-owner" AND s.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM user_roles ur INNER JOIN role_permissions rp ON rp.role_id = ur.role_id INNER JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = s.owner_id AND p.name IN ("*", "space.manage_access"))',
    'UPDATE spaces s SET visibility = "restricted" WHERE visibility = "private" AND EXISTS (SELECT 1 FROM space_acl a WHERE a.space_id = s.id AND a.effect = "allow")',
]];
