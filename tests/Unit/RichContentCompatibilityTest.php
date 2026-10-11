<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Unit;

use OpenWiki\Security\HtmlSanitizer;
use OpenWiki\Wiki\ContentService;
use PHPUnit\Framework\TestCase;

final class RichContentCompatibilityTest extends TestCase
{
    public function testGfmPreservesTablesNestedListsTasksCodeLanguageAndSafeLinks(): void
    {
        $markdown = "# Żółć\n\n| Nazwa | Wynik |\n| --- | --- |\n| test | **OK** |\n\n"
            . "- pierwszy\n  - drugi\n\n- [x] wykonane\n- [ ] otwarte\n\n```php\necho '<tag>';\n```\n\n[Wiki](/spaces/PL/pages/wiki)";
        $html = (new ContentService())->normalize('markdown', '', $markdown)['html'];
        self::assertStringContainsString('<h1>Żółć</h1>', $html);
        self::assertStringContainsString('<th>Nazwa</th>', $html);
        self::assertStringContainsString('<strong>OK</strong>', $html);
        self::assertMatchesRegularExpression('~<li>\s*<p>pierwszy</p>\s*<ul>~', $html);
        self::assertStringContainsString('data-type="taskList"', $html);
        self::assertStringContainsString('data-checked="true"', $html);
        self::assertStringContainsString('data-checked="false"', $html);
        self::assertStringContainsString('data-language="php"', $html);
        self::assertStringContainsString('&lt;tag&gt;', $html);
        self::assertStringContainsString('href="/spaces/PL/pages/wiki"', $html);
        self::assertStringNotContainsString('<input', $html);
    }

    public function testStoredVisualHtmlIsNotRerenderedOrMigrated(): void
    {
        $html = '<section id="legacy"><h2>Stara strona</h2><table><tbody><tr><td colspan="2">Tabela</td></tr></tbody></table>'
            . '<aside data-callout="warning"><p>Uwaga</p></aside><pre><code data-language="sql">SELECT 1;</code></pre>'
            . '<ul data-type="taskList"><li data-type="taskItem" data-checked="true"><p>Gotowe</p></li></ul></section>';
        self::assertSame($html, (new ContentService())->normalize('visual', $html, '# stale')['html']);
    }

    public function testExternalPasteKeepsAllowedStructureAndRecursivelyDropsActiveContent(): void
    {
        $html = (new HtmlSanitizer())->sanitize('<html><body><article><h2 style="color:red">Nagłówek</h2><table><tr><td onclick="bad()"><strong>Komórka</strong></td></tr></table>'
            . '<script>bad()</script><iframe src="https://example.test"></iframe><a href="javascript:bad()">Tekst</a></article></body></html>');
        self::assertStringContainsString('<h2>Nagłówek</h2>', $html);
        self::assertStringContainsString('<strong>Komórka</strong>', $html);
        foreach (['script', 'iframe', 'onclick', 'javascript:', 'style='] as $unsafe) self::assertStringNotContainsString($unsafe, $html);
    }

    public function testLossyRichConversionRetainsTheSanitizedOriginal(): void
    {
        $html = '<aside data-callout="warning"><p>Uwaga</p></aside><table><tr><td colspan="2">Komórka</td></tr></table>';
        $result = (new ContentService())->convert('visual', 'markdown', $html, '');
        self::assertTrue($result['lossy']);
        self::assertSame($html, $result['before_html']);
        self::assertStringContainsString('Komórka', $result['markdown']);
        self::assertSame($result['html'], (new ContentService())->normalize('markdown', '', $result['markdown'])['html']);
    }
}
