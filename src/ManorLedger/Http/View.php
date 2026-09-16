<?php
/**
 * Renders templates/*.php in an isolated scope. Variables are passed
 * explicitly; templates see only `$vars` keys plus the `e()` helper.
 */
declare(strict_types=1);

namespace ManorLedger\Http;

use RuntimeException;
use Throwable;

final class View
{
    public function __construct(private readonly string $templatesDir, private readonly string $assetVersion)
    {
    }

    public function exists(string $template): bool
    {
        return is_file($this->path($template));
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        $file = $this->path($template);
        if (!is_file($file)) {
            throw new RuntimeException("Template not found: {$template}");
        }
        $vars['assetVersion'] = $this->assetVersion;
        $renderer = static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
            } catch (Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string)ob_get_clean();
        };
        return $renderer($file, $vars);
    }

    /**
     * Wraps a fragment template in layout.php.
     *
     * @param array<string, mixed> $vars
     * @param list<string> $styles extra stylesheet paths under /assets/
     */
    public function page(string $template, array $vars, string $title, array $styles = []): string
    {
        $content = $this->render($template, $vars);
        return $this->render('layout', ['title' => $title, 'content' => $content, 'styles' => $styles]);
    }

    public function assetVersion(): string
    {
        return $this->assetVersion;
    }

    /** Cache-busting string from the newest asset mtime; stable across requests, changes on deploy. */
    public static function assetVersionFor(string $publicDir): string
    {
        $latest = 0;
        foreach (glob($publicDir . '/assets/{css,js}/*.{css,js}', GLOB_BRACE) ?: [] as $file) {
            $latest = max($latest, (int)filemtime($file));
        }
        return base_convert((string)$latest, 10, 36);
    }

    private function path(string $template): string
    {
        if (preg_match('/^[a-z0-9_-]+$/i', $template) !== 1) {
            throw new RuntimeException("Invalid template name: {$template}");
        }
        return $this->templatesDir . '/' . $template . '.php';
    }
}
