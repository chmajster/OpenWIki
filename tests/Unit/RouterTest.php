<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Unit;

use OpenWiki\Core\Application;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testItDispatchesNamedPathParameters(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $seen = null;

        $app->router()->get('/spaces/{spaceKey}/pages/{slug}', static function (Request $request, string $spaceKey, string $slug) use (&$seen): Response {
            $seen = [$spaceKey, $slug];
            return Response::html('ok');
        });

        $request = new Request('GET', '/spaces/IT/pages/linux-networking', [], [], [], []);
        $app->router()->dispatch($request, $app);

        self::assertSame(['IT', 'linux-networking'], $seen);
    }
}
