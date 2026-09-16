<?php
/**
 * Template helpers. Global on purpose: templates are plain PHP files and
 * `e()` must be the shortest possible thing to type so nobody skips it.
 */
declare(strict_types=1);

if (!function_exists('e')) {
    /** HTML-escape for text nodes and attribute values (always double-quote attributes). */
    function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
