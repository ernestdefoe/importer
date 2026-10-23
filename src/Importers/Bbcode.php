<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * A pragmatic BBCode → safe HTML converter shared by the XenForo, phpBB and
 * vBulletin importers. The source text is HTML-escaped FIRST (so any raw markup
 * can't inject), then BBCode tags — which survive escaping — are rewritten.
 *
 * Ported verbatim from the Convoro importer suite. Its HTML output is later run
 * through html-to-markdown → Flarum's formatter by the destination writer.
 */
class Bbcode
{
    /**
     * @param  array{uid?:string,escaped?:bool}  $opts
     *   uid      phpBB stores tags as [b:uid]…[/b:uid] — strip the suffix.
     *   escaped  phpBB stores post_text HTML-escaped — decode before re-escaping.
     */
    public static function toHtml(?string $text, array $opts = []): string
    {
        $text = (string) $text;
        if (trim($text) === '') {
            return '';
        }

        if (! empty($opts['uid'])) {
            $text = str_replace(':'.$opts['uid'], '', $text);
        }
        if (! empty($opts['escaped'])) {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Neutralise any raw HTML first; BBCode brackets are untouched by this.
        $t = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Code blocks: protect their contents from further BBCode processing.
        $codes = [];
        $t = preg_replace_callback('#\[code(?:=[^\]]*)?\](.*?)\[/code\]#is', function ($m) use (&$codes) {
            $key = '@@CODE'.count($codes).'@@';
            // phpBB and SMF store indentation as &nbsp;, which decodes to U+00A0.
            $codes[$key] = '<pre><code>'.str_replace("\u{00A0}", ' ', trim($m[1])).'</code></pre>';

            return $key;
        }, $t);

        // Inline formatting (repeat a few times for simple nesting).
        $pairs = [
            '#\[b\](.*?)\[/b\]#is' => '<strong>$1</strong>',
            '#\[i\](.*?)\[/i\]#is' => '<em>$1</em>',
            '#\[u\](.*?)\[/u\]#is' => '<u>$1</u>',
            '#\[(?:s|strike|del)\](.*?)\[/(?:s|strike|del)\]#is' => '<del>$1</del>',
            '#\[center\](.*?)\[/center\]#is' => '<div style="text-align:center">$1</div>',
            '#\[quote(?:=&quot;?([^\]&]*)&quot;?(?:;[^\]]*)?|=([^\]]*))?\](.*?)\[/quote\]#is' => '<blockquote>$3</blockquote>',
            '~\[color=([#\w]+)\](.*?)\[/color\]~is' => '<span style="color:$1">$2</span>',
            '#\[size=(\d+)\](.*?)\[/size\]#is' => '<span>$2</span>',
        ];
        for ($i = 0; $i < 4; $i++) {
            $t = preg_replace(array_keys($pairs), array_values($pairs), $t);
        }

        // Links + images.
        $t = preg_replace_callback('#\[url=([^\]]+)\](.*?)\[/url\]#is', fn ($m) => self::link($m[1], $m[2]), $t);
        $t = preg_replace_callback('#\[url\](.*?)\[/url\]#is', fn ($m) => self::link($m[1], $m[1]), $t);
        $t = preg_replace_callback('#\[img(?:=[^\]]*)?\](.*?)\[/img\]#is', fn ($m) => self::img($m[1]), $t);

        // YouTube / media → a plain link (the post renderer turns it into an embed).
        $t = preg_replace_callback('#\[(?:youtube|video|media)[^\]]*\](.*?)\[/(?:youtube|video|media)\]#is', function ($m) {
            $v = trim($m[1]);
            $url = preg_match('#^https?://#', $v) ? $v : 'https://www.youtube.com/watch?v='.preg_replace('/[^\w-]/', '', $v);

            return self::link($url, $url);
        }, $t);

        // Lists.
        $t = preg_replace_callback('#\[list(?:=([^\]]+))?\](.*?)\[/list\]#is', function ($m) {
            $tag = $m[1] !== '' && $m[1] !== '*' ? 'ol' : 'ul';
            $items = preg_split('#\[\*\]#', $m[2]);
            $li = '';
            foreach ($items as $it) {
                $it = trim($it);
                if ($it !== '') {
                    $li .= '<li>'.$it.'</li>';
                }
            }

            return "<{$tag}>{$li}</{$tag}>";
        }, $t);

        // Strip any leftover unknown [tags].
        $t = preg_replace('#\[/?[a-z0-9]+(?:=[^\]]*)?\]#i', '', $t);

        // Restore code blocks, then paragraph-ise.
        $t = strtr($t, $codes);

        return self::paragraphs($t);
    }

    /**
     * phpBB post_text, whichever of its two storage formats a row is in.
     *
     * 3.2+ stores s9e XML (`<r>…</r>` / `<t>…</t>`) whose markers keep the
     * original BBCode, so stripping the tags and decoding once IS the source —
     * that is phpBB's own unparser.
     *
     * 3.0/3.1 (and old posts on a 3.2+ board that were never reparsed) store
     * the user's text HTML-escaped with `[b:uid]` tags, plus REAL markup phpBB
     * inserted itself: magic links in `<!-- m -->` comments, smilies as `<img>`,
     * `[code=php]` highlighting spans. That markup is rewritten to BBCode while
     * the text is still escaped — so nothing the member typed can be mistaken
     * for it — and the text is then decoded exactly once.
     */
    public static function phpbb(?string $text, string $uid = ''): string
    {
        $text = (string) $text;
        if (preg_match('#^\s*<([rt])[\s>].*</\1>\s*$#s', $text)) {
            return self::toHtml(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }

        return self::toHtml(self::phpbbLegacy($text, $uid), ['escaped' => true]);
    }

    /** phpBB 3.0 markup → BBCode, leaving the escaped text escaped. */
    public static function phpbbLegacy(string $text, string $uid = ''): string
    {
        if ($uid !== '') {
            $text = str_replace(':'.$uid, '', $text);
        }
        // The list closers carry a type suffix as well as the uid.
        $text = str_replace(['[/*:m]', '[/list:u]', '[/list:o]'], ['', '[/list]', '[/list]'], $text);

        // Magic, local and www links, and e-mail addresses.
        $text = preg_replace_callback('#<a\s[^>]*?href="([^"]*)"[^>]*>(.*?)</a>#is', function ($m) {
            [$href, $label] = [$m[1], strip_tags($m[2])];
            if (stripos($href, 'mailto:') === 0) {
                return $label;
            }
            $href = str_replace(['[', ']'], ['%5B', '%5D'], $href);
            // phpBB shortens a long address's label to "start ... end".
            if ($label === $href || str_contains($label, ' ... ') || $label === '') {
                return '[url]'.$href.'[/url]';
            }

            return '[url='.$href.']'.$label.'[/url]';
        }, $text) ?? $text;

        $text = preg_replace([
            '#<img\s[^>]*?alt="([^"]*)"[^>]*>#i', // smilies → their code, which flarum/emoji turns back into an emoji
            '#<br\s*/?>#i',
            '#<!--.*?-->#s',
            '#</?[a-z][^>]*>#i',                    // anything else phpBB inserted
        ], ['$1', "\n", '', ''], $text) ?? $text;

        return $text;
    }

    private static function link(string $href, string $text): string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (! preg_match('#^(https?:)?//#i', $href) && ! str_starts_with($href, '/')) {
            return $text; // block javascript:/data:
        }

        return '<a href="'.htmlspecialchars($href, ENT_QUOTES).'" rel="nofollow noopener" target="_blank">'.$text.'</a>';
    }

    private static function img(string $src): string
    {
        $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return preg_match('#^https?://#i', $src) ? '<img src="'.htmlspecialchars($src, ENT_QUOTES).'" alt="">' : '';
    }

    /** Wrap loose lines into paragraphs, leaving existing block elements alone. */
    private static function paragraphs(string $html): string
    {
        $blocks = preg_split('/(\r?\n){2,}/', trim($html));
        $out = '';
        foreach ($blocks as $b) {
            $b = trim((string) $b);
            if ($b === '') {
                continue;
            }
            if (preg_match('#^<(p|div|blockquote|ul|ol|pre|h[1-6]|img|hr|table)\b#i', $b)) {
                $out .= $b;
            } else {
                $out .= '<p>'.nl2br($b, false).'</p>';
            }
        }

        return $out;
    }
}
