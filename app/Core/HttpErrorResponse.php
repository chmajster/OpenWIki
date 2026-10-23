<?php

declare(strict_types=1);

namespace OpenWiki\Core;

final class HttpErrorResponse
{
    /** @return array{code: string, title: string, message: string, help: string, primaryLabel: string, primaryUrl: string, secondaryLabel: ?string, secondaryUrl: ?string} */
    public static function definition(int $status): array
    {
        return match ($status) {
            400 => [
                'code' => 'bad_request',
                'title' => 'Bad request',
                'message' => 'OpenWiki could not understand this request.',
                'help' => 'Check the address or form data and try again.',
                'primaryLabel' => 'Return to dashboard',
                'primaryUrl' => '/',
                'secondaryLabel' => null,
                'secondaryUrl' => null,
            ],
            401 => [
                'code' => 'unauthorized',
                'title' => 'Sign-in required',
                'message' => 'Sign in to continue to this resource.',
                'help' => 'Your account may need permission to view this page.',
                'primaryLabel' => 'Sign in',
                'primaryUrl' => '/login',
                'secondaryLabel' => 'Return to dashboard',
                'secondaryUrl' => '/',
            ],
            403 => [
                'code' => 'forbidden',
                'title' => 'Permission denied',
                'message' => 'You do not have permission to access this resource.',
                'help' => 'If you need access, ask the space owner or an administrator.',
                'primaryLabel' => 'Return to dashboard',
                'primaryUrl' => '/',
                'secondaryLabel' => null,
                'secondaryUrl' => null,
            ],
            404 => [
                'code' => 'not_found',
                'title' => 'Page not found',
                'message' => 'The requested resource does not exist or is no longer available.',
                'help' => 'Check the address or search OpenWiki for the page.',
                'primaryLabel' => 'Return to dashboard',
                'primaryUrl' => '/',
                'secondaryLabel' => 'Search pages',
                'secondaryUrl' => '/search',
            ],
            405 => [
                'code' => 'method_not_allowed',
                'title' => 'Method not allowed',
                'message' => 'This address does not support the request method used.',
                'help' => 'Open the page normally, then use its controls to make changes.',
                'primaryLabel' => 'Return to dashboard',
                'primaryUrl' => '/',
                'secondaryLabel' => null,
                'secondaryUrl' => null,
            ],
            419 => [
                'code' => 'csrf_failed',
                'title' => 'Session expired',
                'message' => 'The form security token is no longer valid. Reload the form and submit it again.',
                'help' => 'Sign in again if the form still cannot be submitted.',
                'primaryLabel' => 'Sign in',
                'primaryUrl' => '/login',
                'secondaryLabel' => 'Return to dashboard',
                'secondaryUrl' => '/',
            ],
            422 => [
                'code' => 'unprocessable_request',
                'title' => 'Request could not be processed',
                'message' => 'Some of the submitted information is invalid.',
                'help' => 'Review the form and correct the highlighted fields.',
                'primaryLabel' => 'Return to dashboard',
                'primaryUrl' => '/',
                'secondaryLabel' => null,
                'secondaryUrl' => null,
            ],
            429 => [
                'code' => 'too_many_requests',
                'title' => 'Too many requests',
                'message' => 'OpenWiki received too many requests in a short time.',
                'help' => 'Wait a little while, then try again.',
                'primaryLabel' => 'Return to dashboard',
                'primaryUrl' => '/',
                'secondaryLabel' => null,
                'secondaryUrl' => null,
            ],
            503 => [
                'code' => 'service_unavailable',
                'title' => 'Service temporarily unavailable',
                'message' => 'OpenWiki cannot complete this request right now.',
                'help' => 'Wait a moment and try again. If the problem continues, contact an administrator.',
                'primaryLabel' => 'Try again',
                'primaryUrl' => '/',
                'secondaryLabel' => 'Technical status',
                'secondaryUrl' => '/health',
            ],
            default => [
                'code' => 'unexpected_error',
                'title' => 'Application error',
                'message' => 'OpenWiki encountered an unexpected error.',
                'help' => 'Try again. If the problem continues, contact an administrator.',
                'primaryLabel' => 'Return to dashboard',
                'primaryUrl' => '/',
                'secondaryLabel' => 'Technical status',
                'secondaryUrl' => '/health',
            ],
        };
    }

    public static function response(
        Application $app,
        Request $request,
        int $status,
        ?string $title = null,
        ?string $message = null,
        array $headers = []
    ): Response {
        $definition = self::definition($status);
        $title ??= $definition['title'];
        $message ??= $definition['message'];

        if ($request->expectsJson()) {
            // Keep established API/client messages stable while sharing the
            // same status mapping with browser error pages.
            $jsonMessage = match ($status) {
                403 => 'Permission denied.',
                404 => 'Resource not found.',
                419 => 'Invalid CSRF token.',
                405 => 'Method not allowed.',
                default => I18n::text($message),
            };
            return Response::json([
                'error' => [
                    'code' => $definition['code'],
                    'message' => $jsonMessage,
                ],
            ], $status, $headers);
        }

        $allowedMethods = isset($headers['Allow']) ? explode(', ', (string) $headers['Allow']) : null;
        $template = in_array($status, [403, 404, 405, 419], true) ? 'errors/' . $status : 'errors/status';
        return Response::html($app->view()->render($template, [
            'errorStatus' => $status,
            'title' => $title,
            'message' => $message,
            'allowedMethods' => $allowedMethods,
        ]), $status, ['Cache-Control' => 'no-store'] + $headers);
    }
}
