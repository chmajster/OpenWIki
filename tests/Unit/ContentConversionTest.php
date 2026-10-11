<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Unit;

use OpenWiki\Security\HtmlSanitizer;
use OpenWiki\Wiki\ContentService;
use PHPUnit\Framework\TestCase;

final class ContentConversionTest extends TestCase
{
    public function testActiveSourceIgnoresTheStaleInactiveBuffer(): void
    {
        $service = new ContentService();
        $markdown = $service->convert('markdown', 'visual', '<p>Old</p>', '# Latest');
        self::assertSame('<h1>Latest</h1>', $markdown['html']);
        $visual = $service->convert('visual', 'markdown', '<h1>Now</h1>', '# Old');
        self::assertSame('# Now', $visual['markdown']);
        self::assertFalse($visual['lossy']);
    }

    public function testLossyConversionReportsTheSafeOriginalAndTheActualRoundTrip(): void
    {
        $result = (new ContentService())->convert('visual', 'markdown', '<p><u>Zażółć</u></p><figure><img src="/attachments/1/thumbnail" data-attachment-id="1" width="640"><figcaption>Diagram</figcaption></figure>', 'old');
        self::assertTrue($result['lossy']);
        self::assertStringContainsString('data-attachment-id="1"', $result['before_html']);
        self::assertStringContainsString('Zażółć', $result['markdown']);
        self::assertStringNotContainsString('<img', $result['html']);
    }

    public function testNestedUntrustedWrappersCannotBypassRecoverySanitization(): void
    {
        $html = (new HtmlSanitizer())->sanitize('<unknown><p onclick="bad()">Żółć<a href="javascript:bad()">link</a><script>bad()</script></p></unknown>');
        self::assertSame('<p>Żółć<a>link</a></p>', $html);
    }

    public function testConversionPreservesLiteralMarkupAndInlineCode(): void
    {
        $result = (new ContentService())->convert('visual', 'markdown', '<p>Literal *stars* <code>**code**</code></p>', '');
        self::assertFalse($result['lossy']);
        self::assertSame('<p>Literal *stars* <code>**code**</code></p>', $result['html']);
    }
}
