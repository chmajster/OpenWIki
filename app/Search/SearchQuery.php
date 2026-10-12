<?php
declare(strict_types=1);
namespace OpenWiki\Search;

/** Parameterized query plans: candidates carry IDs and scores; bodies are sliced only for the displayed page. */
final class SearchQuery
{
    public function __construct(private readonly bool $fulltext)
    {
    }

    public function build(string $type, string $query, array $filters, ?array $ids = null, bool $working = false): array
    {
        $needle = SearchInput::literal($query); $like = SearchInput::like($needle);
        $prefix = substr($like, 1); $params = [];
        $title = ''; $body = "''"; $date = ''; $created = ''; $page = 'NULL'; $space = 'NULL'; $revision = '0';
        $from = ''; $where = []; $match = ''; $matchParams = []; $extra = '';
        if ($type === 'page' || $type === 'comment' || $type === 'attachment' || $type === 'tag') {
            $from = 'pages p INNER JOIN spaces s ON s.id = p.space_id';
            $where = ['p.deleted_at IS NULL', 's.deleted_at IS NULL'];
            $page = 'p.id'; $space = 'p.space_id'; $title = 'p.title'; $body = 'p.content_text';
            $date = 'p.updated_at'; $created = 'p.created_at'; $revision = 'COALESCE(p.published_revision,p.version)';
            if ($type !== 'page' && ($filters['visibility'] ?? '') === 'published') $where[] = 'p.status = "published"';
        }
        if ($type === 'page') {
            $from .= $working ? ' INNER JOIN page_revisions r ON r.page_id = p.id AND r.revision_number = p.version'
                : ' LEFT JOIN page_revisions pub ON pub.page_id = p.id AND pub.revision_number = p.published_revision';
            $author = $working ? 'r.author_id' : 'p.author_id';
            $from .= ' INNER JOIN users u ON u.id = ' . $author;
            if ($working) {
                $where[] = 'p.published_revision IS NOT NULL AND p.version <> p.published_revision';
                $title = 'r.title'; $body = 'r.content_text'; $date = 'r.created_at'; $revision = 'r.revision_number';
            } else {
                $date = 'COALESCE(pub.created_at,p.updated_at)';
                if (($filters['visibility'] ?? 'all') === 'published') $where[] = 'p.status = "published"';
                if (($filters['visibility'] ?? 'all') === 'working') $where[] = 'p.status <> "published" AND p.published_revision IS NULL';
            }
            $fields = [$title, 's.name', 's.space_key', 'u.username'];
            $conditions = array_map(static fn ($f) => "$f LIKE ? ESCAPE '!'", $fields);
            $matchParams = array_fill(0, count($fields), $like);
            if (!$this->fulltext) { $conditions[] = "$body LIKE ? ESCAPE '!'"; $matchParams[] = $like; }
            $conditions[] = $working ? "r.tags_json LIKE ? ESCAPE '!'" : "EXISTS (SELECT 1 FROM page_tags pt INNER JOIN tags t ON t.id = pt.tag_id WHERE pt.page_id = p.id AND t.name LIKE ? ESCAPE '!')";
            $matchParams[] = $like;
            $match = '(' . implode(' OR ', $conditions) . ')';
            if ($this->fulltext && $ids === null) {
                $tokens = preg_split('/\s+/u', $needle, -1, PREG_SPLIT_NO_EMPTY);
                $boolean = implode(' ', array_map(static fn ($t) => '+' . $t . '*', $tokens));
                // UNION materializes FT hits once. An IN subquery under OR can evaluate MATCH per row on MariaDB.
                $hitId = $working ? 'r.id' : 'p.id';
                $table = $working ? 'page_revisions' : 'pages';
                $from .= ' INNER JOIN (SELECT id AS hit_id FROM ' . $table . ' WHERE MATCH(title,content_text) AGAINST (? IN BOOLEAN MODE) UNION SELECT ' . $hitId . ' AS hit_id FROM ' . $from . ' WHERE ' . $match . ') hits ON hits.hit_id = ' . $hitId;
                array_unshift($matchParams, $boolean); $match = '1=1';
            }
            $id = 'p.id';
            $extra = ', ' . ($working ? 'COALESCE(r.slug,p.slug)' : 'p.slug') . ' AS slug, ' . ($working ? '"draft"' : 'p.status') . ' AS status, u.username AS author';
        } elseif ($type === 'space') {
            $from = 'spaces s'; $where = ['s.deleted_at IS NULL']; $id = 's.id'; $space = 's.id';
            $title = 's.name'; $body = 's.description'; $date = 's.updated_at'; $created = 's.created_at';
            $match = '(s.name LIKE ? ESCAPE \'!\' OR s.space_key LIKE ? ESCAPE \'!\' OR s.description LIKE ? ESCAPE \'!\')'; $matchParams = [$like, $like, $like];
            $extra = ', s.space_key AS `key`, s.visibility, s.status';
        } elseif ($type === 'user') {
            $from = 'users u'; $where = ['u.deleted_at IS NULL']; $id = 'u.id'; $title = 'u.username';
            $body = 'CONCAT_WS(" ",u.first_name,u.last_name)'; $date = 'u.updated_at'; $created = 'u.created_at';
            $match = '(u.username LIKE ? ESCAPE \'!\' OR u.first_name LIKE ? ESCAPE \'!\' OR u.last_name LIKE ? ESCAPE \'!\')'; $matchParams = [$like, $like, $like];
            $extra = ', u.username,u.first_name,u.last_name,u.status,u.auth_source';
        } elseif ($type === 'comment') {
            $from .= ' INNER JOIN comments c ON c.page_id = p.id INNER JOIN users u ON u.id = c.author_id';
            $where[] = 'c.deleted_at IS NULL'; $id = 'c.id'; $title = 'p.title'; $body = 'c.body_html'; $date = 'c.updated_at'; $created = 'c.created_at';
            $match = 'c.body_html LIKE ? ESCAPE \'!\''; $matchParams = [$like]; $extra = ', p.slug, u.username AS author';
        } elseif ($type === 'attachment') {
            $from .= ' INNER JOIN attachments a ON a.page_id = p.id INNER JOIN users u ON u.id = a.uploader_id';
            $legacy = '(s.review_workflow = 0 AND p.status = "published" AND (p.published_revision IS NULL OR p.published_revision = p.version))';
            if ($working) {
                $where[] = 'a.deleted_at IS NULL'; $where[] = '(p.published_revision IS NULL OR p.version <> p.published_revision OR s.review_workflow = 1)';
                $title = 'a.name'; $body = 'a.mime_type'; $date = 'a.updated_at'; $revision = 'p.version';
                $extra = ', a.name,a.mime_type,a.size_bytes,a.current_version AS attachment_version';
            } else {
                $from .= ' LEFT JOIN page_revision_attachments pa ON pa.page_id = p.id AND pa.revision_number = p.published_revision AND pa.attachment_id = a.id LEFT JOIN attachment_versions av ON av.attachment_id = pa.attachment_id AND av.version_number = pa.version_number';
                $where[] = '(' . $legacy . ' OR pa.attachment_id IS NOT NULL)';
                $where[] = '(a.deleted_at IS NULL OR pa.attachment_id IS NOT NULL)';
                $title = 'CASE WHEN ' . $legacy . ' THEN a.name ELSE pa.name END';
                $body = 'CASE WHEN ' . $legacy . ' THEN a.mime_type ELSE av.mime_type END';
                $date = 'CASE WHEN ' . $legacy . ' THEN a.updated_at ELSE av.created_at END';
                $extra = ', ' . $title . ' AS name, ' . $body . ' AS mime_type, CASE WHEN ' . $legacy . ' THEN a.size_bytes ELSE av.size_bytes END AS size_bytes, CASE WHEN ' . $legacy . ' THEN a.current_version ELSE pa.version_number END AS attachment_version';
            }
            $id = 'a.id'; $created = 'a.created_at'; $match = '(' . $title . " LIKE ? ESCAPE '!' OR " . $body . " LIKE ? ESCAPE '!')"; $matchParams = [$like, $like]; $extra .= ', p.title AS page_title,p.slug';
        } elseif ($type === 'tag') {
            $from .= ' INNER JOIN users u ON u.id = p.author_id INNER JOIN page_tags pt ON pt.page_id = p.id INNER JOIN tags t ON t.id = pt.tag_id';
            $id = 't.id'; $title = 't.name'; $body = "''"; $date = 't.created_at'; $created = 't.created_at';
            $match = '(t.name LIKE ? ESCAPE \'!\' OR t.slug LIKE ? ESCAPE \'!\')'; $matchParams = [$like, $like]; $extra = ', t.name,t.slug';
        } else throw new \InvalidArgumentException('Unknown search type.');

        $score = 'CASE WHEN LOWER(' . $title . ') = LOWER(?) THEN 120 WHEN ' . $title . " LIKE ? ESCAPE '!' THEN 90 WHEN " . $title . " LIKE ? ESCAPE '!' THEN 70 ELSE 20 END";
        $params = [$needle, $prefix, $like];
        $variant = $working ? '1' : ($type === 'page' ? '(p.status <> "published" AND p.published_revision IS NULL)' : '0');
        $select = 'SELECT "' . $type . '" AS type,' . $id . ' AS id,' . $page . ' AS page_id,' . $space . ' AS space_id,' . $revision . ' AS revision,' . $variant . ' AS working,' . $score . ' AS relevance,' . $date . ' AS updated_at,' . $created . ' AS created_at';
        if ($ids !== null) {
            $where[] = $id . ' IN (' . implode(',', array_map('intval', $ids)) . ')';
            $select .= ', ' . $title . ' AS title, ' . $this->snippet($body) . ' AS snippet' . $extra;
            $params[] = mb_strtolower($needle); $params[] = mb_strtolower(explode(' ', $needle)[0]);
        } else {
            $where[] = $match; array_push($params, ...$matchParams);
            $this->filters($where, $params, $filters, $type, $working, $created, $date);
        }
        return [$select . ' FROM ' . $from . ' WHERE ' . implode(' AND ', $where), $params];
    }

