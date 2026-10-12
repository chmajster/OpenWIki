<?php

declare(strict_types=1);

namespace OpenWiki\Database;

/** Recovery conditions inspect the actual schema after an interrupted DDL statement. */
final class MigrationStep
{
    public static function column(string $table, string $column, string $definition): array
    {
        self::identifier($table);
        self::identifier($column);
        if (!preg_match('/^([A-Z]+)(?:\((\d+)\))?/i', $definition, $type)) {
            throw new \InvalidArgumentException('A migration column needs an explicit type.');
        }
        $where = "TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = '$column'";
        $expected = " AND DATA_TYPE = '" . strtolower($type[1]) . "'";
        if (isset($type[2]) && in_array(strtolower($type[1]), ['varchar', 'char'], true)) {
            $expected .= ' AND CHARACTER_MAXIMUM_LENGTH = ' . (int) $type[2];
        }
        $expected .= " AND IS_NULLABLE = '" . (stripos($definition, 'NOT NULL') !== false ? 'NO' : 'YES') . "'";
        if (stripos($definition, 'UNSIGNED') !== false) $expected .= " AND COLUMN_TYPE LIKE '%unsigned%'";
        if (preg_match('/\bDEFAULT\s+([0-9]+)/i', $definition, $default)) $expected .= " AND COLUMN_DEFAULT = '" . $default[1] . "'";
        return [
            'sql' => "ALTER TABLE `$table` ADD COLUMN `$column` $definition",
            'check' => 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE ' . $where . $expected,
            'exists' => 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE ' . $where,
        ];
    }

    public static function index(string $table, string $name, array $columns): array
    {
        self::identifier($table);
        self::identifier($name);
        foreach ($columns as $column) self::identifier($column);
        $where = "TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND INDEX_NAME = '$name'";
        return [
            'sql' => "ALTER TABLE `$table` ADD INDEX `$name` (`" . implode('`, `', $columns) . '`)',
            'check' => "SELECT COUNT(*) FROM (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list FROM information_schema.STATISTICS WHERE $where AND NON_UNIQUE = 1 AND INDEX_TYPE = 'BTREE' HAVING columns_list = '" . implode(',', $columns) . "') AS matching_index",
            'exists' => 'SELECT EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE ' . $where . ')',
        ];
    }

    public static function fulltext(string $table, string $name, array $columns): array
    {
        $step = self::index($table, $name, $columns);
        $step['sql'] = str_replace('ADD INDEX', 'ADD FULLTEXT INDEX', $step['sql']);
        $step['check'] = str_replace("INDEX_TYPE = 'BTREE'", "INDEX_TYPE = 'FULLTEXT'", $step['check']);
        return $step;
    }

    private static function identifier(string $name): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) throw new \InvalidArgumentException('Invalid migration identifier.');
    }
}
