<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Audit\AuditLogger;
use OpenWiki\Auth\AuthService;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Security\Csrf;
use OpenWiki\Security\RateLimiter;

final class AccountPasswordController extends Controller
{
    public function edit(Request $request): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }

        return $this->render('account/change-password', [
            'title' => 'Change password',
            'forced' => (bool) ($this->app->auth()->user()['force_password_change'] ?? false),
        ]);
    }

    public function update(Request $request): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $user = $this->app->auth()->user();
        $limiter = new RateLimiter($this->app->database());
        $rateKey = 'user:' . (int) $user['id'] . '|ip:' . $request->ip();

        if ($limiter->tooManyAttempts('password_change', $rateKey, 5, 900)) {
            return $this->render('account/change-password', [
                'title' => 'Change password',
                'forced' => (bool) $user['force_password_change'],
                'formError' => 'Too many password attempts. Try again later.',
            ], 429);
        }

        $currentPassword = (string) $request->input('current_password', '');
        $newPassword = (string) $request->input('new_password', '');
        $confirmation = (string) $request->input('new_password_confirmation', '');

        $row = $this->app->database()->fetchOne(
            'SELECT password_hash FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => (int) $user['id']]
        );

        if (
            $row === null
            || !is_string($row['password_hash'])
            || !password_verify($currentPassword, $row['password_hash'])
        ) {
            $limiter->hit('password_change', $rateKey);
            return $this->render('account/change-password', [
                'title' => 'Change password',
                'forced' => (bool) $user['force_password_change'],
                'formError' => 'Current password is incorrect.',
            ], 422);
        }

        if (strlen($newPassword) < 12) {
            return $this->render('account/change-password', [
                'title' => 'Change password',
                'forced' => (bool) $user['force_password_change'],
                'formError' => 'New password must contain at least 12 characters.',
            ], 422);
        }
        if ($newPassword !== $confirmation) {
            return $this->render('account/change-password', [
                'title' => 'Change password',
                'forced' => (bool) $user['force_password_change'],
                'formError' => 'Password confirmation does not match.',
            ], 422);
        }
        if (password_verify($newPassword, $row['password_hash'])) {
            return $this->render('account/change-password', [
                'title' => 'Change password',
                'forced' => (bool) $user['force_password_change'],
                'formError' => 'New password must be different from the current password.',
            ], 422);
        }

        $hash = password_hash($newPassword, AuthService::passwordAlgorithm());
        if (!is_string($hash)) {
            throw new \RuntimeException('Unable to hash password.');
        }

        $this->app->database()->execute(
            'UPDATE users
             SET password_hash = :password_hash, force_password_change = 0, updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['password_hash' => $hash, 'id' => (int) $user['id']]
        );

        $limiter->clear('password_change', $rateKey);
        Session::regenerate();
        Csrf::rotate();

        (new AuditLogger($this->app->database()))->log(
            'PASSWORD_CHANGED',
            'user',
            (int) $user['id'],
            (int) $user['id'],
            $request
        );

        Session::flash('success', 'Password changed.');
        return Response::redirect('/');
    }
}
