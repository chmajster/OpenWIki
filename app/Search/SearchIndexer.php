<?php
declare(strict_types=1);
namespace OpenWiki\Search;

use OpenWiki\Core\Database;
use OpenWiki\Security\HtmlSanitizer;

final class SearchIndexer
{
    private const KEY = 'search.rebuild';
    public function __construct(private readonly Database $database) {}

    public function status(): array
    {
        $row = $this->database->fetchOne('SELECT setting_value FROM settings WHERE setting_key = ?', [self::KEY]);
        return $row === null ? ['state' => 'idle', 'scanned' => 0, 'total' => 0, 'updated' => 0, 'unchanged' => 0]
            : json_decode($row['setting_value'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** Repeated requests join the current job; a failed job resumes its committed cursor. */
    public function enqueue(): array
    {
        return $this->database->transaction(function (): array {
            $this->database->execute('INSERT IGNORE INTO settings (setting_key,setting_value,is_secret,updated_at) VALUES (?, "{}", 0, UTC_TIMESTAMP())', [self::KEY]);
            $row = $this->database->fetchOne('SELECT setting_value FROM settings WHERE setting_key = ? FOR UPDATE', [self::KEY]);
            $state = json_decode($row['setting_value'], true, 512, JSON_THROW_ON_ERROR);
            if (in_array($state['state'] ?? '', ['queued', 'running'], true)) return $state;
            if (($state['state'] ?? '') === 'failed') { $state['state'] = 'queued'; $state['error'] = null; }
            else {
                $bounds = $this->database->fetchOne('SELECT COALESCE(MAX(id),0) AS high_water, COUNT(*) AS total FROM pages');
                $state = ['state' => 'queued', 'cursor' => 0, 'high_water' => (int) $bounds['high_water'], 'total' => (int) $bounds['total'],
                    'scanned' => 0, 'updated' => 0, 'unchanged' => 0, 'started_at' => gmdate(DATE_ATOM), 'finished_at' => null, 'error' => null];
            }
            $this->persist($state); return $state;
        });
    }

    /** Lock the job and its bounded batch together. A crash rolls back both data and progress. */
    public function processDue(int $batchSize = 200): array
    {
        $batchSize = max(1, min(500, $batchSize));
        try {
            return $this->database->transaction(function () use ($batchSize): array {
                $row = $this->database->fetchOne('SELECT setting_value FROM settings WHERE setting_key = ? FOR UPDATE', [self::KEY]);
                if ($row === null) return $this->status();
                $state = json_decode($row['setting_value'], true, 512, JSON_THROW_ON_ERROR);
                if (!in_array($state['state'] ?? '', ['queued', 'running'], true)) return $state;
                $metadata = $this->database->fetchAll('SELECT id,OCTET_LENGTH(content_html) + OCTET_LENGTH(content_text) AS bytes FROM pages WHERE id > ? AND id <= ? ORDER BY id LIMIT ' . $batchSize . ' FOR UPDATE', [$state['cursor'], $state['high_water']]);
                $ids = []; $bytes = 0;
                foreach ($metadata as $page) {
                    if ($ids !== [] && $bytes + (int) $page['bytes'] > 4 * 1024 * 1024) break;
                    $ids[] = (int) $page['id']; $bytes += (int) $page['bytes'];
                }
                $rows = $ids === [] ? [] : $this->database->fetchAll('SELECT id,content_html,content_text,deleted_at FROM pages WHERE id IN (' . implode(',', $ids) . ') ORDER BY id');
                foreach ($rows as $page) {
                    $state['cursor'] = (int) $page['id']; $state['scanned']++;
                    $text = $page['deleted_at'] === null ? $this->plainText($page['content_html']) : $page['content_text'];
                    if ($text === $page['content_text']) $state['unchanged']++;
                    else { $this->database->execute('UPDATE pages SET content_text = ? WHERE id = ?', [$text, $page['id']]); $state['updated']++; }
                }
                $done = (count($rows) === count($metadata) && count($metadata) < $batchSize) || $state['cursor'] >= $state['high_water'];
                $state['state'] = $done ? 'completed' : 'running';
                if ($done) { $state['finished_at'] = gmdate(DATE_ATOM); $state['total'] = $state['scanned']; }
                $this->persist($state); return $state;
            });
        } catch (\Throwable $error) {
            error_log('[OpenWiki search index] ' . $error->getMessage());
            $this->database->transaction(function (): void {
                $row = $this->database->fetchOne('SELECT setting_value FROM settings WHERE setting_key = ? FOR UPDATE', [self::KEY]);
                if ($row === null) return;
                $state = json_decode($row['setting_value'], true, 512, JSON_THROW_ON_ERROR);
                if (!in_array($state['state'] ?? '', ['queued', 'running'], true)) return;
                $state['state'] = 'failed'; $state['error'] = 'Błąd indeksowania. Sprawdź log PHP i wznów zadanie.';
                $this->persist($state);
            });
            throw $error;
        }
    }

    public function rebuild(): array
    {
        $state = $this->enqueue();
        while (in_array($state['state'], ['queued', 'running'], true)) $state = $this->processDue();
        if ($state['state'] !== 'completed') throw new \RuntimeException($state['error'] ?? 'Index rebuild failed.');
        return $state;
    }

    public function plainText(string $html): string
    {
        $safe = (new HtmlSanitizer())->sanitize($html);
        $safe = preg_replace('~</(?:p|div|h[1-6]|li|tr|td|th|pre|blockquote)>|<br\s*/?>~i', ' ', $safe) ?? $safe;
        $text = html_entity_decode(strip_tags($safe), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function persist(array $state): void
    {
        $this->database->execute('UPDATE settings SET setting_value = ?, updated_at = UTC_TIMESTAMP() WHERE setting_key = ?', [json_encode($state, JSON_THROW_ON_ERROR), self::KEY]);
    }
}
