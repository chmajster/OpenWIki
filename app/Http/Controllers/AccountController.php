<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\DateFormatter;
use OpenWiki\Core\Session;
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
            'SELECT username, email, first_name, last_name, auth_source, date_format
             FROM users WHERE id = ? AND deleted_at IS NULL',
            [(int) $currentUser['id']]
        );

        if ($profile === null) {
            return $this->render('errors/404', ['title' => 'Account not found'], 404);
        }

        return $this->render('account/index', [
            'title' => 'Profile and settings',
            'profile' => $profile,
            'dateFormats' => DateFormatter::choices(),
        ]);
    }

    public function savePreferences(Request $request): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $format = $request->input('date_format');
        if (!is_string($format) || !DateFormatter::supports($format)) {
            Session::flash('error', 'Invalid date format.');
            return Response::redirect('/account');
        }

        $user = $this->app->auth()->user();
        $this->app->database()->execute(
            'UPDATE users SET date_format = :date_format WHERE id = :id',
            ['date_format' => $format, 'id' => (int) $user['id']]
        );
        Session::flash('success', 'Date format saved.');
        return Response::redirect('/account');
    }
}
