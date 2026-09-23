<?php

declare(strict_types=1);

namespace OpenWiki\Wiki;

use OpenWiki\Core\Database;

final class PageTemplateService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function all(): array
    {
        return $this->database->fetchAll(
            'SELECT pt.id, pt.name, pt.description, pt.content_html, pt.content_markdown,
                    pt.created_by, pt.is_system, pt.created_at, pt.updated_at,
                    u.username AS creator_username
             FROM page_templates pt
             LEFT JOIN users u ON u.id = pt.created_by
             ORDER BY pt.is_system DESC, pt.name ASC'
        );
    }

    public function find(int $id): ?array
    {
        return $this->database->fetchOne(
            'SELECT pt.*, u.username AS creator_username
             FROM page_templates pt
             LEFT JOIN users u ON u.id = pt.created_by
             WHERE pt.id = :id
             LIMIT 1',
            ['id' => $id]
        );
    }

    public function save(?int $id, array $input, int $actorId): int
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 191) {
            throw new \InvalidArgumentException('Template name is required and may contain at most 191 characters.');
        }

        $description = trim((string) ($input['description'] ?? ''));
        if (mb_strlen($description) > 500) {
            throw new \InvalidArgumentException('Template description may contain at most 500 characters.');
        }

        $format = (string) ($input['content_format'] ?? 'visual');
        $content = (new ContentService())->normalize(
            $format,
            (string) ($input['content_html'] ?? ''),
            (string) ($input['content_markdown'] ?? '')
        );

        if ($id !== null) {
            $existing = $this->find($id);
            if ($existing === null) {
                throw new \InvalidArgumentException('Template not found.');
            }
            if ((bool) $existing['is_system']) {
                throw new \InvalidArgumentException('System templates are read-only.');
            }

            $this->database->execute(
                'UPDATE page_templates
                 SET name = :name,
                     description = :description,
                     content_html = :content_html,
                     content_markdown = :content_markdown,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND is_system = 0',
                [
                    'id' => $id,
                    'name' => $name,
                    'description' => $description === '' ? null : $description,
                    'content_html' => $content['html'],
                    'content_markdown' => $content['markdown'],
                ]
            );

            return $id;
        }

        return $this->database->insert(
            'INSERT INTO page_templates
             (name, description, content_html, content_markdown, created_by, is_system, created_at, updated_at)
             VALUES
             (:name, :description, :content_html, :content_markdown, :created_by, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'name' => $name,
                'description' => $description === '' ? null : $description,
                'content_html' => $content['html'],
                'content_markdown' => $content['markdown'],
                'created_by' => $actorId,
            ]
        );
    }

    public function delete(int $id): bool
    {
        $template = $this->find($id);
        if ($template === null) {
            return false;
        }
        if ((bool) $template['is_system']) {
            throw new \InvalidArgumentException('System templates cannot be deleted.');
        }

        return $this->database->execute(
            'DELETE FROM page_templates WHERE id = :id AND is_system = 0',
            ['id' => $id]
        ) === 1;
    }
}
