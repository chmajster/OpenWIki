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
        return array_map(fn (array $row): array => (new ContentService())->safeProjection($row), $this->database->fetchAll(
            'SELECT pt.id, pt.name, pt.description, pt.content_html, pt.content_markdown,
                    pt.created_by, pt.is_system, pt.created_at, pt.updated_at,
                    u.username AS creator_username
             FROM page_templates pt
             LEFT JOIN users u ON u.id = pt.created_by
             ORDER BY pt.is_system DESC, pt.name ASC'
        ));
    }

    public function find(int $id): ?array
    {
        return (new ContentService())->safeProjection($this->database->fetchOne(
            'SELECT pt.*, u.username AS creator_username
             FROM page_templates pt
             LEFT JOIN users u ON u.id = pt.created_by
             WHERE pt.id = :id
             LIMIT 1',
            ['id' => $id]
        ));
    }

    /** Existing stored templates retain their source; missing starter structures need no data rewrite. */
    public function catalog(): array
    {
        $starters = [
            'Technical Documentation' => ['technical', 'Dokumentacja techniczna', 'Techniczne', 'Architektura, konfiguracja i utrzymanie systemu.', '<h2>Przegląd</h2><p></p><h2>Architektura</h2><p></p><h2>Konfiguracja</h2><pre><code data-language="yaml"># Konfiguracja</code></pre><h2>Utrzymanie</h2><p></p>'],
            'Step-by-step Guide' => ['guide', 'Instrukcja krok po kroku', 'Wiedza', 'Cel, wymagania i uporządkowane kroki wykonania.', '<h2>Cel</h2><p></p><h2>Wymagania</h2><ul><li><p></p></li></ul><h2>Kroki</h2><ol><li><p>Pierwszy krok</p></li><li><p>Drugi krok</p></li></ol><h2>Weryfikacja</h2><p></p>'],
            'Meeting Notes' => ['meeting', 'Notatka ze spotkania', 'Zespół', 'Agenda, decyzje i zadania do wykonania.', '<h2>Uczestnicy i agenda</h2><p></p><h2>Notatki</h2><p></p><h2>Decyzje</h2><p></p><h2>Zadania</h2><ul data-type="taskList"><li data-type="taskItem" data-checked="false"><p>Ustal następny krok</p></li></ul>'],
            'API Documentation' => ['api', 'Dokumentacja API', 'Techniczne', 'Endpointy, parametry, przykłady i odpowiedzi.', '<h2>Opis API</h2><p></p><h2>Uwierzytelnianie</h2><p></p><h2>Endpointy</h2><table><tbody><tr><th>Metoda</th><th>Ścieżka</th><th>Opis</th></tr><tr><td>GET</td><td></td><td></td></tr></tbody></table><h2>Przykład</h2><pre><code data-language="json">{}</code></pre><h2>Błędy</h2><p></p>'],
            'Standard Operating Procedure' => ['operations', 'Procedura operacyjna', 'Operacje', 'Odpowiedzialność, kroki, kontrola i cofnięcie operacji.', '<h2>Cel i odpowiedzialność</h2><p></p><h2>Warunki wejściowe</h2><p></p><h2>Wykonanie</h2><ol><li><p></p></li></ol><h2>Kontrola wyniku</h2><p></p><h2>Wycofanie</h2><p></p>'],
            'Knowledge Base' => ['knowledge', 'Baza wiedzy', 'Wiedza', 'Pytanie, rozwiązanie i powiązane materiały.', '<h2>Pytanie lub problem</h2><p></p><h2>Rozwiązanie</h2><p></p><h2>Powiązane materiały</h2><ul><li><p></p></li></ul>'],
            'Project Overview' => ['project', 'Opis projektu', 'Zespół', 'Cel, zakres, kamienie milowe i decyzje.', '<h2>Cel projektu</h2><p></p><h2>Zakres</h2><p></p><h2>Kamienie milowe</h2><table><tbody><tr><th>Etap</th><th>Termin</th><th>Właściciel</th></tr><tr><td></td><td></td><td></td></tr></tbody></table><h2>Ryzyka i decyzje</h2><p></p>'],
            'Incident Report' => ['incident', 'Raport incydentu', 'Operacje', 'Wpływ, oś czasu, przyczyna i działania naprawcze.', '<h2>Podsumowanie</h2><p></p><h2>Wpływ</h2><p></p><h2>Oś czasu</h2><table><tbody><tr><th>Czas</th><th>Zdarzenie</th></tr><tr><td></td><td></td></tr></tbody></table><h2>Przyczyna</h2><p></p><h2>Działania naprawcze</h2><ul data-type="taskList"><li data-type="taskItem" data-checked="false"><p></p></li></ul>'],
        ];
        $stored = $this->all(); $systems = [];
        foreach ($stored as $row) if ($row['is_system']) $systems[$row['name']] = $row;
        $catalog = [];
        foreach ($starters as $name => [$key, $label, $category, $description, $html]) {
            $source = $systems[$name] ?? ['id' => 'builtin:' . $key, 'content_format' => 'visual', 'content_html' => $html, 'content_markdown' => null, 'is_system' => 1];
            $catalog[] = $source + ['display_name' => $label, 'category' => $category, 'summary' => $description, 'icon' => $key];
        }
        foreach ($stored as $row) if (!$row['is_system'] || !isset($starters[$row['name']])) {
            $catalog[] = $row + ['display_name' => $row['name'], 'category' => $row['is_system'] ? 'Wiedza' : 'Własne', 'summary' => $row['description'] ?? '', 'icon' => 'document'];
        }
        return array_map(fn (array $row): array => (new ContentService())->safeProjection($row), $catalog);
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
