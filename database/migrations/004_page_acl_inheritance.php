<?php

declare(strict_types=1);

return [
    'version' => '004_page_acl_inheritance',
    'up' => [
        <<<'SQL'
ALTER TABLE pages
    ADD COLUMN inherit_acl TINYINT(1) NOT NULL DEFAULT 1 AFTER order_index,
    ADD KEY idx_pages_inherit_acl (inherit_acl)
SQL
    ],
];
