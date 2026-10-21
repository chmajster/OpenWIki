<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Unit;

use OpenWiki\Spaces\BrowseInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrowseInputTest extends TestCase
{
    public function testDefaultsAndUnicodeQueries(): void
    {
        self::assertSame(['q' => '', 'sort' => 'name', 'page' => 1, 'visibility' => 'all', 'state' => 'active', 'layout' => 'cards'], BrowseInput::directory([]));
        self::assertSame('Zażółć 100%_!', BrowseInput::directory(['q' => '  Zażółć 100%_!  '])['q']);
        self::assertSame('archived', BrowseInput::content(['state' => 'archived'])['state']);
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsMalformedFilters(array $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BrowseInput::directory($input);
    }

    public static function invalidInputs(): array
    {
        return array_map(static fn (array $input): array => [$input], [
            ['q' => []], ['q' => str_repeat('ą', 201)], ['q' => "\xff"],
            ['page' => []], ['page' => 0], ['page' => '1.5'], ['page' => '-1'], ['page' => '1000000'],
            ['sort' => 'name; DROP TABLE spaces'], ['visibility' => []], ['state' => 'deleted'], ['layout' => 'unknown'],
        ]);
    }
}
