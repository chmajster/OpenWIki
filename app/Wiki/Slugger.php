<?php

declare(strict_types=1);

namespace OpenWiki\Wiki;

final class Slugger
{
    public function slug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? '';
        $value = trim($value, '-');

        if ($value === '') {
            $value = 'page-' . substr(bin2hex(random_bytes(6)), 0, 12);
        }

        return mb_substr($value, 0, 240);
    }

    public function spaceKey(string $value): string
    {
        $key = strtoupper(trim($value));
        $key = preg_replace('/[^A-Z0-9_-]+/', '-', $key) ?? '';
        $key = trim($key, '-_');

        if ($key === '' || strlen($key) > 50) {
            throw new \InvalidArgumentException('Space key must contain 1-50 letters, numbers, dash or underscore.');
        }

        return $key;
    }
}
