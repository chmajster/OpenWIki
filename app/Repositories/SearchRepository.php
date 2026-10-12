<?php

declare(strict_types=1);

namespace OpenWiki\Repositories;

use OpenWiki\Core\Database;

final class SearchRepository
{
    // Default InnoDB list on MySQL/MariaDB. Reading its information_schema table requires PROCESS,
    // which the application database account must not need. Custom lists use the literal fallback.
    private const STOPWORDS = ['a','about','an','are','as','at','be','by','com','de','en','for','from','how','i','in','is','it','la','of','on','or','that','the','this','to','was','what','when','where','who','will','with','und','www'];
    public function __construct(private readonly Database $database)
    {
    }

    public function candidates(string $query, string $type, array $filters, bool $authenticated): array
    {
        $plans = new \OpenWiki\Search\SearchQuery($this->usesFulltext($query));
        $rows = [];
        foreach ($type === 'all' ? array_diff(\OpenWiki\Search\SearchInput::TYPES, ['all']) : [$type] as $kind) {
            if ($kind === 'user' && (!$authenticated || ($filters['space'] ?? '') !== '' || ($filters['tag'] ?? '') !== '')) continue;
            if ($kind === 'space' && (($filters['author'] ?? '') !== '' || ($filters['tag'] ?? '') !== '')) continue;
            if (($filters['visibility'] ?? 'all') === 'working' && !in_array($kind, ['page', 'attachment'], true)) continue;
            foreach (in_array($kind, ['page', 'attachment'], true) && ($filters['visibility'] ?? 'all') !== 'published' ? [false, true] : [false] as $working) {
                if ($kind === 'attachment' && ($filters['visibility'] ?? '') === 'working' && !$working) continue;
                [$sql, $params] = $plans->build($kind, $query, $filters, null, $working);
                foreach ($this->database->fetchAll($sql, $params) as $row) { $row['branch'] = $working; $rows[] = $row; }
            }
        }
        return $rows;
    }

    public function details(string $query, array $filters, array $candidates): array
    {
        $groups = []; $result = []; $plans = new \OpenWiki\Search\SearchQuery(false);
        foreach ($candidates as $row) $groups[$row['type'] . ':' . (int) $row['branch']][] = (int) $row['id'];
        foreach ($groups as $key => $ids) {
            [$type, $working] = explode(':', $key);
            [$sql, $params] = $plans->build($type, $query, $filters, $ids, (bool) $working);
            foreach ($this->database->fetchAll($sql, $params) as $row) $result[\OpenWiki\Search\SearchService::key($row)] = $row;
        }
        return $result;
    }

    private function usesFulltext(string $query): bool
    {
        // Punctuation, quoted phrases and short technical names retain literal substring semantics.
        if (preg_match('/^[\p{L}\p{N}]+(?:\s+[\p{L}\p{N}]+)*\*?$/uD', $query) !== 1) return false;
        $config = $this->database->fetchOne('SELECT @@innodb_ft_min_token_size AS minimum, @@innodb_ft_max_token_size AS maximum, @@innodb_ft_server_stopword_table AS custom_stopwords, @@innodb_ft_user_stopword_table AS user_stopwords');
        if (($config['custom_stopwords'] ?? '') !== '' || ($config['user_stopwords'] ?? '') !== '') return false;
        foreach (preg_split('/\s+/u', \OpenWiki\Search\SearchInput::literal($query), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if (mb_strlen($word) < (int) $config['minimum'] || mb_strlen($word) > (int) $config['maximum'] || in_array(mb_strtolower($word), self::STOPWORDS, true)) return false;
        }
        return true;
    }

}
