<?php

declare(strict_types=1);

namespace OpenWiki\Wiki;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use OpenWiki\Core\Database;

final class WikiMetadataService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function normalizeTags(string|array|null $input): array
    {
        $values = is_array($input) ? $input : preg_split('/[,\n]+/u', (string) $input);
        $tags = [];

        foreach ($values ?: [] as $value) {
            $name = trim((string) $value);
            $name = ltrim($name, '#');
            $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > 100) {
                throw new \InvalidArgumentException('A tag may contain at most 100 characters.');
            }

            $slug = (new Slugger())->slug($name);
            $tags[$slug] = ['name' => $name, 'slug' => $slug];

            if (count($tags) > 30) {
                throw new \InvalidArgumentException('A page may contain at most 30 tags.');
            }
        }

        return array_values($tags);
    }

    public function syncTags(int $pageId, string|array|null $input): void
    {
        $tags = $this->normalizeTags($input);

        $this->database->transaction(function (Database $db) use ($pageId, $tags): void {
            $db->execute('DELETE FROM page_tags WHERE page_id = :page_id', ['page_id' => $pageId]);

            foreach ($tags as $tag) {
                $db->execute(
                    'INSERT INTO tags (name, slug, created_at)
                     VALUES (:name, :slug, UTC_TIMESTAMP())
                     ON DUPLICATE KEY UPDATE name = VALUES(name)',
                    ['name' => $tag['name'], 'slug' => $tag['slug']]
                );

                $row = $db->fetchOne('SELECT id FROM tags WHERE slug = :slug LIMIT 1', ['slug' => $tag['slug']]);
                if ($row === null) {
                    throw new \RuntimeException('Unable to resolve synchronized tag.');
                }

                $db->execute(
                    'INSERT IGNORE INTO page_tags (page_id, tag_id) VALUES (:page_id, :tag_id)',
                    ['page_id' => $pageId, 'tag_id' => (int) $row['id']]
                );
            }
        });
    }

    public function tagsForPage(int $pageId): array
    {
        return $this->database->fetchAll(
            'SELECT t.id, t.name, t.slug
             FROM tags t
             INNER JOIN page_tags pt ON pt.tag_id = t.id
             WHERE pt.page_id = :page_id
             ORDER BY t.name ASC',
            ['page_id' => $pageId]
        );
    }

    public function tagBySlug(string $slug): ?array
    {
        return $this->database->fetchOne(
            'SELECT id, name, slug FROM tags WHERE slug = :slug LIMIT 1',
            ['slug' => $slug]
        );
    }

    public function pagesForTag(string $slug): array
    {
        return $this->database->fetchAll(
            'SELECT p.*, u.username AS author_username,
                    s.space_key, s.name AS space_name, s.visibility AS space_visibility,
                    s.owner_id AS space_owner_id, s.status AS space_status, s.deleted_at AS space_deleted_at
             FROM pages p
             INNER JOIN page_tags pt ON pt.page_id = p.id
             INNER JOIN tags t ON t.id = pt.tag_id
             INNER JOIN users u ON u.id = p.author_id
             INNER JOIN spaces s ON s.id = p.space_id
             WHERE t.slug = :slug AND p.deleted_at IS NULL AND s.deleted_at IS NULL
             ORDER BY p.updated_at DESC',
            ['slug' => $slug]
        );
    }

    public function decorateWikiLinks(int $spaceId, string $spaceKey, string $html): array
    {
        if ($html === '' || !str_contains($html, '[[')) {
            return ['html' => $html, 'references' => []];
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<!doctype html><html><body><div id="openwiki-wiki-root">' . $html . '</div></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('openwiki-wiki-root');
        if (!$root) {
            return ['html' => $html, 'references' => []];
        }

        $references = [];
        $cache = [];
        $this->linkifyChildren($document, $root, $spaceId, $spaceKey, $references, $cache);

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return ['html' => $result, 'references' => array_values($references)];
    }

    public function syncLinks(int $pageId, int $spaceId, array $references): void
    {
        $unique = [];
        foreach ($references as $reference) {
            $reference = trim((string) $reference);
            if ($reference === '' || mb_strlen($reference) > 255) {
                continue;
            }
            $unique[mb_strtolower($reference)] = $reference;
        }

        $this->database->transaction(function (Database $db) use ($pageId, $spaceId, $unique): void {
            $db->execute('DELETE FROM page_links WHERE source_page_id = :page_id', ['page_id' => $pageId]);

            foreach ($unique as $reference) {
                $target = $this->resolveTarget($spaceId, $reference);
                $db->execute(
                    'INSERT INTO page_links
                     (source_page_id, target_page_id, target_reference, is_broken, created_at)
                     VALUES (:source_page_id, :target_page_id, :target_reference, :is_broken, UTC_TIMESTAMP())',
                    [
                        'source_page_id' => $pageId,
                        'target_page_id' => $target === null ? null : (int) $target['id'],
                        'target_reference' => $reference,
                        'is_broken' => $target === null ? 1 : 0,
                    ]
                );
            }
        });
    }

    public function refreshSpaceLinks(int $spaceId): void
    {
        $links = $this->database->fetchAll(
            'SELECT l.id, l.target_reference
             FROM page_links l
             INNER JOIN pages p ON p.id = l.source_page_id
             WHERE p.space_id = :space_id AND p.deleted_at IS NULL',
            ['space_id' => $spaceId]
        );

        foreach ($links as $link) {
            $target = $this->resolveTarget($spaceId, (string) $link['target_reference']);
            $this->database->execute(
                'UPDATE page_links
                 SET target_page_id = :target_page_id, is_broken = :is_broken
                 WHERE id = :id',
                [
                    'target_page_id' => $target === null ? null : (int) $target['id'],
                    'is_broken' => $target === null ? 1 : 0,
                    'id' => (int) $link['id'],
                ]
            );
        }
    }

    public function resolveTarget(int $spaceId, string $reference): ?array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        try {
            $slug = (new Slugger())->slug($reference);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $target = $this->database->fetchOne(
            'SELECT id, title, slug
             FROM pages
             WHERE space_id = :space_id
               AND deleted_at IS NULL
               AND (title = :title_value OR slug = :slug_value)
             ORDER BY CASE WHEN title = :title_order THEN 0 ELSE 1 END, id ASC
             LIMIT 1',
            [
                'space_id' => $spaceId,
                'title_value' => $reference,
                'slug_value' => $slug,
                'title_order' => $reference,
            ]
        );
        if ($target !== null) {
            return $target;
        }

        return $this->database->fetchOne(
            'SELECT p.id, p.title, p.slug
             FROM page_redirects r
             INNER JOIN pages p ON p.id = r.page_id AND p.deleted_at IS NULL
             WHERE r.space_id = :space_id AND r.old_slug = :old_slug
             LIMIT 1',
            ['space_id' => $spaceId, 'old_slug' => $slug]
        );
    }

    public function backlinks(int $pageId): array
    {
        return $this->database->fetchAll(
            'SELECT p.id, p.space_id, p.title, p.slug, p.status, p.owner_id, p.author_id,
                    p.updated_at, u.username AS author_username,
                    s.space_key, s.name AS space_name, s.visibility AS space_visibility,
                    s.owner_id AS space_owner_id, s.status AS space_status, s.deleted_at AS space_deleted_at
             FROM page_links l
             INNER JOIN pages p ON p.id = l.source_page_id AND p.deleted_at IS NULL
             INNER JOIN users u ON u.id = p.author_id
             INNER JOIN spaces s ON s.id = p.space_id AND s.deleted_at IS NULL
             WHERE l.target_page_id = :page_id AND l.is_broken = 0
             ORDER BY p.updated_at DESC',
            ['page_id' => $pageId]
        );
    }

    public function brokenLinks(int $limit = 500): array
    {
        $limit = max(1, min($limit, 2000));

        return $this->database->fetchAll(
            'SELECT l.id, l.target_reference, l.created_at,
                    p.id AS source_page_id, p.title AS source_title, p.slug AS source_slug,
                    s.id AS space_id, s.space_key, s.name AS space_name
             FROM page_links l
             INNER JOIN pages p ON p.id = l.source_page_id AND p.deleted_at IS NULL
             INNER JOIN spaces s ON s.id = p.space_id AND s.deleted_at IS NULL
             WHERE l.is_broken = 1
             ORDER BY s.name ASC, p.title ASC, l.target_reference ASC
             LIMIT ' . $limit
        );
    }

    private function linkifyChildren(
        DOMDocument $document,
        DOMNode $node,
        int $spaceId,
        string $spaceKey,
        array &$references,
        array &$cache
    ): void {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                if (in_array(strtolower($child->tagName), ['a', 'code', 'pre'], true)) {
                    continue;
                }
                $this->linkifyChildren($document, $child, $spaceId, $spaceKey, $references, $cache);
                continue;
            }

            if (!$child instanceof DOMText || !str_contains($child->nodeValue ?? '', '[[')) {
                continue;
            }

            $value = (string) $child->nodeValue;
            if (!preg_match_all('/\[\[([^\]\r\n]{1,255})\]\]/u', $value, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $parent = $child->parentNode;
            if ($parent === null) {
                continue;
            }

            $offset = 0;
            foreach ($matches[0] as $index => $full) {
                [$rawMatch, $position] = $full;
                $reference = trim((string) $matches[1][$index][0]);
                if ($reference === '') {
                    continue;
                }

                if ($position > $offset) {
                    $parent->insertBefore(
                        $document->createTextNode(substr($value, $offset, $position - $offset)),
                        $child
                    );
                }

                $cacheKey = mb_strtolower($reference);
                if (!array_key_exists($cacheKey, $cache)) {
                    $cache[$cacheKey] = $this->resolveTarget($spaceId, $reference);
                }
                $target = $cache[$cacheKey];

                $link = $document->createElement('a');
                $link->setAttribute(
                    'href',
                    '/spaces/' . rawurlencode($spaceKey) . '/wiki/' . rawurlencode($reference)
                );
                $link->setAttribute('class', $target === null ? 'wiki-link wiki-link--broken' : 'wiki-link');
                $link->appendChild($document->createTextNode($reference));
                $parent->insertBefore($link, $child);

                $references[$cacheKey] = $reference;
                $offset = $position + strlen($rawMatch);
            }

            if ($offset < strlen($value)) {
                $parent->insertBefore($document->createTextNode(substr($value, $offset)), $child);
            }

            $parent->removeChild($child);
        }
    }
}
