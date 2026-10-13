<?php

declare (strict_types=1);
namespace OpenWiki\Tests\Unit;

use OpenWiki\Wiki\CommentAnchor;
use PHPUnit\Framework\TestCase;
final class CommentAnchorTest extends TestCase
{
    private function anchor(string $text, string $quote, ?int $start = null): array
    {
        return CommentAnchor::create($text, ['quote' => $quote, 'start' => $start ?? mb_strpos($text, $quote), 'hash' => hash('sha256', $text)]);
    }
    public function testCanonicalTextAndUnicodeOffsets(): void
    {
        $text = CommentAnchor::text('<h2>Żółw 🐢</h2><p>Zażółć&nbsp;<strong>gęślą</strong> jaźń.</p>');
        self::assertSame('Żółw 🐢 Zażółć gęślą jaźń.', $text);
        $anchor = $this->anchor($text, 'gęślą');
        self::assertSame(['start' => 14, 'end' => 19], CommentAnchor::locate($anchor, $text));
    }
    public function testInsertionBeforeQuoteMovesOnlyWithMatchingContext(): void
    {
        $text = str_repeat('Context ', 12) . 'Quoted fragment continues after.';
        $anchor = $this->anchor($text, 'Quoted fragment');
        self::assertSame(['start' => 106, 'end' => 121], CommentAnchor::locate($anchor, 'Inserted. ' . $text));
        self::assertNull(CommentAnchor::locate($anchor, str_repeat('Context ', 12) . 'continues after.'));
        self::assertNull(CommentAnchor::locate($anchor, 'Unrelated Quoted fragment elsewhere.'));
    }
    public function testDuplicateQuoteNeverFallsBackToItsOldOrdinal(): void
    {
        $text = 'Left same quote right. Other same quote end.';
        $anchor = $this->anchor($text, 'same quote');
        self::assertNotNull(CommentAnchor::locate($anchor, $text));
        self::assertNull(CommentAnchor::locate($anchor, 'Other same quote end.'));
        self::assertNull(CommentAnchor::locate($anchor, $text . ' ' . $text));
    }
    public function testClientCannotAnchorToUnverifiedContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommentAnchor::create('Actual source', ['quote' => 'Actual', 'start' => 0, 'hash' => hash('sha256', 'Forged source')]);
    }
}
