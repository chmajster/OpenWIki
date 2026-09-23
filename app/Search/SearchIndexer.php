<?php

declare(strict_types=1);

namespace OpenWiki\Search;

use OpenWiki\Core\Database;

final class SearchIndexer
{
    public function __construct(private readonly Database $database)
    {
    }

    public function rebuild(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT id, content_html, content_text
             FROM pages
             WHERE deleted_at IS NULL
             ORDER BY id ASC'
        );

        $updated = 0;
        $unchanged = 0;

        foreach ($rows as $row) {
            $text = $this->plainText((string) $row['content_html']);
            if (hash_equals((string) $row['content_text'], $text)) {
                $unchanged++;
                continue;
            }

            $this->database->execute(
                'UPDATE pages
                 SET content_text = :content_text
                 WHERE id = :id AND deleted_at IS NULL',
                [
                    'content_text' => $text,
                    'id' => (int) $row['id'],
                ]
            );
            $updated++;
        }

        return [
            'scanned' => count($rows),
            'updated' => $updated,
            'unchanged' => $unchanged,
        ];
    }

    public function plainText(string $html): string
    {
        $text = html_entity_decode(
            strip_tags($html),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
