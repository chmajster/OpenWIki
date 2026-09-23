<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Unit;

use OpenWiki\Security\HtmlSanitizer;
use OpenWiki\Wiki\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class ContentSecurityTest extends TestCase
{
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
