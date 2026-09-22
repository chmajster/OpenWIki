<?php

declare(strict_types=1);

namespace OpenWiki\Import;

use OpenWiki\Core\Database;
use OpenWiki\Core\Env;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Wiki\ContentService;
use OpenWiki\Wiki\Slugger;
use OpenWiki\Wiki\WikiMetadataService;
use ZipArchive;

final class DocumentImportService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $basePath
    ) {
    }

    public function importUploaded(
        array $space,
        int $userId,
        array $file,
        ?int $parentId = null
    ): array {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $message = match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Import file exceeds the configured upload limit.',
                UPLOAD_ERR_PARTIAL => 'Import upload was incomplete.',
                UPLOAD_ERR_NO_FILE => 'Choose a file to import.',
                default => 'Import upload failed.',
            };
            throw new \InvalidArgumentException($message);
        }

        $path = (string) ($file['tmp_name'] ?? '');
        if ($path === '' || !is_uploaded_file($path)) {
            throw new \InvalidArgumentException('Invalid uploaded import file.');
        }

        return $this->importFromPath(
            $space,
            $userId,
            $path,
            (string) ($file['name'] ?? 'import'),
            $parentId
        );
    }

    public function importFromPath(
        array $space,
        int $userId,
        string $path,
        string $originalName,
        ?int $parentId = null
    ): array {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Import source is not readable.');
        }

        $size = filesize($path);
        if ($size === false) {
            throw new \RuntimeException('Unable to determine import size.');
        }
        $maxBytes = Env::int('IMPORT_MAX_BYTES', 50 * 1024 * 1024);
        if ($size > $maxBytes) {
            throw new \InvalidArgumentException('Import file exceeds the maximum allowed size.');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        return match ($extension) {
            'md', 'markdown' => $this->importSingle(
                $space,
                $userId,
                $parentId,
                $originalName,
                $this->readFile($path, 10 * 1024 * 1024),
                'markdown'
            ),
            'html', 'htm' => $this->importSingle(
                $space,
                $userId,
                $parentId,
                $originalName,
                $this->readFile($path, 10 * 1024 * 1024),
                'visual'
            ),
            'zip' => $this->importZip($space, $userId, $parentId, $path),
            default => throw new \InvalidArgumentException(
                'Supported import formats are Markdown, HTML and ZIP.'
            ),
        };
    }

    private function importSingle(
        array $space,
        int $userId,
        ?int $parentId,
        string $sourceName,
        string $content,
        string $format
    ): array {
        $pageId = $this->database->transaction(function () use (
            $space,
            $userId,
            $parentId,
            $sourceName,
            $content,
            $format
        ): int {
            return $this->createDocument(
                $space,
                $userId,
                $parentId,
                $this->titleFromPath($sourceName),
                $content,
                $format,
                $sourceName
            );
        });

        (new WikiMetadataService($this->database))->refreshSpaceLinks((int) $space['id']);

        return [
            'imported_pages' => 1,
            'section_pages' => 0,
            'skipped_files' => 0,
            'page_ids' => [$pageId],
        ];
    }

    private function importZip(
        array $space,
        int $userId,
        ?int $parentId,
        string $path
    ): array {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('PHP ZIP extension is not available.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException('Unable to open ZIP documentation archive.');
        }

        try {
            if ($zip->numFiles > 500) {
                throw new \InvalidArgumentException('ZIP archive contains more than 500 entries.');
            }

            $documents = [];
            $skipped = 0;
            $totalUncompressed = 0;
            $maxTotal = Env::int('IMPORT_ZIP_MAX_UNCOMPRESSED_BYTES', 100 * 1024 * 1024);
            $maxEntry = Env::int('IMPORT_ZIP_MAX_ENTRY_BYTES', 10 * 1024 * 1024);

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) {
                    throw new \InvalidArgumentException('ZIP archive contains an unreadable entry.');
                }

                $name = $this->safeZipPath((string) ($stat['name'] ?? ''));
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }

                $this->rejectZipSymlink($zip, $index, $name);

                $entrySize = (int) ($stat['size'] ?? 0);
                if ($entrySize < 0 || $entrySize > $maxEntry) {
                    throw new \InvalidArgumentException('ZIP entry is too large: ' . $name);
                }

                $totalUncompressed += $entrySize;
                if ($totalUncompressed > $maxTotal) {
                    throw new \InvalidArgumentException('ZIP archive exceeds the allowed uncompressed size.');
                }

                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($extension, ['md', 'markdown', 'html', 'htm'], true)) {
                    $skipped++;
                    continue;
                }

                $documents[] = [
                    'index' => $index,
                    'path' => $name,
                    'format' => in_array($extension, ['md', 'markdown'], true) ? 'markdown' : 'visual',
                    'size' => $entrySize,
                ];
            }

            if ($documents === []) {
                throw new \InvalidArgumentException('ZIP archive contains no Markdown or HTML documentation files.');
            }

            usort($documents, static function (array $left, array $right): int {
                $depth = substr_count($left['path'], '/') <=> substr_count($right['path'], '/');
                return $depth !== 0 ? $depth : strcmp($left['path'], $right['path']);
            });

            $result = $this->database->transaction(function () use (
                $zip,
                $documents,
                $space,
                $userId,
                $parentId,
                $skipped
            ): array {
                $folderPages = [];
                $pageIds = [];
                $sectionCount = 0;

                foreach ($documents as $document) {
                    $segments = explode('/', $document['path']);
                    $fileName = array_pop($segments);
                    $currentParent = $parentId;
                    $folderPath = '';

                    foreach ($segments as $segment) {
                        $folderPath = $folderPath === '' ? $segment : $folderPath . '/' . $segment;
                        if (isset($folderPages[$folderPath])) {
                            $currentParent = $folderPages[$folderPath];
                            continue;
                        }

                        $sectionId = $this->createDocument(
                            $space,
                            $userId,
                            $currentParent,
                            $this->cleanTitle($segment),
                            '<p>Imported documentation section.</p>',
                            'visual',
                            $folderPath
                        );
                        $folderPages[$folderPath] = $sectionId;
                        $currentParent = $sectionId;
                        $pageIds[] = $sectionId;
                        $sectionCount++;
                    }

                    $content = $zip->getFromIndex((int) $document['index'], (int) $document['size'] + 1);
                    if (!is_string($content)) {
                        throw new \RuntimeException('Unable to read ZIP entry: ' . $document['path']);
                    }

                    $pageIds[] = $this->createDocument(
                        $space,
                        $userId,
                        $currentParent,
                        $this->titleFromPath((string) $fileName),
                        $content,
                        (string) $document['format'],
                        (string) $document['path']
                    );
                }

                return [
                    'imported_pages' => count($documents),
                    'section_pages' => $sectionCount,
                    'skipped_files' => $skipped,
                    'page_ids' => $pageIds,
                ];
            });

            (new WikiMetadataService($this->database))->refreshSpaceLinks((int) $space['id']);
            return $result;
        } finally {
            $zip->close();
        }
    }

    private function createDocument(
        array $space,
        int $userId,
        ?int $parentId,
        string $title,
        string $source,
        string $format,
        string $sourceName
    ): int {
        $content = (new ContentService())->normalize(
            $format,
            $format === 'visual' ? $source : '',
            $format === 'markdown' ? $source : ''
        );

        $metadata = new WikiMetadataService($this->database);
        $decorated = $metadata->decorateWikiLinks(
            (int) $space['id'],
            (string) $space['space_key'],
            (string) $content['html']
        );

        $pageId = (new PageRepository($this->database))->create([
            'space_id' => (int) $space['id'],
            'parent_id' => $parentId,
            'title' => mb_substr($title, 0, 255),
            'slug' => $this->uniqueSlug((int) $space['id'], $title),
            'content_html' => $decorated['html'],
            'content_markdown' => $content['markdown'],
            'content_text' => $content['text'],
            'content_format' => $content['format'],
            'author_id' => $userId,
            'owner_id' => $userId,
            'status' => 'draft',
            'change_summary' => mb_substr('Imported from ' . $sourceName, 0, 500),
        ]);

        $metadata->syncLinks(
            $pageId,
            (int) $space['id'],
            $decorated['references']
        );

        return $pageId;
    }

    private function uniqueSlug(int $spaceId, string $title): string
    {
        $base = (new Slugger())->slug($title);
        $slug = $base;
        $counter = 2;

        while ($this->database->fetchOne(
            'SELECT id FROM pages WHERE space_id = :space_id AND slug = :slug LIMIT 1',
            ['space_id' => $spaceId, 'slug' => $slug]
        ) !== null) {
            $suffix = '-' . $counter;
            $slug = mb_substr($base, 0, max(1, 255 - strlen($suffix))) . $suffix;
            $counter++;
        }

        return $slug;
    }

    private function titleFromPath(string $path): string
    {
        $name = pathinfo(str_replace('\\', '/', $path), PATHINFO_FILENAME);
        return $this->cleanTitle($name);
    }

    private function cleanTitle(string $value): string
    {
        $value = preg_replace('/[_-]+/u', ' ', trim($value)) ?? trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        return $value === '' ? 'Imported page' : mb_substr($value, 0, 255);
    }

    private function readFile(string $path, int $maxBytes): string
    {
        $size = filesize($path);
        if ($size === false || $size > $maxBytes) {
            throw new \InvalidArgumentException('Imported document exceeds the allowed size.');
        }

        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new \RuntimeException('Unable to read imported document.');
        }

        return $content;
    }

    private function safeZipPath(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('ZIP entry contains a null byte.');
        }

        $path = str_replace('\\', '/', $path);
        if (
            str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:\//', $path) === 1
        ) {
            throw new \InvalidArgumentException('ZIP entry uses an absolute path.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new \InvalidArgumentException('ZIP entry contains path traversal.');
            }
        }

        return implode('/', array_values(array_filter(
            $segments,
            static fn (string $segment): bool => $segment !== '' && $segment !== '.'
        )));
    }

    private function rejectZipSymlink(ZipArchive $zip, int $index, string $name): void
    {
        $opsys = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return;
        }

        if ($opsys === ZipArchive::OPSYS_UNIX) {
            $mode = ($attributes >> 16) & 0xF000;
            if ($mode === 0xA000) {
                throw new \InvalidArgumentException('ZIP symlink entries are not allowed: ' . $name);
            }
        }
    }
}
