<?php
declare(strict_types=1);
namespace OpenWiki\Tests\Unit;

use OpenWiki\Core\Request;
use OpenWiki\Search\SearchInput;
use PHPUnit\Framework\TestCase;

final class SearchInputTest extends TestCase
{
    public function testTechnicalPunctuationIsLiteralAndPrefixesAndPhrasesAreExplicit(): void
    {
        foreach (['*' => '*', 'C++' => 'C++', 'foo_bar' => 'foo_bar', '50%' => '50%', '*router' => '*router', 'router*' => 'router', '"two words"' => 'two words'] as $query => $literal) self::assertSame($literal, SearchInput::literal($query));
        self::assertSame('%foo!_bar!%!!!%%', SearchInput::like('foo_bar%!%'));
    }

    public function testMalformedFiltersAreRejectedInsteadOfChangingSearchScope(): void
    {
        foreach ([['q' => []], ['type' => 'unknown'], ['visibility' => 'private'], ['page' => -1], ['per_page' => 1000], ['created_from' => '2026-02-30'], ['updated_from' => '2026-10-12', 'updated_to' => '2026-10-11']] as $query) {
            try { SearchInput::fromRequest(new Request('GET','/search',$query + ['q' => 'IP'],[],[],[])); self::fail('Malformed input must be rejected.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
