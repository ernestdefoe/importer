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

    /**
     * A tag of phpBB 3.2+'s stored XML, as it shows in a post imported before
     * 0.3.7: escaped as `&lt;…&gt;`, or as `\<…\>` once repair-text's
     * fixEntities() had been over it. Uppercase names are BBCodes (B, QUOTE,
     * URL, LINK_TEXT, E for a smiley…); r/t are the root, s/e the markers
     * around the original BBCode, i ignored text, br a line break.
     */
    private const XML_TAG = '(?:&lt;|\\\\<)/?(?:[A-Z](?:[A-Z0-9]|\\\\?_)*|[rtsei]|br)(?:\s(?:(?!&gt;|\\\\>).)*?)?\s*/?(?:&gt;|\\\\>)';

    /**
     * Was this post phpBB 3.2+ XML that arrived as visible text?
     *
     * 🚨 Before 0.3.7 the XML was escaped into the text, so the page showed
     * `<r><QUOTE author="Xeno"><s>` around every reply written after a board's
     * upgrade to 3.2 (Winckle, 2026-10-24: 190 of 207 replies in one thread).
     * The 0.3.7 repair knew only phpBB 3.0 markup and marked these done.
     */
    public static function hasPhpbbXml(string $md): bool
    {
        return (bool) preg_match('#^\s*(?:&lt;|\\\\<)([rt])(?:&gt;|\\\\>).*(?:&lt;|\\\\<)/\1(?:&gt;|\\\\>)\s*$#s', $md);
    }

    /** A cheap test on a post's STORED content, so a rerun unparses only candidates. */
    public static function mayHoldPhpbbXml(string $content): bool
    {
        return str_contains($content, '</HE>r<HE') || str_contains($content, '</HE>t<HE')
            || str_contains($content, '&lt;</ESC>r<ESC>') || str_contains($content, '&lt;</ESC>t<ESC>');
    }

    /**
     * The post without phpBB's XML tags.
     *
     * The BBCode inside the markers was already converted along with the text
     * (`<s>**</s>bold<e>**</e>`, a quote's `> ` lines), so dropping the tags
     * leaves the post as it was meant to read: what phpBB's own unparser does.
     * Text the member typed in angle brackets is not an XML tag name and stays.
     */
    public static function repairPhpbbXml(string $md, ?callable $wrote = null): string
    {
        // Who a quote quotes survives only on its QUOTE tag; hold it in a
        // placeholder while the tags go, as a fresh import would print it.
        $cites = [];
        $md = preg_replace_callback('#(?:&lt;|\\\\<)QUOTE\s((?:(?!&gt;|\\\\>).)*)(?:&gt;|\\\\>)#s', function ($m) use (&$cites) {
            if (! preg_match('#author=\\\\?"((?:(?!\\\\?").)*)\\\\?"#', $m[1], $a)) {
                return '';
            }
            $name = html_entity_decode(preg_replace('/\\\\(.)/', '$1', $a[1]) ?? $a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cites[] = $name;

            return "\x01".(count($cites) - 1)."\x01";
        }, $md) ?? $md;

        $md = preg_replace('#'.self::XML_TAG.'#s', '', $md) ?? $md;

        if ($cites !== []) {
            $md = preg_replace_callback("/\x01(\d+)\x01\s*((?:> ?)+)?/", function ($m) use ($cites, $wrote) {
                $name = self::escape($cites[(int) $m[1]]);
                $line = '**'.($wrote ? $wrote($name) : $name.' wrote:').'**';
                $p = $m[2] ?? '';

                return $p === '' ? "\n\n".$line."\n\n" : "\n\n".$p.$line."\n".rtrim($p)."\n".$p;
            }, $md) ?? $md;
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", $md) ?? $md);
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
