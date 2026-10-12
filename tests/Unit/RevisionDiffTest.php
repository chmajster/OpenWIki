<?php
declare(strict_types=1);
namespace OpenWiki\Tests\Unit;
use OpenWiki\Wiki\RevisionDiff;
use PHPUnit\Framework\TestCase;
final class RevisionDiffTest extends TestCase
{
    public function testRevisionFilesKeepExternalUrlsAndBindProtectedImageAndLinkQueries(): void
    {
        $source = '<p><img src="/attachments/12/thumbnail?width=640"><a href="/attachments/12/download">File</a><a href="https://example.test/attachments/12/download">External</a></p>';
        $rendered = \OpenWiki\Wiki\RevisionAccessService::bindFiles($source, 3);
        self::assertStringContainsString('src="/attachments/12/thumbnail?width=640&amp;revision=3"', $rendered);
        self::assertStringContainsString('href="/attachments/12/download?revision=3"', $rendered);
        self::assertStringContainsString('href="https://example.test/attachments/12/download"', $rendered);
        self::assertSame($rendered, \OpenWiki\Wiki\RevisionAccessService::bindFiles($rendered, 3));
        self::assertSame($source, \OpenWiki\Wiki\RevisionAccessService::bindFiles($source, 3, [99]));
        self::assertSame($rendered, \OpenWiki\Wiki\RevisionAccessService::bindFiles($source, 3, [12]));
    }

    public function testDiffReconstructsBothVersionsAndHandlesLargeChanges(): void
    {
        foreach ([["a\nb\nc", "a\nx\nc"], ['', '<script>unsafe</script>'], [str_repeat("old\n", 600), str_repeat("new\n", 600)], [str_repeat("old\n", 50000), str_repeat("new\n", 50000)], ['same', 'same']] as [$a, $b]) {
            $diff = RevisionDiff::lines($a, $b);
            self::assertSame($a, implode("\n", array_column(array_filter($diff, static fn (array $r): bool => $r[0] !== 'added'), 1)));
            self::assertSame($b, implode("\n", array_column(array_filter($diff, static fn (array $r): bool => $r[0] !== 'removed'), 1)));
        }
    }
}
