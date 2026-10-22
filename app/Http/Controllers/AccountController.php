<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Http\Controller;

final class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }

        $currentUser = $this->app->auth()->user();
        $profile = $this->app->database()->fetchOne(
            'SELECT username, email, first_name, last_name, auth_source
             FROM users WHERE id = ? AND deleted_at IS NULL',
            [(int) $currentUser['id']]
        );

        if ($profile === null) {
            return $this->render('errors/404', ['title' => 'Account not found'], 404);
        }

        return $this->render('account/index', [
            'title' => 'Profile and settings',
            'profile' => $profile,
        ]);
    }
}