    private function snippet(string $column): string
    {
        return 'SUBSTRING(' . $column . ',GREATEST(1,COALESCE(NULLIF(LOCATE(? ,LOWER(' . $column . ')),0),NULLIF(LOCATE(?,LOWER(' . $column . ')),0),1)-90),260)';
    }

    private function filters(array &$where, array &$params, array $filters, string $type, bool $working, string $created, string $date): void
    {
        if (($filters['space'] ?? '') !== '' && $type !== 'user') {
            $where[] = "(s.name LIKE ? ESCAPE '!' OR s.space_key LIKE ? ESCAPE '!')"; array_push($params, SearchInput::like($filters['space']), SearchInput::like($filters['space']));
        }
        if (($filters['author'] ?? '') !== '' && $type !== 'space') { $where[] = "u.username LIKE ? ESCAPE '!'"; $params[] = SearchInput::like($filters['author']); }
        if (($filters['tag'] ?? '') !== '' && !in_array($type, ['space', 'user'], true)) {
            if ($working && $type === 'page') { $where[] = 'JSON_CONTAINS(COALESCE(r.tags_json,"[]"), ?)'; $params[] = json_encode($filters['tag'], JSON_THROW_ON_ERROR); }
            else { $where[] = 'EXISTS (SELECT 1 FROM page_tags fpt INNER JOIN tags ft ON ft.id = fpt.tag_id WHERE fpt.page_id = p.id AND (ft.name = ? OR ft.slug = ?))'; array_push($params, $filters['tag'], $filters['tag']); }
        }
        foreach (['created_from' => [$created, '>='], 'created_to' => [$created, '<='], 'updated_from' => [$date, '>='], 'updated_to' => [$date, '<=']] as $key => [$column, $operator]) if (($filters[$key] ?? '') !== '') { $where[] = $column . ' ' . $operator . ' ?'; $params[] = $filters[$key]; }
    }
}
