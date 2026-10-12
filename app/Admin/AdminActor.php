<?php

declare(strict_types=1);

namespace OpenWiki\Admin;

/** Authority comes from the authenticated caller, never from submitted form fields. */
final class AdminActor
{
    private function __construct(public readonly ?int $userId) {}

    public static function user(int $id): self
    {
        if ($id < 1) throw new \DomainException('An authenticated administrator is required.');
        return new self($id);
    }

    public static function localConsole(): self
    {
        if (PHP_SAPI !== 'cli') throw new \DomainException('Local console authority is unavailable over HTTP.');
        return new self(null);
    }
}
