<?php

declare(strict_types=1);

namespace OpenWiki\Wiki;

final class TemplateGallery
{
    public const CATEGORIES = ['all' => 'All templates', 'technical' => 'Technical', 'knowledge' => 'Knowledge', 'team' => 'Team', 'operations' => 'Operations', 'custom' => 'Custom'];
    public const PER_PAGE = 12;

    public static function select(array $catalog, mixed $id): ?array
    {
        if (!is_string($id) || !preg_match('/^(?:[1-9][0-9]{0,18}|builtin:[a-z-]{1,32})$/D', $id)) return null;
        foreach ($catalog as $row) if ((string) $row['id'] === $id) return $row;
        return null;
    }

    public static function browse(array $catalog, array $input): array
    {
        $query = $input['q'] ?? '';
        $category = $input['category'] ?? 'all';
        $page = $input['page'] ?? '1';
        if (!is_string($query) || !mb_check_encoding($query, 'UTF-8') || mb_strlen($query) > 200) throw new \InvalidArgumentException('Search must contain at most 200 characters.');
        if (!is_string($category) || !array_key_exists($category, self::CATEGORIES)) throw new \InvalidArgumentException('Choose a valid filter value.');
        if ((!is_int($page) && !is_string($page)) || !preg_match('/^[1-9][0-9]{0,5}$/D', (string) $page)) throw new \InvalidArgumentException('Choose a valid page number.');
        $query = trim($query);
        $rows = array_values(array_filter($catalog, static fn (array $row): bool => ($category === 'all' || $row['category_key'] === $category)
            && ($query === '' || mb_stripos($row['display_name'] . ' ' . $row['summary'], $query, 0, 'UTF-8') !== false)));
        $total = count($rows);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min((int) $page, $totalPages);
        $offset = ($page - 1) * self::PER_PAGE;
        return ['filters' => ['q' => $query, 'category' => $category], 'data' => array_slice($rows, $offset, self::PER_PAGE),
            'meta' => ['total' => $total, 'page' => $page, 'total_pages' => $totalPages, 'from' => $total ? $offset + 1 : 0, 'to' => min($offset + self::PER_PAGE, $total)]];
    }
}
