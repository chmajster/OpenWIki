<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Unit;

use OpenWiki\Wiki\TemplateGallery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemplateGalleryTest extends TestCase
{
    public function testLiteralUnicodeSearchCategoriesAndPagination(): void
    {
        $catalog = [];
        for ($id = 1; $id <= 15; $id++) $catalog[] = ['id' => $id, 'display_name' => 'Łódź 100%_! ' . $id, 'summary' => 'Opis', 'category_key' => $id === 15 ? 'technical' : 'custom'];
        $first = TemplateGallery::browse($catalog, ['q' => ' łÓDŹ 100%_! ', 'category' => 'custom']);
        self::assertSame(14, $first['meta']['total']);
        self::assertCount(12, $first['data']);
        $last = TemplateGallery::browse($catalog, ['category' => 'custom', 'page' => 999999]);
        self::assertSame(2, $last['meta']['page']);
        self::assertSame([13, 14], array_column($last['data'], 'id'));
        self::assertSame(0, TemplateGallery::browse($catalog, ['q' => '100X'])['meta']['total']);
    }

    public function testSelectionUsesExactStoredAndBuiltinIdentifiers(): void
    {
        $catalog = [['id' => 7], ['id' => 'builtin:project']];
        self::assertSame(['id' => 7], TemplateGallery::select($catalog, '7'));
        self::assertSame(['id' => 'builtin:project'], TemplateGallery::select($catalog, 'builtin:project'));
        foreach (['07', '8', [], 7, 'builtin:unknown', '../7', '7 OR 1=1'] as $id) self::assertNull(TemplateGallery::select($catalog, $id));
    }

    #[DataProvider('invalidFilters')]
    public function testMalformedFiltersAreRejected(array $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TemplateGallery::browse([], $input);
    }

    public static function invalidFilters(): array
    {
        return array_map(static fn (array $input): array => [$input], [
            ['q' => []], ['q' => "\xff"], ['q' => str_repeat('ą', 201)],
            ['category' => []], ['category' => 'deleted'], ['page' => []], ['page' => '0'], ['page' => '1.5'], ['page' => '1000000'],
        ]);
    }
}
