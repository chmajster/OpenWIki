<?php

declare(strict_types=1);

namespace OpenWiki\Core;

final class Response
{
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        private readonly array $headers = [],
        private readonly ?string $filePath = null,
        private readonly bool $deleteFileAfterSend = false
    ) {
    }

    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8'] + $headers);
    }

    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return new self($json, $status, ['Content-Type' => 'application/json; charset=UTF-8'] + $headers);
    }

    public static function file(
        string $path,
        string $mimeType,
        string $downloadName,
        bool $inline = false,
        bool $deleteAfterSend = false
    ): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('File is not available.');
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($downloadName)) ?: 'download';
        $disposition = ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"';

        return new self('', 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $path, $deleteAfterSend);
    }

    public static function download(string $content, string $mimeType, string $downloadName): self
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($downloadName)) ?: 'download';

        return new self($content, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => 'attachment; filename="' . $safeName . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        if (!str_starts_with($location, '/')) {
            throw new \InvalidArgumentException('Only local redirects are allowed.');
        }

        return new self('', $status, ['Location' => $location]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): never
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        if ($this->filePath !== null) {
            $handle = fopen($this->filePath, 'rb');
            if ($handle === false) {
                http_response_code(500);
                exit;
            }
            fpassthru($handle);
            fclose($handle);
            if ($this->deleteFileAfterSend) {
                @unlink($this->filePath);
            }
            exit;
        }

        echo $this->body;
        exit;
    }
}
