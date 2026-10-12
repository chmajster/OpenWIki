<?php
declare(strict_types=1);

use OpenWiki\Database\MigrationStep;

return ['version' => '012_search_revisions', 'up' => [
    MigrationStep::fulltext('page_revisions', 'ft_revision_search', ['title', 'content_text']),
]];
