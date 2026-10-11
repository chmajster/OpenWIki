<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Unit;

use OpenWiki\Core\I18n;
use OpenWiki\Core\Request;
use OpenWiki\Http\Controllers\LanguageController;
use OpenWiki\Security\Csrf;
use PHPUnit\Framework\TestCase;

final class LanguagePreferenceTest extends TestCase
{
    public function testPreferenceRequiresCsrfAndRejectsRemoteRedirects(): void
    {
        $controller = new LanguageController();
        $request = static fn (array $data): Request => new Request('POST', '/language', [], $data, [], []);
        self::assertSame(419, $controller->store($request(['locale' => 'pl']))->status());
        foreach (['//example.test', '/\\example.test', "/\r\nInjected: true", 'https://example.test'] as $back) {
            self::assertSame(422, $controller->store($request(['_token' => Csrf::token(), 'locale' => 'pl', 'back' => $back]))->status());
        }
        $response = $controller->store($request(['_token' => Csrf::token(), 'locale' => 'en', 'back' => '/spaces/PL/pages/wiki?document=123']));
        self::assertSame(303, $response->status());
        self::assertSame('/spaces/PL/pages/wiki?document=123', $response->headers()['Location']);
        self::assertStringContainsString('HttpOnly; SameSite=Lax', $response->headers()['Set-Cookie']);
    }

    public function testLocaleTranslatesOnlyExplicitInterfaceMessages(): void
    {
        $old = $_COOKIE;
        try {
            unset($_COOKIE['openwiki_locale']);
            self::assertSame('pl', I18n::locale());
            self::assertSame('Zapisz stronę', I18n::text('Save page'));
            self::assertSame('User document: Save page', I18n::text('User document: Save page'));
            $_COOKIE['openwiki_locale'] = 'en';
            self::assertSame('Save page', I18n::text('Save page'));
        } finally { $_COOKIE = $old; }
    }
}
