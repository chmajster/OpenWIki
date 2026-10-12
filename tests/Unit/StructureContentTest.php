<?php
declare(strict_types=1);
namespace OpenWiki\Tests\Unit;
use OpenWiki\Spaces\StructureContent;
use PHPUnit\Framework\TestCase;

final class StructureContentTest extends TestCase
{
    private function transform(): StructureContent
    {
        $rows = [
            ['id' => 1, 'space_id' => 1, 'title' => 'Hidden', 'slug' => 'secret-slug', 'deleted_at' => null],
            ['id' => 2, 'space_id' => 1, 'title' => 'Root & child', 'slug' => 'root-child', 'deleted_at' => null],
            ['id' => 3, 'space_id' => 1, 'title' => 'Hidden', 'slug' => 'visible-duplicate', 'deleted_at' => null],
        ];
        return new StructureContent(['id' => 1, 'space_key' => 'SOURCE'], ['id' => 2, 'space_key' => 'DEST'], $rows, [2 => 'copied', 3 => 'copy-duplicate'], [2 => 10, 3 => 11], ['/attachments/2' => '/attachments/20'], [['space_id' => 1, 'old_slug' => 'old-root', 'page_id' => 2]]);
    }

    public function testMarkdownRewritePreservesCodeAndDoesNotRevealHiddenSlugs(): void
    {
        $source = "[[Root & child]] [[old-root]] [[Hidden]]\n\n`[[Root & child]]`\n\n```php\n[[Root & child]]\n```\n\n[File](/attachments/2/download)";
        $content = $this->transform()->transform(['content_format' => 'markdown', 'content_markdown' => $source]);
        self::assertStringContainsString('[Root & child](/spaces/DEST/pages/copied)', $content['markdown']);
        self::assertStringContainsString('[old-root](/spaces/DEST/pages/copied)', $content['markdown']);
        self::assertStringContainsString('[Hidden](/spaces/SOURCE/wiki/Hidden)', $content['markdown']);
        self::assertStringNotContainsString('secret-slug', $content['markdown']); self::assertStringNotContainsString('copy-duplicate', $content['markdown']);
        self::assertStringContainsString('`[[Root & child]]`', $content['markdown']); self::assertStringContainsString("```php\n[[Root & child]]\n```", $content['markdown']);
        self::assertStringContainsString('[File](/attachments/20/download)', $content['markdown']);
    }

    public function testVisualRewritePreservesEscapedTextCodeStructureAndProtectedImageVariants(): void
    {
        $source = '<p>&lt;em&gt;literal&lt;/em&gt; [[Root &amp; child]] [[Hidden]]</p><pre><code>[[Root &amp; child]]</code></pre><figure><img src="/attachments/2/thumbnail?width=120" alt="Alt"><figcaption>Caption</figcaption></figure><table><tbody><tr><td><a href="/spaces/SOURCE/pages/old-root#heading">Alias</a></td></tr></tbody></table>';
        $content = $this->transform()->transform(['content_format' => 'visual', 'content_html' => $source]);
        self::assertStringContainsString('&lt;em&gt;literal&lt;/em&gt;', $content['html']); self::assertStringContainsString('/spaces/DEST/pages/copied#heading', $content['html']);
        self::assertStringContainsString('<code>[[Root &amp; child]]</code>', $content['html']); self::assertStringContainsString('/attachments/20/thumbnail?width=120', $content['html']);
        self::assertStringContainsString('<figcaption>Caption</figcaption>', $content['html']); self::assertStringContainsString('<table>', $content['html']);
        self::assertStringNotContainsString('secret-slug', $content['html']);
    }
}
