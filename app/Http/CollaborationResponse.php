<?php

declare (strict_types=1);
namespace OpenWiki\Http;

use OpenWiki\Core\Response;
use OpenWiki\Wiki\CollaborationConflict;
final class CollaborationResponse
{
    public static function failure(\Throwable $error): Response
    {
        $status = match (true) {
            $error instanceof CollaborationConflict => 409,
            $error instanceof \OutOfBoundsException => 404,
            $error instanceof \InvalidArgumentException => 422,
            $error instanceof \DomainException, in_array($error->getMessage(), ['COMMENT_EDIT_FORBIDDEN', 'COMMENT_DELETE_FORBIDDEN'], true) => 403,
            default => 500,
        };
        if ($status === 500) {
            error_log('[OpenWiki collaboration] ' . $error::class . ': ' . $error->getMessage());
        }
        return Response::json(['error' => ['code' => match ($status) {
            409 => 'conflict',
            404 => 'not_found',
            422 => 'validation_error',
            403 => 'forbidden',
            default => 'server_error',
        }, 'message' => $status === 500 ? 'Nie zapisano zmiany. Spróbuj ponownie.' : (str_starts_with($error->getMessage(), 'COMMENT_') ? 'Nie masz prawa zmienić tej wypowiedzi.' : $error->getMessage())]], $status);
    }
}
