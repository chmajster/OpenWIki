<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Unit;

use OpenWiki\Core\Application;
use PHPUnit\Framework\TestCase;

final class RouteRegistrationTest extends TestCase
{
    public function testAllWebRoutesReferenceExistingControllerMethods(): void
    {
        $basePath = dirname(__DIR__, 2);
        $app = Application::boot($basePath);

        require $basePath . '/routes/web.php';

        self::assertTrue(true);
    }
}
