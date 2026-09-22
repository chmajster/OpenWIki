<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Unit;

use OpenWiki\Wiki\Slugger;
use PHPUnit\Framework\TestCase;

final class SluggerTest extends TestCase
{
    public function testItCreatesUnicodeSafePageSlugs(): void
    {
        $slug = (new Slugger())->slug('  Linux: Sieć i Bezpieczeństwo  ');
        self::assertSame('linux-sieć-i-bezpieczeństwo', $slug);
    }

    public function testItNormalizesSpaceKeys(): void
    {
        self::assertSame('PROJECT-X', (new Slugger())->spaceKey(' project x '));
    }

    public function testItRejectsEmptySpaceKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Slugger())->spaceKey('---');
    }
}
