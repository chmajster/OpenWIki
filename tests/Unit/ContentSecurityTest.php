<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Unit;

use OpenWiki\Security\HtmlSanitizer;
use OpenWiki\Wiki\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class ContentSecurityTest extends TestCase
{
    public function testEveryDescendantAndAttributeIsCheckedAfterUnwrapping(): void
    {
        $sanitizer = new HtmlSanitizer();
        foreach (require dirname(__DIR__) . '/Fixtures/security-html.php' as $index => $input) {
            $output = $sanitizer->sanitize($input);
            if ($index !== 2) self::assertStringContainsString('retained', $output);
            self::assertDoesNotMatchRegularExpression('/<(?:script|style|svg|math|template|noscript|custom|article)\\b|\\bon[a-z]+\\s*=|javascript:/i', $output);
            self::assertSame($output, $sanitizer->sanitize($output), 'Safe output must survive reparsing.');
        }
    }

    public function testHistoricalProjectionDoesNotRewriteSnapshot(): void
    {
        $snapshot = ['content_format' => 'visual', 'content_html' => '<article><p onclick="alert(1)">retained</p></article>',
            'content_markdown' => null, 'document_json' => json_encode(['version' => 1, 'format' => 'visual', 'source' => '<p onclick="alert(1)">retained</p>'])];
        $before = $snapshot;
        $safe = (new \OpenWiki\Wiki\ContentService())->safeProjection($snapshot);
        self::assertSame($before, $snapshot);
        self::assertSame('<p>retained</p>', $safe['content_html']);
        self::assertSame('<p>retained</p>', json_decode($safe['document_json'], true)['source']);
    }

    public function testSanitizerRemovesScriptsHandlersAndUnsafeUrls(): void
    {
        $input = '<p onclick="alert(1)">Safe<script>alert(1)</script><a href="javascript:alert(2)">link</a></p>';
        $output = (new HtmlSanitizer())->sanitize($input);

        self::assertStringNotContainsString('<script', $output);
        self::assertStringNotContainsString('onclick=', $output);
        self::assertStringNotContainsString('javascript:', $output);
        self::assertStringContainsString('Safe', $output);
    }

    public function testImageFigureKeepsSafeMetadataAndDropsActiveAttributes(): void
    {
        $input = '<figure><img src="/attachments/1/thumbnail?width=640" alt="Diagram" width="640" '
            . 'data-attachment-id="1" data-original-width="1200" data-original-height="800" '
            . 'style="width:9999px" onerror="alert(1)"><figcaption>Topology</figcaption></figure>';

        $output = (new HtmlSanitizer())->sanitize($input);

        self::assertStringContainsString('<figure>', $output);
        self::assertStringContainsString('<figcaption>Topology</figcaption>', $output);
        self::assertStringContainsString('data-attachment-id="1"', $output);
        self::assertStringContainsString('data-original-width="1200"', $output);
        self::assertStringContainsString('width="640"', $output);
        self::assertStringNotContainsString('style=', $output);
        self::assertStringNotContainsString('onerror=', $output);
    }

    public function testMarkdownEscapesRawHtml(): void
    {
        $output = (new MarkdownRenderer())->render('# Title' . "\n\n" . '<script>alert(1)</script>');
        self::assertStringContainsString('<h1>Title</h1>', $output);
        self::assertStringNotContainsString('<script>', $output);
        self::assertStringContainsString('&lt;script&gt;', $output);
    }
}
