<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Unit;

use OpenWiki\Core\Application;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use PHPUnit\Framework\TestCase;

final class RouterHttpMethodsTest extends TestCase
{
    public function testRouterDispatchesPutPatchAndDeleteRoutes(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $seen = [];

        $app->router()->put('/api/items/{id}', static function (Request $request, string $id) use (&$seen): Response {
            $seen[] = ['PUT', $id];
            return Response::json(['ok' => true]);
        });
        $app->router()->patch('/api/items/{id}', static function (Request $request, string $id) use (&$seen): Response {
            $seen[] = ['PATCH', $id];
            return Response::json(['ok' => true]);
        });
        $app->router()->delete('/api/items/{id}', static function (Request $request, string $id) use (&$seen): Response {
            $seen[] = ['DELETE', $id];
            return Response::json(['ok' => true]);
        });

        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $app->router()->dispatch(new Request($method, '/api/items/42', [], [], [], []), $app);
        }

        self::assertSame([
            ['PUT', '42'],
            ['PATCH', '42'],
            ['DELETE', '42'],
        ], $seen);
    }
}
