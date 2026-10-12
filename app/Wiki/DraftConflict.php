<?php

declare(strict_types=1);

namespace OpenWiki\Wiki;

final class DraftConflict extends \RuntimeException
{
    public function __construct(public readonly array $current)
    {
        parent::__construct('The server draft changed. Keep your work separately or recover the server draft.');
    }
}
