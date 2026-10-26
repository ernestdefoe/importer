<?php

namespace ErnestDefoe\Importer\Markup;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * The canonical form every source is read into before {@see Writer} writes it
 * for the target forum — a deliberately small HTML vocabulary:
 *
 *   blocks   p, h1–h6, blockquote, ul, ol[start], li, pre, hr, table, thead,
 *            tbody, tr, th, td[colspan|rowspan], div (a group), figure
 *   inline   strong, em, u, del, sup, sub, code, a[href], img[src|alt], br,
 *            span, mark
 *   data-*   align (p, div, h1–h6, figure), color (span, mark), size (span,
 *            px), lang (pre), cite + post (blockquote: who, and the source
 *            post), title (details = a spoiler, aside = an info box), media
 *            (figure: an embed's address), spoiler (span: inline spoiler),
 *            mention + user|user-name (span), topic (a: a source topic id)
 *
 * {@see clean()} maps any source's HTML onto it: inline styles to data
 * attributes, editor classes (spoilers, code languages, emoji, mentions) to
 * elements, iframes to an embed's address, everything unknown unwrapped so
 * its text survives. Scripts, handlers and javascript: URLs go.
 */
final class Html
{
    /** Parse a fragment; returns the wrapping element, or null for nothing. */
    public static function load(string $html): ?DOMElement
    {
        if (trim($html) === '') {
            return null;
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="importer-root">'.$html.'</div>',
            LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_COMPACT | LIBXML_PARSEHUGE
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $ok) {
            return null;
        }
        foreach ($doc->childNodes as $n) {
            if ($n instanceof DOMElement && $n->getAttribute('id') === 'importer-root') {
                return $n;
            }
        }
        $root = $doc->getElementById('importer-root');

        return $root instanceof DOMElement ? $root : null;
    }

    public static function save(DOMElement $root): string
    {
        $out = '';
        foreach ($root->childNodes as $c) {
            $out .= $root->ownerDocument->saveHTML($c);
        }

        return $out;
    }

    /* ── Strings in the forum's language ────────────────────────────────── */

    /** "Ada wrote:" — in the forum's language, English when the strings are not loaded (a console run). */
    public static function wrote(string $name): string
    {
        return self::trans('quote_wrote', ['name' => $name], $name.' wrote:');
    }

    public static function spoilerLabel(): string
    {
        return self::trans('spoiler', [], 'Spoiler');
    }

    public static function attachmentLabel(string $name): string
    {
        return self::trans('attachment', ['name' => $name], 'Attachment: '.$name);
    }

    private static function trans(string $key, array $params, string $fallback): string
    {
        try {
            $t = resolve(\Flarum\Locale\TranslatorInterface::class)->trans('ernestdefoe-importer.api.'.$key, $params);
        } catch (\Throwable) {
            return $fallback;
        }

        // Flarum hands back the key itself when the strings are not loaded.
        return $t === '' || str_contains($t, 'ernestdefoe-importer.') ? $fallback : $t;
    }

    /* ── Values ─────────────────────────────────────────────────────────── */

    private const COLORS = 'aqua|black|blue|fuchsia|gray|grey|green|lime|maroon|navy|olive|orange|purple|red|silver|teal|white|yellow|brown|pink|gold|violet|indigo|darkred|darkblue|darkgreen|darkorange|crimson|tomato|coral|salmon|khaki|beige|tan|cyan|magenta|skyblue|royalblue|deepskyblue|seagreen|limegreen|forestgreen|darkviolet|orchid|plum|chocolate|sienna|firebrick|goldenrod|slategray|lightgray|lightgrey|darkgray|darkgrey|dimgray';

    /** A colour the forum's own filters accept (#rrggbb or a CSS name), or null. */
    public static function color(?string $c): ?string
    {
        $c = strtolower(trim((string) $c));
        if ($c === '') {
            return null;
        }
        if (preg_match('/^#?([0-9a-f]{6}|[0-9a-f]{3})$/', $c, $m)) {
            $h = strlen($m[1]) === 3 ? preg_replace('/(.)/', '$1$1', $m[1]) : $m[1];

            return '#'.$h;
        }
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/', $c, $m)) {
            return sprintf('#%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
        }

        return preg_match('/^(?:'.self::COLORS.')$/', $c) ? $c : null;
    }

    /**
     * A font size in px from what the old board stored: an HTML 1–7 size,
     * a CSS name, px/pt/em/%, or — with $scale — the board's own unit.
     *
     * @param  string  $scale  'html' (1–7, vBulletin/XenForo/SMF), 'percent' (phpBB), 'pt' (MyBB), 'px'
     */
    public static function size(?string $v, string $scale = 'html'): ?int
    {
        $v = strtolower(trim((string) $v, " \"'"));
        if ($v === '') {
            return null;
        }
        $named = ['xx-small' => 9, 'x-small' => 10, 'small' => 13, 'medium' => 16, 'large' => 18, 'x-large' => 24, 'xx-large' => 32, 'smaller' => 13, 'larger' => 18];
        if (isset($named[$v])) {
            return $named[$v];
        }
        if (! preg_match('/^(\d+(?:\.\d+)?)\s*(px|pt|em|rem|%)?$/', $v, $m)) {
            return null;
        }
        $n = (float) $m[1];
        $px = match ($m[2] ?? '') {
            'px' => $n,
            'pt' => $n * 4 / 3,
            'em', 'rem' => $n * 16,
            '%' => $n * 14 / 100,
            default => match ($scale) {
                'percent' => $n * 14 / 100,
                'pt' => $n * 4 / 3,
                'px' => $n,
                default => $n <= 7 ? [1 => 10, 13, 16, 18, 24, 32, 36][max(1, (int) $n)] : $n,
            },
        };

        return (int) round(max(8, min(36, $px)));
    }

    /** http(s), protocol-relative, site-relative or mailto: — never javascript:, data: and the like. */
    public static function safeUrl(string $u): bool
    {
        $u = trim($u);

        return (bool) preg_match('#^(?:https?://|//|/(?!/)|mailto:[^\s@]+@)#i', $u);
    }

    /** [provider, id] for an address Scribe can embed, or null. */
    public static function video(string $url): ?array
    {
        $p = parse_url(trim($url));
        if (! $p || empty($p['host'])) {
            return null;
        }
        $host = strtolower(preg_replace('/^www\./i', '', $p['host']) ?? '');
        $path = $p['path'] ?? '/';
        parse_str($p['query'] ?? '', $q);
        $yt = '[A-Za-z0-9_-]{11}';

        return match (true) {
            in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com'], true) && preg_match('#^/watch/?$#', $path) && is_string($q['v'] ?? null) && preg_match('/^'.$yt.'$/', $q['v']) => ['youtube', $q['v']],
            in_array($host, ['youtube.com', 'm.youtube.com', 'youtube-nocookie.com'], true) && preg_match('#^/(?:embed|live|v)/('.$yt.')/?$#', $path, $m) => ['youtube', $m[1]],
            in_array($host, ['youtube.com', 'm.youtube.com'], true) && preg_match('#^/shorts/('.$yt.')/?$#', $path, $m) => ['youtubeshorts', $m[1]],
            $host === 'youtu.be' && preg_match('#^/('.$yt.')/?$#', $path, $m) => ['youtube', $m[1]],
            $host === 'vimeo.com' && preg_match('#^/(?:channels/[\w-]+/)?(\d{1,12})/?$#', $path, $m) => ['vimeo', $m[1]],
            $host === 'player.vimeo.com' && preg_match('#^/video/(\d{1,12})/?$#', $path, $m) => ['vimeo', $m[1]],
            $host === 'dailymotion.com' && preg_match('#^/(?:embed/)?video/([A-Za-z0-9]{5,12})#', $path, $m) => ['dailymotion', $m[1]],
            default => null,
        };
    }

    /** The watch address for a provider's id, as the old boards' media tags store just the id. */
    public static function mediaUrl(string $provider, string $id): ?string
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $id)) {
            return $id;
        }
        $id = rawurlencode($id);

        return match (strtolower(trim($provider))) {
            'youtube', 'yt', '' => 'https://www.youtube.com/watch?v='.$id,
            'vimeo' => 'https://vimeo.com/'.$id,
            'dailymotion' => 'https://www.dailymotion.com/video/'.$id,
            'facebook' => 'https://www.facebook.com/watch/?v='.$id,
            'twitch' => 'https://www.twitch.tv/videos/'.$id,
            'twitter', 'x' => 'https://x.com/i/status/'.$id,
            'instagram' => 'https://www.instagram.com/p/'.$id,
            'tiktok' => 'https://www.tiktok.com/embed/v2/'.$id,
            'spotify' => 'https://open.spotify.com/'.str_replace('%3A', '/', $id),
            'soundcloud' => 'https://soundcloud.com/'.str_replace('%2F', '/', $id),
            'liveleak', 'metacafe' => null,
            default => null,
        };
    }

    /**
     * `@name` and `@"Full Name"` typed as text (NodeBB, Vanilla) → mentions
     * {@see Writer} links when that member was imported. Not inside code or
     * a link; an e-mail address is not a mention.
     */
    public static function textMentions(string $html): string
    {
        if (! str_contains($html, '@')) {
            return $html;
        }
        $parts = preg_split('#(<[^>]+>)#', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $skip = 0;
        foreach ($parts as $i => $part) {
            if ($part !== '' && $part[0] === '<') {
                if (preg_match('#^<(/?)(code|pre|a)\b#i', $part, $m)) {
                    $skip += $m[1] === '/' ? -1 : 1;
                    $skip = max(0, $skip);
                }

                continue;
            }
            if ($skip || ! str_contains($part, '@')) {
                continue;
            }
            $parts[$i] = preg_replace_callback('/(?<![\w@.\/])@(?:&quot;([^&]{1,60}?)&quot;|"([^"<]{1,60}?)"|([\p{L}\p{N}_-][\p{L}\p{N}_.-]{0,59}))/u', function ($m) {
                $name = rtrim(html_entity_decode($m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : ($m[3] ?? '')), ENT_QUOTES, 'UTF-8'), '.');
                $e = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

                return '<span data-mention="'.$e.'" data-user-name="'.$e.'">@'.$e.'</span>';
            }, $part) ?? $part;
        }

        return implode('', $parts);
    }

    /* ── Source HTML → canonical ────────────────────────────────────────── */

    /**
     * @param  array{base?:string}  $opts  base: the old board's address, for relative links and images
     */
    public static function clean(string $html, array $opts = []): string
    {
        $root = self::cleanRoot($html, $opts);

        return $root ? self::save($root) : '';
    }

    /** As {@see clean()}, returning the parsed root for {@see Writer} (one parse, not two). */
    public static function cleanRoot(string $html, array $opts = []): ?DOMElement
    {
        $src = self::load($html);
        if (! $src) {
            return null;
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElement('div');
        $doc->appendChild($root);
        $c = new self($doc, rtrim((string) ($opts['base'] ?? ''), '/'));
        $c->children($src, $root);

        return $root;
    }

    private function __construct(private DOMDocument $doc, private string $base)
    {
    }

    private const DROP = ['script', 'style', 'noscript', 'template', 'object', 'embed', 'applet', 'head', 'title', 'meta', 'link', 'base', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'canvas', 'param', 'source', 'track', 'map', 'area'];

    private const KEEP = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'hr', 'br', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'strong', 'em', 'u', 'del', 'sup', 'sub', 'mark'];

    private const RENAME = ['b' => 'strong', 'i' => 'em', 'cite' => 'em', 'dfn' => 'em', 'var' => 'em', 'strike' => 'del', 's' => 'del', 'ins' => 'u', 'tt' => 'code', 'kbd' => 'code', 'samp' => 'code', 'tfoot' => 'tbody', 'dir' => 'ul', 'menu' => 'ul'];

    /** Elements whose content is a block of its own: kept as a group so their text does not run into the next. */
    private const GROUP = ['div', 'section', 'article', 'header', 'footer', 'main', 'nav', 'address', 'center', 'dl', 'fieldset', 'figcaption', 'caption', 'legend', 'dt', 'dd', 'hgroup', 'figure', 'picture', 'body', 'html'];

    private function children(DOMNode $from, DOMElement $to): void
    {
        foreach (iterator_to_array($from->childNodes) as $n) {
            $this->node($n, $to);
        }
    }

    private function node(DOMNode $n, DOMElement $to): void
    {
        if ($n instanceof DOMText) {
            $to->appendChild($this->doc->createTextNode($n->data));

            return;
        }
        if (! $n instanceof DOMElement) {
            return; // comments, processing instructions
        }
        $tag = strtolower($n->nodeName);
        if (in_array($tag, self::DROP, true)) {
            return;
        }
        $class = ' '.strtolower($n->getAttribute('class')).' ';
        $style = self::style($n->getAttribute('style'));
        // Already canonical (a BBCode conversion, or a second pass): kept as is.
        if (($v = $n->getAttribute('data-color')) !== '' && ! isset($style['color']) && $tag !== 'mark') {
            $style['color'] = $v;
        }
        if (($v = (int) $n->getAttribute('data-size')) > 0) {
            $style['font-size'] = $v.'px';
        }
        if (($v = $n->getAttribute('data-align')) !== '') {
            $style['text-align'] = $v;
        }
        // Discourse's lightbox caption (file name, size): noise once imported.
        if (str_contains($class, ' meta ') && $n->parentNode instanceof DOMElement && str_contains(strtolower($n->parentNode->getAttribute('class')), 'lightbox')) {
            return;
        }

        // Read back from a stored post ({@see FromXml}): kept exactly.
        if ($tag === 'span' && ($n->hasAttribute('data-raw') || $n->hasAttribute('data-tag'))) {
            $el = $this->el('span', $to);
            foreach (['data-raw', 'data-tag'] as $a) {
                if ($n->hasAttribute($a)) {
                    $el->setAttribute($a, $n->getAttribute($a));
                }
            }
            $el->appendChild($this->doc->createTextNode($n->textContent));

            return;
        }

        // ── Things recognised by their attributes, whatever the element ──
        if ($n->hasAttribute('data-mention') || $n->hasAttribute('data-mentionid') || preg_match('/\s(?:ipsmention|mention|user-mention|usermention)\s/', $class)) {
            $this->mention($n, $to);

            return;
        }
        if ($tag === 'img' && $this->isEmoji($n, $class)) {
            $to->appendChild($this->doc->createTextNode($this->emojiText($n)));

            return;
        }
        if ($n->hasAttribute('data-spoiler') || (preg_match('/\s(?:spoiler|spoilertext|ipsspoiler|bbcode_spoiler|spoiler-blurred|spoiled)\s/', $class) && ! str_contains($class, 'header'))) {
            $this->spoilerFrom($n, $to, $tag);

            return;
        }
        if ($n->hasAttribute('data-media')) {
            $this->embed((string) $n->getAttribute('data-media'), $to);

            return;
        }
        // Discourse's lazy video: the id and provider, the player loaded later.
        if (($vid = $n->getAttribute('data-video-id')) !== '' && ($url = Html::mediaUrl($n->getAttribute('data-provider-name') ?: 'youtube', $vid))) {
            $this->embed($url, $to);

            return;
        }

        switch ($tag) {
            case 'iframe':
            case 'video':
            case 'audio':
                $src = $n->getAttribute('src') ?: $n->getAttribute('data-src') ?: $n->getAttribute('data-embed-src');
                if ($src === '' && ($s = $n->getElementsByTagName('source')->item(0))) {
                    $src = $s->getAttribute('src');
                }
                $this->embed($this->embedTarget($src), $to);

                return;
            case 'a':
                $this->anchor($n, $to);

                return;
            case 'img':
                $this->image($n, $to);

                return;
            case 'pre':
                $this->pre($n, $to, $class);

                return;
            case 'code':
                // A multi-line <code> standing as a block is a code block.
                if (! $this->inline($to) && str_contains(trim($n->textContent), "\n")) {
                    $this->pre($n, $to, $class);

                    return;
                }
                $el = $this->el('code', $to);
                $el->appendChild($this->doc->createTextNode($n->textContent));

                return;
            case 'blockquote':
            case 'q':
                $this->quote($n, $to, $tag);

                return;
            case 'details':
                $this->details($n, $to);

                return;
            case 'summary':
                return; // read by details()
            case 'aside':
                if (str_contains($class, 'quote') || $n->hasAttribute('data-username')) {
                    $this->quote($n, $to, $tag);

                    return;
                }
                if (str_contains($class, 'onebox')) {
                    $this->onebox($n, $to);

                    return;
                }
                $el = $this->el('aside', $to);
                if (($t = trim($n->getAttribute('data-title'))) !== '') {
                    $el->setAttribute('data-title', $t);
                }
                $this->children($n, $el);

                return;
            case 'figure':
                if ($n->getElementsByTagName('img')->length && ($a = self::align($style['text-align'] ?? null))) {
                    $el = $this->el('figure', $to);
                    $el->setAttribute('data-align', $a);
                    $this->children($n, $el);

                    return;
                }
                break;
            case 'font':
                $el = $this->styled('span', $style + array_filter([
                    'color' => $n->getAttribute('color'),
                    'font-size' => ($s = Html::size($n->getAttribute('size'))) ? $s.'px' : '',
                ]), $to);
                $this->children($n, $el);

                return;
            case 'span':
            case 'big':
            case 'small':
            case 'abbr':
            case 'acronym':
            case 'label':
            case 'time':
            case 'bdi':
            case 'bdo':
            case 'nobr':
            case 'wbr':
                if ($tag === 'wbr') {
                    return;
                }
                $el = $this->styled('span', $style, $to);
                $this->children($n, $el);

                return;
        }

        $tag = self::RENAME[$tag] ?? $tag;
        if ($tag === 'code') {
            $el = $this->el('code', $to);
            $el->appendChild($this->doc->createTextNode($n->textContent));

            return;
        }
        if (in_array($tag, self::KEEP, true)) {
            $el = $this->el($tag, $to);
            if (in_array($tag, ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'td', 'th'], true) && ($a = self::align($style['text-align'] ?? $n->getAttribute('align')))) {
                $el->setAttribute('data-align', $a);
            }
            if ($tag === 'ol' && ($s = (int) $n->getAttribute('start')) > 1) {
                $el->setAttribute('start', (string) $s);
            }
            if (in_array($tag, ['td', 'th'], true)) {
                foreach (['colspan', 'rowspan'] as $a) {
                    if (($v = (int) $n->getAttribute($a)) > 1) {
                        $el->setAttribute($a, (string) $v);
                    }
                }
            }
            if ($tag === 'mark' && ($c = Html::color($style['background-color'] ?? $style['background'] ?? $n->getAttribute('data-color')))) {
                $el->setAttribute('data-color', $c);
            }
            // Colour or size set on a block or a <strong>: kept on an inner span.
            $inner = $el;
            if ($tag !== 'mark' && (isset($style['color']) || isset($style['font-size']))) {
                $inner = $this->styled('span', array_intersect_key($style, ['color' => 1, 'font-size' => 1]), $el);
            }
            $this->children($n, $inner);
            // A paragraph that is just a video's address is that video (Markdown boards autolink it).
            if ($tag === 'p' && $el->childNodes->length === 1 && ($a = $el->firstChild) instanceof DOMElement && $a->nodeName === 'a'
                && trim($a->textContent) === $a->getAttribute('href') && Html::video($a->getAttribute('href'))) {
                $f = $this->doc->createElement('figure');
                $f->setAttribute('data-media', $a->getAttribute('href'));
                $to->replaceChild($f, $el);
            }

            return;
        }

        // A group: keeps its alignment, its contents stay apart from what follows.
        if (in_array($tag, self::GROUP, true) || $style !== [] || $n->hasAttribute('align')) {
            $align = self::align($style['text-align'] ?? $n->getAttribute('align')) ?? ($tag === 'center' ? 'center' : null);
            $inline = $this->inline($to) && ! in_array($tag, ['div', 'center', 'section', 'article', 'dl', 'dt', 'dd', 'figure', 'figcaption', 'caption'], true);
            if ($inline) {
                $el = $this->styled('span', $style, $to);
                $this->children($n, $el);

                return;
            }
            $el = $this->el('div', $to);
            if ($align && $align !== 'left') {
                $el->setAttribute('data-align', $align);
            }
            $inner = (isset($style['color']) || isset($style['font-size'])) ? $this->styled('span', array_intersect_key($style, ['color' => 1, 'font-size' => 1]), $el) : $el;
            $this->children($n, $inner);

            return;
        }

        // Unknown: its text stays, its markup goes.
        $this->children($n, $to);
    }

    private function el(string $tag, DOMElement $to): DOMElement
    {
        $el = $this->doc->createElement($tag);
        $to->appendChild($el);

        return $el;
    }

    /** Is this position inside inline content (a paragraph, a heading, a link…)? */
    private function inline(DOMElement $to): bool
    {
        for ($n = $to; $n instanceof DOMElement; $n = $n->parentNode) {
            $t = strtolower($n->nodeName);
            if (in_array($t, ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'em', 'u', 'del', 'sup', 'sub', 'a', 'span', 'mark', 'code'], true)) {
                return true;
            }
            if (in_array($t, ['div', 'li', 'td', 'th', 'blockquote', 'details', 'aside'], true)) {
                return false;
            }
        }

        return false;
    }

    /** @param array<string,string> $style */
    private function styled(string $tag, array $style, DOMElement $to): DOMElement
    {
        $el = $this->el($tag, $to);
        if ($c = Html::color($style['color'] ?? null)) {
            $el->setAttribute('data-color', $c);
        }
        if ($s = Html::size($style['font-size'] ?? null)) {
            $el->setAttribute('data-size', (string) $s);
        }
        if (($bg = Html::color($style['background-color'] ?? null)) && $tag === 'span') {
            $mark = $this->doc->createElement('mark');
            $mark->setAttribute('data-color', $bg);
            $el->appendChild($mark);

            return $mark;
        }
        return $el;
    }

    /** @return array<string,string> */
    private static function style(string $style): array
    {
        $out = [];
        foreach (explode(';', $style) as $decl) {
            if (preg_match('/^\s*([a-z-]+)\s*:\s*(.+?)\s*(?:!important)?\s*$/i', $decl, $m)) {
                $out[strtolower($m[1])] = $m[2];
            }
        }

        return array_intersect_key($out, array_flip(['color', 'font-size', 'text-align', 'background-color', 'background']));
    }

    private static function align(?string $a): ?string
    {
        $a = strtolower(trim((string) $a));

        return in_array($a, ['left', 'center', 'right', 'justify'], true) ? $a : ($a === 'middle' ? 'center' : null);
    }

    private function absolute(string $u): string
    {
        $u = trim(html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($u === '' || preg_match('#^(?:https?:|mailto:)#i', $u)) {
            return $u;
        }
        if (str_starts_with($u, '//')) {
            return 'https:'.$u;
        }
        if ($this->base !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*:#i', $u) && ! str_starts_with($u, '#')) {
            if (str_starts_with($u, '/')) {
                $p = parse_url($this->base);

                return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '').$u;
            }

            return $this->base.'/'.preg_replace('#^\./#', '', $u);
        }

        return $u;
    }

    private function anchor(DOMElement $n, DOMElement $to): void
    {
        $href = $this->absolute($n->getAttribute('href'));
        if ($v = Html::video($href)) {
            // A bare video link standing alone becomes the embed it was on the old board.
            if (trim($n->textContent) === '' || trim($n->textContent) === trim($n->getAttribute('href'))) {
                if (! $this->inline($to)) {
                    $this->embed($href, $to);

                    return;
                }
            }
        }
        if ($href === '' && ($topic = $n->getAttribute('data-topic')) !== '') {
            $a = $this->el('a', $to);
            $a->setAttribute('data-topic', $topic);
            $this->children($n, $a);

            return;
        }
        if ($href === '' || ! Html::safeUrl($href) || str_starts_with($href, '/')) {
            // No address, or one that only worked on the old board: the words stay.
            $this->children($n, $to);

            return;
        }
        $a = $this->el('a', $to);
        $a->setAttribute('href', $href);
        $this->children($n, $a);
    }

    private function image(DOMElement $n, DOMElement $to): void
    {
        $src = $this->absolute($n->getAttribute('src') ?: $n->getAttribute('data-src') ?: $n->getAttribute('data-original'));
        $alt = trim($n->getAttribute('alt') ?: $n->getAttribute('title'));
        if ($src === '' || ! preg_match('#^https?://#i', $src)) {
            // Never a broken image: the description, or the file's name, stays as text.
            $name = $alt !== '' ? $alt : basename((string) parse_url($src, PHP_URL_PATH));
            if ($name !== '') {
                $to->appendChild($this->doc->createTextNode('['.$name.']'));
            }

            return;
        }
        $img = $this->el('img', $to);
        $img->setAttribute('src', $src);
        if ($alt !== '') {
            $img->setAttribute('alt', $alt);
        }
    }

    private function isEmoji(DOMElement $n, string $class): bool
    {
        return $n->hasAttribute('data-emoticon') || $n->hasAttribute('data-emoji')
            || (bool) preg_match('/\s(?:emoji|emoticon|smilie|smiley|ipsemoji|wp-smiley|emoji-only|smilies|bbc_smiley|mycode_smilie|inlineimg)\s/', $class)
            || (bool) preg_match('#/(?:smilies|smileys|emoticons|emoji|images/smilies)/#i', $n->getAttribute('src'));
    }

    /** An emoji image as text: the character itself, or its code (`:)`, `:smile:`) for the forum's emoji to pick up. */
    private function emojiText(DOMElement $n): string
    {
        foreach (['alt', 'title', 'data-emoticon'] as $a) {
            $v = trim($n->getAttribute($a));
            if ($v !== '' && $v !== '1') {
                return $v;
            }
        }

        return '';
    }

    private function mention(DOMElement $n, DOMElement $to): void
    {
        $name = trim($n->getAttribute('data-mention') ?: ltrim(trim($n->textContent), '@'));
        if ($name === '') {
            return;
        }
        $span = $this->el('span', $to);
        $span->setAttribute('data-mention', $name);
        foreach (['data-user' => ['data-user', 'data-mentionid', 'data-userid', 'data-user-id'], 'data-user-name' => ['data-user-name', 'data-username'], 'data-flarum-user' => ['data-flarum-user'], 'data-flarum-post' => ['data-flarum-post'], 'data-flarum-group' => ['data-flarum-group']] as $to2 => $from) {
            foreach ($from as $a) {
                if (($v = trim($n->getAttribute($a))) !== '') {
                    $span->setAttribute($to2, $v);

                    break;
                }
            }
        }
        // Discourse's mention is a link to /u/name.
        if (! $span->hasAttribute('data-user-name') && preg_match('#/u(?:sers?)?/([^/?\#]+)#', $n->getAttribute('href'), $m)) {
            $span->setAttribute('data-user-name', rawurldecode($m[1]));
        }
        $span->appendChild($this->doc->createTextNode('@'.$name));
    }

    private function spoilerFrom(DOMElement $n, DOMElement $to, string $tag): void
    {
        $inlineTag = in_array($tag, ['span', 'a', 'strong', 'em', 'font'], true);
        if ($inlineTag || ($this->inline($to) && $tag !== 'div')) {
            $span = $this->el('span', $to);
            $span->setAttribute('data-spoiler', '1');
            $this->children($n, $span);

            return;
        }
        $d = $this->el('details', $to);
        // IPS: a header div with the title, a contents div with the text.
        $title = trim($n->getAttribute('data-title') ?: $n->getAttribute('data-ipsspoiler-title') ?: $n->getAttribute('title'));
        foreach (iterator_to_array($n->childNodes) as $c) {
            if ($c instanceof DOMElement && preg_match('/spoiler_?(?:header|title|head)|spoilertitle/i', $c->getAttribute('class'))) {
                $title = $title ?: trim($c->textContent);
                $n->removeChild($c);
            }
        }
        if ($title !== '' && ! preg_match('/^(?:spoiler|show|hide|click to (?:show|reveal|expand))$/i', $title)) {
            $d->setAttribute('data-title', $title);
        }
        $this->children($n, $d);
    }

    private function details(DOMElement $n, DOMElement $to): void
    {
        $d = $this->el('details', $to);
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMElement && strtolower($c->nodeName) === 'summary') {
                $t = trim(preg_replace('/\s+/', ' ', $c->textContent) ?? '');
                if ($t !== '') {
                    $d->setAttribute('data-title', $t);
                }

                break;
            }
        }
        if (! $d->hasAttribute('data-title') && ($t = trim($n->getAttribute('data-title')))) {
            $d->setAttribute('data-title', $t);
        }
        $this->children($n, $d);
    }

    private function quote(DOMElement $n, DOMElement $to, string $tag): void
    {
        if ($tag === 'q') {
            $to->appendChild($this->doc->createTextNode('“'));
            $this->children($n, $to);
            $to->appendChild($this->doc->createTextNode('”'));

            return;
        }
        $bq = $this->el('blockquote', $to);
        $cite = '';
        foreach (['data-cite', 'data-username', 'data-ipsquote-username', 'data-author', 'data-quote', 'rel'] as $a) {
            $v = trim($n->getAttribute($a));
            if ($v !== '' && ! in_array(strtolower($v), ['nofollow', 'noopener', 'noreferrer'], true)) {
                $cite = $v;

                break;
            }
        }
        // Discourse: <aside class="quote" data-username data-post data-topic><div class="title">…</div><blockquote>…</blockquote></aside>
        $body = $n;
        foreach (iterator_to_array($n->childNodes) as $c) {
            if (! $c instanceof DOMElement) {
                continue;
            }
            $cc = strtolower($c->getAttribute('class'));
            if (preg_match('/(?:^|\s)(?:title|quote-title|ipsquote_citation|bbcodeblock-title|quoteheader|quote_header|bbc_quote_header|attribution|bbcode_quote_header|quoteauthor|quote-author|quotetitle)(?:\s|$)/', $cc) || (strtolower($c->nodeName) === 'cite' && $tag === 'blockquote')) {
                if ($cite === '') {
                    $cite = trim(preg_replace('/\s+(?:said|wrote|schrieb|a écrit|escribió|ha scritto|написал)\s*:?\s*$/iu', '', trim($c->textContent)) ?? '');
                }
                $n->removeChild($c);
            }
            if ($tag === 'aside' && strtolower($c->nodeName) === 'blockquote') {
                $body = $c;
            }
        }
        if ($cite !== '') {
            $bq->setAttribute('data-cite', $cite);
        }
        // Discourse's data-post is a number within data-topic, not a post id.
        foreach ($n->hasAttribute('data-topic') ? [] : ['data-post', 'data-ipsquote-contentcommentid', 'data-post-id', 'data-postid'] as $a) {
            if (($v = trim($n->getAttribute($a))) !== '' && ctype_digit($v)) {
                $bq->setAttribute('data-post', $v);

                break;
            }
        }
        if ($n->hasAttribute('data-flarum-post')) {
            $bq->setAttribute('data-flarum-post', (string) (int) $n->getAttribute('data-flarum-post'));
        }
        $this->children($body, $bq);
    }

    private function pre(DOMElement $n, DOMElement $to, string $class): void
    {
        $lang = $n->getAttribute('data-lang') ?: $n->getAttribute('lang');
        $code = $n->getElementsByTagName('code')->item(0);
        if ($lang === '' && $code instanceof DOMElement) {
            $class .= ' '.strtolower($code->getAttribute('class'));
            $lang = $code->getAttribute('data-lang');
        }
        if ($lang === '' && preg_match('/(?:^|\s)(?:language|lang|brush:\s*|highlight-source-)-?([a-z0-9+#._-]+)/', $class, $m)) {
            $lang = $m[1];
        }
        $lang = strtolower(preg_replace('/[^a-z0-9+#._-]/i', '', $lang) ?? '');
        if (in_array($lang, ['auto', 'nohighlight', 'plaintext', 'text', 'none', 'prettyprint', 'prettyprinted', 'ipscode'], true)) {
            $lang = '';
        }
        $pre = $this->el('pre', $to);
        if ($lang !== '') {
            $pre->setAttribute('data-lang', $lang);
        }
        $pre->appendChild($this->doc->createTextNode(self::codeText($n)));
    }

    /** A code block's text, with the <br>s some editors use for its line breaks. */
    private static function codeText(DOMNode $n): string
    {
        $out = '';
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMText) {
                $out .= $c->data;
            } elseif ($c instanceof DOMElement) {
                $t = strtolower($c->nodeName);
                if ($t === 'br') {
                    $out .= "\n";
                } else {
                    $out .= self::codeText($c).(in_array($t, ['p', 'div', 'li'], true) ? "\n" : '');
                }
            }
        }

        return str_replace("\u{00A0}", ' ', $out);
    }

    private function embedTarget(string $src): string
    {
        $src = $this->absolute($src);
        // IPS embeds point back at the old board with the real address in ?url=.
        if (preg_match('#[?&](?:url|src)=([^&]+)#', $src, $m) && preg_match('#^https?%3A#i', $m[1])) {
            return rawurldecode($m[1]);
        }
        if (preg_match('#youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{11})#', $src, $m)) {
            return 'https://www.youtube.com/watch?v='.$m[1];
        }
        if (preg_match('#player\.vimeo\.com/video/(\d+)#', $src, $m)) {
            return 'https://vimeo.com/'.$m[1];
        }

        return $src;
    }

    private function embed(string $url, DOMElement $to): void
    {
        $url = trim($url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return;
        }
        if ($this->inline($to)) {
            $a = $this->el('a', $to);
            $a->setAttribute('href', $url);
            $a->appendChild($this->doc->createTextNode($url));

            return;
        }
        $f = $this->el('figure', $to);
        $f->setAttribute('data-media', $url);
    }

    /** Discourse's link previews: the link, not the preview's markup. */
    private function onebox(DOMElement $n, DOMElement $to): void
    {
        $url = $n->getAttribute('data-onebox-src');
        if ($url === '') {
            foreach ($n->getElementsByTagName('a') as $a) {
                if (preg_match('#^https?://#', $a->getAttribute('href'))) {
                    $url = $a->getAttribute('href');

                    break;
                }
            }
        }
        if ($url !== '') {
            $p = $this->el('p', $to);
            $a = $this->el('a', $p);
            $a->setAttribute('href', $url);
            $a->appendChild($this->doc->createTextNode($url));
        }
    }
}
