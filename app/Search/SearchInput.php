<?php
declare(strict_types=1);
namespace OpenWiki\Search;

use OpenWiki\Core\Request;

final class SearchInput
{
    public const TYPES = ['all', 'page', 'space', 'user', 'comment', 'attachment', 'tag'];

    public static function fromRequest(Request $request, string $defaultType = 'all'): array
    {
        foreach (['q','type','visibility','space','author','tag','created_from','created_to','updated_from','updated_to','page','per_page'] as $key) {
            $value = $request->query($key);
            if ($value !== null && ((!is_string($value) && !is_int($value)) || !mb_check_encoding((string) $value, 'UTF-8'))) throw new \InvalidArgumentException('Nieprawidłowy parametr: ' . $key . '.');
        }
        $query = trim((string) $request->query('q', ''));
        $type = strtolower(trim((string) $request->query('type', $defaultType)));
        $visibility = (string) $request->query('visibility', 'all');
        if (mb_strlen($query) > 200) throw new \InvalidArgumentException('Zapytanie może mieć najwyżej 200 znaków.');
        if (!in_array($type, self::TYPES, true)) throw new \InvalidArgumentException('Nieprawidłowy typ wyników.');
        if (!in_array($visibility, ['all', 'published', 'working'], true)) throw new \InvalidArgumentException('Nieprawidłowy zakres rewizji.');
        $filters = ['visibility' => $visibility];
        foreach (['space' => 191, 'author' => 100, 'tag' => 100] as $key => $length) {
            $filters[$key] = trim((string) $request->query($key, ''));
            if (mb_strlen($filters[$key]) > $length) throw new \InvalidArgumentException('Zbyt długi filtr: ' . $key . '.');
        }
        foreach (['created_from', 'created_to', 'updated_from', 'updated_to'] as $key) {
            $value = trim((string) $request->query($key, ''));
            if ($value !== '' && (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
                throw new \InvalidArgumentException('Nieprawidłowa data: ' . $key . '.');
            }
            $filters[$key] = $value === '' ? '' : $value . (str_ends_with($key, '_to') ? ' 23:59:59' : ' 00:00:00');
        }
        foreach (['created', 'updated'] as $prefix) {
            if ($filters[$prefix . '_from'] !== '' && $filters[$prefix . '_to'] !== '' && $filters[$prefix . '_from'] > $filters[$prefix . '_to']) throw new \InvalidArgumentException('Początek zakresu dat musi poprzedzać jego koniec.');
        }
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT);
        $perPage = filter_var($request->query('per_page', 25), FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 100000000 || $perPage === false || $perPage < 1 || $perPage > 100) throw new \InvalidArgumentException('Nieprawidłowa paginacja.');
        return ['query' => $query, 'type' => $type, 'filters' => $filters, 'page' => (int) $page, 'per_page' => (int) $perPage];
    }

    public static function literal(string $query): string
    {
        $query = trim($query);
        if (strlen($query) >= 2 && $query[0] === '"' && str_ends_with($query, '"')) return substr($query, 1, -1);
        if (preg_match('/^[\p{L}\p{N}]+(?:\s+[\p{L}\p{N}]+)*\*$/uD', $query) === 1) return substr($query, 0, -1);
        return $query;
    }

    public static function like(string $value): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%';
    }
}
