<?php
declare(strict_types=1);
namespace OpenWiki\Wiki;

final class PageStatusPolicy
{
    public static function assertAllowed(string $status, ?array $page, ?callable $can): void
    {
        $permission = match ($status) { 'published' => 'page.publish', 'archived' => 'page.archive', default => null };
        if ($permission !== null && $can !== null && !$can($permission, $page)) {
            throw new \DomainException('You do not have permission to publish or archive this page.');
        }
    }
}
