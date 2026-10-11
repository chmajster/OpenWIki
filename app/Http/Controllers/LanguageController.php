<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Security\Csrf;

final class LanguageController
{
    public function store(Request $request): Response
    {
        if (!Csrf::verify(is_string($request->input('_token')) ? $request->input('_token') : null)) {
            return Response::html('Invalid CSRF token.', 419);
        }
        $locale = $request->input('locale');
        $back = $request->input('back', '/');
        if (!in_array($locale, ['pl', 'en'], true) || !is_string($back)
            || !preg_match('~^/(?!/)[^\\\\\r\n]*$~D', $back)) {
            return Response::html('Invalid language preference.', 422);
        }
        return new Response('', 303, ['Location' => $back, 'Set-Cookie' =>
            'openwiki_locale=' . $locale . '; Path=/; Max-Age=31536000; HttpOnly; SameSite=Lax'
            . (str_starts_with((string) $request->header('Origin', ''), 'https://') ? '; Secure' : '')]);
    }
}
