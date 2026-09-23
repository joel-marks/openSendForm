<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use RuntimeException;

/**
 * Minimal plain-PHP template renderer for the admin UI.
 *
 * Views are ordinary PHP files under templates/admin/. render() captures a
 * view's output and wraps it in layout.php (which receives it as $content).
 * The escaping helper h() (src/Admin/helpers.php) is loaded once and used
 * throughout the templates; nothing here emits unescaped user input.
 *
 * The inline-SVG icon() helper (src/Admin/icons.php) is loaded alongside it
 * so templates can render vendored Lucide glyphs.
 */
final class TemplateRenderer
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/');
        require_once __DIR__ . '/helpers.php';
        require_once __DIR__ . '/icons.php';
        require_once __DIR__ . '/appbar.php';
    }

    /**
     * Render a view wrapped in the layout.
     *
     * @param array<string, mixed> $vars
     */
    public function render(string $view, array $vars = []): string
    {
        $content = $this->capture($view, $vars);

        return $this->capture('layout', ['content' => $content] + $vars);
    }

    /**
     * Render a single template file in isolation and return its output.
     *
     * @param array<string, mixed> $vars
     */
    private function capture(string $view, array $vars): string
    {
        $file = $this->directory . '/' . $view . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Admin template not found: {$view}");
        }

        // Extract vars into the template's local scope; never clobber $file.
        extract($vars, EXTR_SKIP);

        ob_start();
        require $file;

        return (string) ob_get_clean();
    }
}
