<?php

declare(strict_types=1);

use OpenWiki\Database\MigrationStep;

return [
    'version' => '004_page_acl_inheritance',
    'up' => [
        MigrationStep::column('pages', 'inherit_acl', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER order_index'),
        MigrationStep::index('pages', 'idx_pages_inherit_acl', ['inherit_acl']),
    ],
];
