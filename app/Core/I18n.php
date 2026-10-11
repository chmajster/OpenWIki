<?php

declare(strict_types=1);

namespace OpenWiki\Core;

final class I18n
{
    public static function locale(): string
    {
        return ($_COOKIE['openwiki_locale'] ?? 'pl') === 'en' ? 'en' : 'pl';
    }

    public static function catalog(): array
    {
        static $catalog;
        return $catalog ??= require dirname(__DIR__, 2) . '/resources/lang/pl.php';
    }

    public static function text(string $text): string
    {
        return self::locale() === 'pl' ? (self::catalog()[$text] ?? $text) : $text;
    }
}
