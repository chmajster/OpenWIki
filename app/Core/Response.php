<?php

declare(strict_types=1);

namespace OpenWiki\Core;

final class Response
{
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        private readonly array $headers = []
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

    public static function redirect(string $location, int $status = 302): self
    {
        if (!str_starts_with($location, '/')) {
            throw new \InvalidArgumentException('Only local redirects are allowed.');
        }

        return new self('', $status, ['Location' => $location]);
    }

    public function send(): never
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        echo $this->body;
        exit;
    }
}
