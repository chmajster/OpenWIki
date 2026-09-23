<?php

declare(strict_types=1);

namespace OpenWiki\Security;

use OpenWiki\Core\Session;

final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_csrf');
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            Session::put('_csrf', $token);
        }

        return $token;
    }

    public static function verify(?string $token): bool
    {
        $expected = Session::get('_csrf');
        return is_string($expected) && is_string($token) && hash_equals($expected, $token);
    }

    public static function rotate(): void
    {
        Session::put('_csrf', bin2hex(random_bytes(32)));
    }
}
