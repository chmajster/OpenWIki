<?php

declare(strict_types=1);

namespace OpenWiki\Core;

use OpenWiki\Security\Csrf;

final class View
{
    public function __construct(
        private readonly string $viewsPath,
        private readonly Application $app
    ) {
    }

    public function render(string $template, array $data = [], bool $withLayout = true): string
    {
        $file = $this->viewsPath . '/' . ltrim($template, '/') . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $template);
        }

        $data['app'] = $this->app;
        $data['csrfToken'] = Csrf::token();
        $data['currentUser'] = $this->app->auth()->user();
        $data['flashSuccess'] = Session::pullFlash('success');
        $data['flashError'] = Session::pullFlash('error');

        extract($data, EXTR_SKIP);

        ob_start();
        require $file;
        $content = (string) ob_get_clean();

        if (!$withLayout) {
            return $content;
        }

        $layout = $this->viewsPath . '/layouts/app.php';
        if (!is_file($layout)) {
            return $content;
        }

        ob_start();
        require $layout;
        return (string) ob_get_clean();
    }
}
