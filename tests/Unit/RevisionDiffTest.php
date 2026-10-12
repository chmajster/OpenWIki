<?php
declare(strict_types=1);
namespace OpenWiki\Tests\Unit;
use OpenWiki\Wiki\RevisionDiff;
use PHPUnit\Framework\TestCase;
final class RevisionDiffTest extends TestCase
{
    public function testDiffReconstructsBothVersionsAndHandlesLargeChanges(): void
    {
        foreach ([["a\nb\nc", "a\nx\nc"], ['', '<script>unsafe</script>'], [str_repeat("old\n", 600), str_repeat("new\n", 600)], ['same', 'same']] as [$a, $b]) {
            $diff = RevisionDiff::lines($a, $b);
            self::assertSame($a, implode("\n", array_column(array_filter($diff, static fn (array $r): bool => $r[0] !== 'added'), 1)));
            self::assertSame($b, implode("\n", array_column(array_filter($diff, static fn (array $r): bool => $r[0] !== 'removed'), 1)));
        }
    }
}
