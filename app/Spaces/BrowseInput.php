<?php

declare(strict_types=1);

namespace OpenWiki\Spaces;

final class BrowseInput
{
    public const PER_PAGE = 12;

    public static function directory(array $input): array
    {
        return self::common($input) + [
            'visibility' => self::choice($input, 'visibility', ['all', 'public', 'restricted', 'private'], 'all'),
            'state' => self::choice($input, 'state', ['all', 'active', 'archived'], 'active'),
            'layout' => self::choice($input, 'layout', ['cards', 'list'], 'cards'),
        ];
    }

    public static function content(array $input): array
    {
        return self::common($input) + [
            'state' => self::choice($input, 'state', ['all', 'published', 'draft', 'archived'], 'all'),
        ];
    }

    private static function common(array $input): array
    {
        $query = $input['q'] ?? '';
        if (!is_string($query) || !mb_check_encoding($query, 'UTF-8') || mb_strlen($query) > 200) {
            throw new \InvalidArgumentException('Search must contain at most 200 characters.');
        }
        $page = $input['page'] ?? '1';
        if ((!is_string($page) && !is_int($page)) || !preg_match('/^[1-9][0-9]{0,5}$/D', (string) $page)) {
            throw new \InvalidArgumentException('Choose a valid page number.');
        }
        return [
            'q' => trim($query),
            'sort' => self::choice($input, 'sort', ['name', 'updated'], 'name'),
            'page' => (int) $page,
        ];
    }

    private static function choice(array $input, string $name, array $choices, string $default): string
    {
        $value = $input[$name] ?? $default;
        if (!is_string($value) || !in_array($value, $choices, true)) {
            throw new \InvalidArgumentException('Choose a valid filter value.');
        }
        return $value;
    }
}
