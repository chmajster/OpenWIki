<?php

declare(strict_types=1);

namespace OpenWiki\Core;

use OpenWiki\Auth\AuthService;

final class Application
{
    private ?Database $database = null;
    private ?AuthService $auth = null;
    private readonly Router $router;
    private readonly View $view;

    private function __construct(private readonly string $basePath)
    {
        $this->router = new Router();
        $this->view = new View($basePath . '/resources/views', $this);
    }

    public static function boot(string $basePath): self
    {
        Env::load($basePath . '/.env');

        $timezone = Env::get('APP_TIMEZONE', 'UTC') ?? 'UTC';
        if (!@date_default_timezone_set($timezone)) {
            date_default_timezone_set('UTC');
        }

        Session::start();

        return new self($basePath);
    }

    public function basePath(string $suffix = ''): string
    {
        return $this->basePath . ($suffix === '' ? '' : '/' . ltrim($suffix, '/'));
    }

    public function installed(): bool
    {
        $key = Env::get('APP_KEY');
        return is_file($this->basePath('storage/installed.lock'))
            && is_string($key)
            && strlen($key) >= 32;
    }

    public function database(): Database
    {
        if (!$this->installed()) {
            throw new \RuntimeException('Application is not installed.');
        }

        return $this->database ??= Database::fromEnvironment();
    }

    public function auth(): AuthService
    {
        return $this->auth ??= new AuthService($this);
    }

    public function router(): Router { return $this->router; }
    public function view(): View { return $this->view; }

    public function name(): string
    {
        return Env::get('APP_NAME', 'OpenWiki') ?? 'OpenWiki';
    }

    public function version(): string
    {
        return '0.1.0';
    }
}
