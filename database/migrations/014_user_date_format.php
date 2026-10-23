<?php

declare(strict_types=1);

use OpenWiki\Database\MigrationStep;

return [
    'version' => '014_user_date_format',
    'up' => [
        MigrationStep::column('users', 'date_format', "VARCHAR(20) NOT NULL DEFAULT 'locale'"),
    ],
];
