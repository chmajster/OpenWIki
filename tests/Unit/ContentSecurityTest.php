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

    public function testMarkdownEscapesRawHtml(): void
    {
        $output = (new MarkdownRenderer())->render('# Title' . "\n\n" . '<script>alert(1)</script>');
        self::assertStringContainsString('<h1>Title</h1>', $output);
        self::assertStringNotContainsString('<script>', $output);
        self::assertStringContainsString('&lt;script&gt;', $output);
    }
}
