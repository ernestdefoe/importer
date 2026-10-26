<?php

namespace ErnestDefoe\Importer\Markup;

/**
 * Vanilla's two JSON post formats → canonical HTML.
 *
 *   Rich   a Quill delta: runs of text with inline attributes, each line's
 *          block format (heading, list, quote, code, spoiler) carried on the
 *          newline that ends it, and embeds (images, videos, links, quotes,
 *          mentions, emoji) as objects.
 *   Rich2  Vanilla's newer editor: a tree of typed nodes (p, h2, ul, li,
 *          blockquote, code_block, table, spoiler, link, @mention,
 *          rich_embed_card…) with marked-up text leaves.
 *
 * Before 0.6.0 both were reduced to their bare text: every list, heading,
 * quote and link was lost on import.
 */
final class VanillaRich
{
    public static function toHtml(string $json): ?string
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }
        // Rich2 is a list of nodes with `type`; Rich a list (or {ops}) of inserts.
        $first = $data[0] ?? null;
        if (is_array($first) && (isset($first['type']) || isset($first['children']))) {
            return self::rich2($data);
        }
        $ops = $data['ops'] ?? $data;

        return is_array($ops) ? self::quill($ops) : null;
    }

    /* ── Rich (Quill) ───────────────────────────────────────────────────── */

    private static function quill(array $ops): string
    {
        $lines = [];   // [blockType, attrs, inlineHtml]
        $line = '';
        $blocks = [];  // embeds that stand alone
        foreach ($ops as $op) {
            if (! is_array($op) || ! array_key_exists('insert', $op)) {
                continue;
            }
            $ins = $op['insert'];
            $attrs = is_array($op['attributes'] ?? null) ? $op['attributes'] : [];
            if (is_string($ins)) {
                $parts = explode("\n", $ins);
                foreach ($parts as $i => $part) {
                    if ($i > 0) {
                        // The newline ends the line; its attributes are the line's format.
                        $lines[] = [$attrs, $line];
                        $line = '';
                    }
                    if ($part !== '') {
                        $line .= self::marks(htmlspecialchars($part, ENT_QUOTES, 'UTF-8'), $attrs);
                    }
                }

                continue;
            }
            if (! is_array($ins)) {
                continue;
            }
            if (isset($ins['mention'])) {
                $m = $ins['mention'];
                $name = htmlspecialchars((string) ($m['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $line .= '<span data-mention="'.$name.'"'.(isset($m['userID']) ? ' data-user="'.(int) $m['userID'].'"' : '').'>@'.$name.'</span>';
            } elseif (isset($ins['emoji'])) {
                $line .= htmlspecialchars((string) ($ins['emoji']['emojiChar'] ?? ''), ENT_QUOTES, 'UTF-8');
            } elseif (isset($ins['embed-external'])) {
                if ($line !== '') {
                    $lines[] = [[], $line];
                    $line = '';
                }
                $lines[] = [['#embed' => true], self::embed($ins['embed-external']['data'] ?? $ins['embed-external'])];
            } elseif (isset($ins['image']) && is_string($ins['image'])) {
                $line .= '<img src="'.htmlspecialchars($ins['image'], ENT_QUOTES, 'UTF-8').'">';
            }
        }
        if ($line !== '') {
            $lines[] = [[], $line];
        }

        // Consecutive lines of one kind make one block.
        $html = '';
        $group = null;
        $buf = [];
        foreach ($lines as [$attrs, $inline]) {
            if (! empty($attrs['#embed'])) {
                $html .= self::group($group, $buf).$inline;
                $group = null;

                continue;
            }
            $kind = match (true) {
                ! empty($attrs['blockquote-line']) || ! empty($attrs['blockquote']) => 'quote',
                ! empty($attrs['spoiler-line']) => 'spoiler',
                ! empty($attrs['code-block']) || ! empty($attrs['codeBlock']) => 'code',
                isset($attrs['list']) => self::listType($attrs['list']),
                default => null,
            };
            if ($kind !== $group) {
                $html .= self::group($group, $buf);
                $group = $kind;
            }
            if ($kind === 'ul' || $kind === 'ol') {
                $buf[] = [$inline, (int) (is_array($attrs['list']) ? ($attrs['list']['depth'] ?? 0) : ($attrs['indent'] ?? 0)), $kind];

                continue;
            }
            if ($kind !== null) {
                $buf[] = $inline;

                continue;
            }
            $h = $attrs['header'] ?? null;
            $level = is_array($h) ? (int) ($h['level'] ?? 2) : (int) $h;
            if ($level >= 1 && $level <= 6) {
                $html .= '<h'.$level.'>'.$inline.'</h'.$level.'>';
            } elseif (trim(strip_tags($inline, '<img>')) !== '') {
                $html .= '<p>'.$inline.'</p>';
            }
        }

        return $html.self::group($group, $buf);
    }

    /** The lines gathered for one block kind, as that block; empties the buffer. */
    private static function group(?string $kind, array &$buf): string
    {
        $html = match ($kind) {
            'quote' => '<blockquote>'.implode('', array_map(fn ($l) => '<p>'.$l.'</p>', $buf)).'</blockquote>',
            'spoiler' => '<details>'.implode('', array_map(fn ($l) => '<p>'.$l.'</p>', $buf)).'</details>',
            'code' => '<pre>'.implode("\n", array_map(fn ($l) => strip_tags($l), $buf)).'</pre>',
            'ul', 'ol' => self::nestedList($buf),
            default => '',
        };
        $buf = [];

        return $html;
    }

    private static function listType(mixed $l): string
    {
        $t = is_array($l) ? ($l['type'] ?? 'bullet') : $l;

        return $t === 'ordered' ? 'ol' : 'ul';
    }

    /** @param list<array{0:string,1:int,2:string}> $items [html, depth, ul|ol] */
    private static function nestedList(array $items): string
    {
        $html = '';
        $stack = [];
        foreach ($items as [$inline, $depth, $tag]) {
            while (count($stack) > $depth + 1) {
                $html .= '</li></'.array_pop($stack).'>';
            }
            if (count($stack) < $depth + 1) {
                while (count($stack) < $depth + 1) {
                    $html .= '<'.$tag.'><li>';
                    $stack[] = $tag;
                }
            } else {
                $html .= '</li><li>';
            }
            $html .= $inline;
        }
        while ($stack) {
            $html .= '</li></'.array_pop($stack).'>';
        }

        return $html;
    }

    private static function marks(string $html, array $a): string
    {
        if (! empty($a['code-inline']) || ! empty($a['code'])) {
            $html = '<code>'.$html.'</code>';
        }
        if (! empty($a['bold'])) {
            $html = '<strong>'.$html.'</strong>';
        }
        if (! empty($a['italic'])) {
            $html = '<em>'.$html.'</em>';
        }
        if (! empty($a['underline'])) {
            $html = '<u>'.$html.'</u>';
        }
        if (! empty($a['strike']) || ! empty($a['strikethrough'])) {
            $html = '<del>'.$html.'</del>';
        }
        if (! empty($a['link']) && is_string($a['link'])) {
            $html = '<a href="'.htmlspecialchars($a['link'], ENT_QUOTES, 'UTF-8').'">'.$html.'</a>';
        }

        return $html;
    }

    /** An embed card: an image, a video, a quoted post, a file or a link. */
    private static function embed(mixed $d): string
    {
        if (! is_array($d)) {
            return '';
        }
        $type = strtolower((string) ($d['embedType'] ?? $d['type'] ?? ''));
        $url = (string) ($d['url'] ?? '');
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        if ($type === 'image' && $url !== '') {
            return '<p><img src="'.$e($url).'"'.(! empty($d['name']) ? ' alt="'.$e((string) $d['name']).'"' : '').'></p>';
        }
        if ($type === 'quote') {
            $who = (string) ($d['insertUser']['name'] ?? '');
            $body = (string) ($d['body'] ?? $d['bodyRaw'] ?? '');
            $inner = str_starts_with(trim($body), '[') ? (self::toHtml($body) ?? '') : $body;

            return '<blockquote'.($who !== '' ? ' data-cite="'.$e($who).'"' : '').'>'.$inner.'</blockquote>';
        }
        if ($type === 'file' && $url !== '') {
            return '<p><a href="'.$e($url).'">'.$e((string) ($d['name'] ?? basename($url))).'</a></p>';
        }
        if ($url === '') {
            return '';
        }
        if (Html::video($url) || in_array($type, ['youtube', 'vimeo', 'twitch', 'soundcloud', 'twitter', 'instagram', 'imgur', 'giphy', 'getty', 'codepen', 'tiktok'], true)) {
            return '<figure data-media="'.$e($url).'"></figure>';
        }

        return '<p><a href="'.$e($url).'">'.$e((string) ($d['name'] ?? $url)).'</a></p>';
    }

    /* ── Rich2 ──────────────────────────────────────────────────────────── */

    private static function rich2(array $nodes): string
    {
        $html = '';
        foreach ($nodes as $n) {
            $html .= self::node($n);
        }

        return $html;
    }

    private static function node(mixed $n): string
    {
        if (! is_array($n)) {
            return '';
        }
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        if (array_key_exists('text', $n) && ! isset($n['type'])) {
            return self::marks(nl2br($e((string) $n['text']), false), [
                'bold' => $n['bold'] ?? false, 'italic' => $n['italic'] ?? false, 'underline' => $n['underline'] ?? false,
                'strike' => $n['strikethrough'] ?? false, 'code' => $n['code'] ?? false,
            ]);
        }
        $kids = fn () => implode('', array_map([self::class, 'node'], is_array($n['children'] ?? null) ? $n['children'] : []));
        $type = strtolower((string) ($n['type'] ?? ''));

        return match ($type) {
            'p', 'paragraph' => '<p>'.$kids().'</p>',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => '<'.$type.'>'.$kids().'</'.$type.'>',
            'blockquote' => '<blockquote>'.$kids().'</blockquote>',
            'blockquote-line', 'blockquote_line', 'lic', 'spoiler-line', 'spoiler_line' => '<p>'.$kids().'</p>',
            'code_block', 'code-block' => '<pre'.(! empty($n['lang']) ? ' data-lang="'.$e((string) $n['lang']).'"' : '').'>'.$e(self::plain($n)).'</pre>',
            'code_line', 'code-line' => $e(self::plain($n))."\n",
            'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'td', 'th' => '<'.$type.'>'.$kids().'</'.$type.'>',
            'spoiler' => '<details>'.$kids().'</details>',
            'spoiler-content', 'spoiler_content' => $kids(),
            'link', 'a' => '<a href="'.$e((string) ($n['url'] ?? '')).'">'.$kids().'</a>',
            'mention', '@' => '<span data-mention="'.$e((string) ($n['name'] ?? '')).'"'.(isset($n['userID']) ? ' data-user="'.(int) $n['userID'].'"' : '').'>@'.$e((string) ($n['name'] ?? '')).'</span>',
            'img', 'image' => '<img src="'.$e((string) ($n['url'] ?? $n['src'] ?? '')).'"'.(! empty($n['alt']) ? ' alt="'.$e((string) $n['alt']).'"' : '').'>',
            'hr' => '<hr>',
            'rich_embed_card', 'rich_embed_inline' => self::embed(($n['embedData'] ?? []) + ['url' => $n['url'] ?? '', 'embedType' => $n['dataSourceType'] ?? ($n['embedData']['embedType'] ?? '')]),
            default => $kids(),
        };
    }

    private static function plain(array $n): string
    {
        if (isset($n['text']) && ! isset($n['type'])) {
            return (string) $n['text'];
        }
        $out = '';
        foreach ($n['children'] ?? [] as $c) {
            $out .= is_array($c) ? self::plain($c) : '';
        }

        return $out;
    }
}
