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
        $data['locale'] = I18n::locale();
        $data['t'] = static fn (string $text): string => I18n::text($text);
        $data['e'] = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $data['flashSuccess'] = Session::pullFlash('success');
        $data['flashError'] = Session::pullFlash('error');
        // Preserve the render argument as the authoritative view name.
        $data['template'] = $template;
        // The page editor uses its own full-screen shell and stylesheet.
        if ($template === 'pages/editor') {
            $data['editorWorkspace'] = true;
        }

        // Render the content view in its own scope so loop variables declared
        // by a view cannot overwrite render state (for example, $template).
        $content = (static function (string $__viewFile, array $__viewData): string {
            $data = $__viewData;
            extract($__viewData, EXTR_SKIP);

            ob_start();
            require $__viewFile;
            return (string) ob_get_clean();
        })($file, $data);

        if (!$withLayout) {
            return $content;
        }

        $layout = $this->viewsPath . '/layouts/app.php';
        if (!is_file($layout)) {
            return $content;
        }

        return (static function (string $__layoutFile, array $__layoutData, string $__content): string {
            $data = $__layoutData;
            $content = $__content;
            extract($__layoutData, EXTR_SKIP);

            ob_start();
            require $__layoutFile;
            return (string) ob_get_clean();
        })($layout, $data, $content);
    }
}
