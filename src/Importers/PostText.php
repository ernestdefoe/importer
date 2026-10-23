<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * Pure text fixes on the Markdown the importer hands Flarum's formatter. No
 * Flarum here, so `php tests/post_text.php` checks it with nothing but `php`.
 */
class PostText
{
    /**
     * league/html-to-markdown writes `<`, `>`, `&` and a bare `|` as HTML
     * entities, expecting a CommonMark parser to decode them. Flarum's Markdown
     * never does, so every imported "Tom & Jerry" read "Tom &amp; Jerry".
     *
     * One pass, left to right: `&amp;lt;` becomes the TEXT `&lt;`, never `<`.
     * `<` and `>` become Markdown escapes rather than raw brackets, so
     * nothing a member once typed can turn into markup on an HTML-aware
     * editor. Code spans and fences are left alone: there the formatter shows
     * the entities the way they were meant.
     */
    public static function fixEntities(string $md): string
    {
        $entities = ['&amp;' => '&', '&lt;' => '\\<', '&gt;' => '\\>', '&#124;' => '\\|'];

        return preg_replace_callback(
            '/^(`{3,}|~{3,})[^\n]*\n.*?^\1[ \t]*$|(`+)(?:(?!\2).)+?\2|(?<![\[(<\w])(https?:\/\/(?:(?!&[lg]t;)[^\s<>()\[\]\"])+)|&(?:amp|lt|gt|#124);/ms',
            function ($m) use ($entities) {
                if (($m[3] ?? '') !== '') {
                    // A bare address: the escaped underscore would become part
                    // of the link the autolinker makes.
                    return str_replace(['&amp;', '\\_'], ['&', '_'], $m[3]);
                }

                return $entities[$m[0]] ?? $m[0];
            },
            $md
        ) ?? $md;
    }

    /**
     * Repair a post imported from phpBB 3.0 before 0.3.7: its stored markup
     * (magic links, smilies, list closers) arrived as escaped TEXT. Takes the
     * post's unparsed Markdown and returns it fixed — unchanged when there is
     * nothing to fix, so running twice is a no-op.
     */
    public static function repairPhpbb(string $md): string
    {
        $un = fn (string $s) => preg_replace('/\\\\([\\\\*_\[\]|#`])/', '$1', $s) ?? $s;

        $md = preg_replace_callback(
            '#&lt;a\s(?:(?!&gt;).)*?href="([^"]*)"(?:(?!&gt;).)*&gt;(.*?)&lt;/a&gt;#is',
            function ($m) use ($un) {
                $href = html_entity_decode($un($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = $m[2];
                $plain = html_entity_decode($un($label), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (! preg_match('#^https?://#i', $href)) {
                    return $label; // mailto:, relative ./viewtopic.php links: keep the words
                }
                if ($plain === $href || str_contains($plain, ' ... ') || trim($plain) === '') {
                    $label = self::escape($href);
                }
                $dest = str_replace([' ', '(', ')', '<', '>'], ['%20', '%28', '%29', '%3C', '%3E'], $href);

                // The destination is encoded back into the entity domain, so
                // fixEntities() decodes its `&` once along with the rest.
                return '['.$label.']('.htmlspecialchars($dest, ENT_NOQUOTES, 'UTF-8').')';
            },
            $md
        ) ?? $md;

        $md = preg_replace([
            '#&lt;img\s(?:(?!&gt;).)*?alt="([^"]*)"(?:(?!&gt;).)*&gt;#i',
            '#&lt;br\s*/?&gt;#i',
            '#&lt;!--.*?--&gt;#s',
            '#\\\\\[/\\\\\*:m\\\\\]#',
            '#\\\\\[/list:[ou]\\\\\]#',
            '#\s*\\\\\[\\\\\*\\\\\]\s*#',
        ], ['$1', "\n", '', '', "\n", "\n- "], $md) ?? $md;

        return $md;
    }

    /** Does this unparsed post still carry phpBB 3.0 markup or uid leftovers? */
    public static function hasPhpbbLegacy(string $md): bool
    {
        return (bool) preg_match('#&lt;!-- ?[a-z]|&lt;a\s[^\n]*?class="postlink|\\\\\[/\\\\\*:m\\\\\]|\\\\\[/list:[ou]\\\\\]#i', $md);
    }

    private static function escape(string $s): string
    {
        return preg_replace('/([\\\\\[\]*_`])/', '\\\\$1', $s) ?? $s;
    }
}
