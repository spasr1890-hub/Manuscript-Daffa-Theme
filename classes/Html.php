<?php

/**
 * @file plugins/themes/manuscriptDaffa/classes/Html.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Html
 *
 * @brief Small string helpers to change the rendered HTML of the Default
 *  Theme templates without copying them. Every helper leaves the HTML
 *  untouched when the expected markup is not found.
 */

namespace APP\plugins\themes\manuscriptDaffa\classes;

class Html
{
    public static function e(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Position just after the element that opens at $start (balanced on the
     * same tag name), or null when it is not closed.
     */
    public static function elementEnd(string $html, int $start, string $tag): ?int
    {
        $depth = 0;
        $offset = $start;
        $pattern = '~<(/?)' . preg_quote($tag, '~') . '\b[^>]*>~i';
        while (preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $isClose = $m[1][0] === '/';
            $pos = $m[0][1];
            $offset = $pos + strlen($m[0][0]);
            $depth += $isClose ? -1 : 1;
            if ($depth === 0) {
                return $offset;
            }
        }
        return null;
    }

    /**
     * Insert before the first match of $needle (literal), or not at all.
     */
    public static function insertBefore(string $html, string $needle, string $insert, int $offset = 0): string
    {
        $pos = strpos($html, $needle, $offset);
        if ($pos === false) {
            return $html;
        }
        return substr($html, 0, $pos) . $insert . substr($html, $pos);
    }

    /**
     * Insert after the first match of $needle (literal), or not at all.
     */
    public static function insertAfter(string $html, string $needle, string $insert, int $offset = 0): string
    {
        $pos = strpos($html, $needle, $offset);
        if ($pos === false) {
            return $html;
        }
        $pos += strlen($needle);
        return substr($html, 0, $pos) . $insert . substr($html, $pos);
    }

    /**
     * Apply $callback to the part of the HTML between $startNeedle and
     * $endNeedle (both literal and kept).
     */
    public static function region(string $html, string $startNeedle, string $endNeedle, callable $callback): string
    {
        $start = strpos($html, $startNeedle);
        if ($start === false) {
            return $html;
        }
        $end = strpos($html, $endNeedle, $start + strlen($startNeedle));
        if ($end === false) {
            return $html;
        }
        $inner = substr($html, $start, $end - $start);
        return substr($html, 0, $start) . $callback($inner) . substr($html, $end);
    }

    /**
     * Add an attribute to <img>/<iframe> tags that do not have it yet.
     *
     * @param ?callable $skip fn(string $tag): bool Return true to leave a tag alone
     */
    public static function addAttribute(string $html, array $tags, string $name, string $value, ?callable $skip = null): string
    {
        $pattern = '~<(' . implode('|', array_map('preg_quote', $tags)) . ')\b([^>]*)>~i';
        return preg_replace_callback($pattern, function ($m) use ($name, $value, $skip) {
            if (preg_match('~\s' . preg_quote($name, '~') . '\s*=~i', $m[2])) {
                return $m[0];
            }
            if ($skip && $skip($m[0])) {
                return $m[0];
            }
            $selfClosing = str_ends_with(rtrim($m[2]), '/');
            $attrs = $selfClosing ? rtrim(substr(rtrim($m[2]), 0, -1)) : $m[2];
            return '<' . $m[1] . $attrs . ' ' . $name . '="' . self::e($value) . '"' . ($selfClosing ? ' />' : '>');
        }, $html);
    }

    /**
     * Plain text, whitespace collapsed, cut at a word boundary.
     */
    public static function excerpt(?string $html, int $max = 160): string
    {
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1, 'UTF-8');
        $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
        if ($space !== false && $space > $max * 0.6) {
            $cut = mb_substr($cut, 0, $space, 'UTF-8');
        }
        return rtrim($cut, " ,.;:-\u{2013}\u{2014}") . "\u{2026}";
    }
}
