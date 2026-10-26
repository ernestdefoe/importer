<?php

namespace ErnestDefoe\Importer\Importers;

use ErnestDefoe\Importer\Markup\Html;

/**
 * BBCode → canonical HTML ({@see Html}), for every board that stores BBCode:
 * phpBB, XenForo, vBulletin 3/4/5, MyBB, SMF, Vanilla's BBCode format and
 * Web Wiz. One parser, every board's dialect:
 *
 *   quotes      [quote="Ada" post_id=1] phpBB, [QUOTE="Ada, post: 1, member: 2"]
 *               XenForo, [QUOTE=Ada;1] vBulletin, [quote='Ada' pid='1'] MyBB,
 *               [quote author=Ada link=msg=1] SMF, [quote="Ada;c-1"] Vanilla
 *   code        [code], [code=php], [CODE lang="php"], [php] [html] [sql],
 *               [icode] [tt] inline, [noparse] [nobbc] [plain] taken literally
 *   lists       [list] [list=1|a|i] [list type=decimal] [ol] [ul], [*] [li]
 *   tables      [table] [tr] [th] [td] (XenForo 2, vBulletin 4+, SMF)
 *   layout      [center] [left] [right] [justify] [align=…] [indent]
 *               [heading=2] [h=2] [h1]…[h6] [hr]
 *   style       [b] [i] [u] [s] [strike] [del] [sup] [sub] [color=…]
 *               [size=…] [font=…] [highlight] [bgcolor=…] SMF's [red] etc.
 *   hidden      [spoiler] [spoiler="Title"] [hide] [ispoiler]
 *   links       [url] [url=…] [url unfurl="true"] [iurl] [ftp] [email]
 *               [thread=1] [post=1]
 *   media       [img] [img=WxH] [IMG width=…] [media=youtube]id [video=youtube]
 *               [video=youtube;id] [youtube] [flash] [ame] [tube] [gvideo]
 *   people      [USER=1] [mention=1] [member=…]
 *   files       [attach] [ATTACH=CONFIG] [ATTACH=JSON]{…} [attachment=0]
 *
 * 🚨 Nothing a member wrote is lost. An unknown tag that is opened and closed
 * loses its markup and keeps its text; a bracket that is not a tag at all, or
 * a tag never closed, stays exactly as written. An attachment the importer
 * cannot copy stays as its file name.
 */
class Bbcode
{
    /**
     * @param  array{uid?:string,escaped?:bool,attachment?:\Closure,size?:string}  $opts
     *   uid         phpBB stores tags as [b:uid]…[/b:uid] — strip the suffix.
     *   escaped     phpBB and SMF store the text HTML-escaped — decode it first.
     *   attachment  fn(string $sourceId): ?array{url:string,filename:string,isImage:bool}
     *               resolves a source attachment to a rehosted file; null keeps
     *               the reference as its name.
     *   size        how the board measures [size=n]: 'html' 1–7 (default),
     *               'percent' (phpBB), 'pt' (MyBB), 'px'.
     *   html        the board allowed HTML in this post as well as BBCode
     *               (vBulletin's htmlstate, Web Wiz): 'raw' keeps the text's
     *               HTML and its line breaks as whitespace, 'nl2br' keeps the
     *               HTML and turns line breaks into breaks. {@see Html::clean()}
     *               makes it safe afterwards.
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
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $p = new self($opts);

        return $p->flow($p->parse($text));
    }

    /* ── phpBB's two storage formats ────────────────────────────────────── */

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
            return self::toHtml(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_XML1, 'UTF-8'), ['size' => 'percent']);
        }

        return self::toHtml(self::phpbbLegacy($text, $uid), ['escaped' => true, 'size' => 'percent']);
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

        // Smilies → the emoji they stand for; a board's own custom smiley keeps its code.
        $text = preg_replace_callback('#<img\s[^>]*?alt="([^"]*)"[^>]*>#i', fn ($m) => \ErnestDefoe\Importer\Markup\Smilies::code(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')), $text) ?? $text;

        $text = preg_replace([
            '#<br\s*/?>#i',
            '#<!--.*?-->#s',
            '#</?[a-z][^>]*>#i',                    // anything else phpBB inserted
        ], ["\n", '', ''], $text) ?? $text;

        return $text;
    }

    /* ── The parser ─────────────────────────────────────────────────────── */

    /** Content taken literally, up to the matching close tag. */
    private const RAW = ['code', 'php', 'html', 'sql', 'js', 'javascript', 'css', 'xml', 'pre', 'icode', 'c', 'noparse', 'nobbc', 'plain', 'img', 'media', 'video', 'youtube', 'yt', 'flash', 'ame', 'tube', 'gvideo', 'vimeo', 'dailymotion', 'attach', 'attachment', 'email', 'ftp', 'iurl'];

    private const VOID = ['*', 'hr', 'br'];

    private const COLOR_TAGS = ['red', 'blue', 'green', 'black', 'white', 'orange', 'purple', 'yellow', 'pink', 'brown', 'beige', 'teal', 'navy', 'maroon', 'limegreen', 'grey', 'gray'];

    /** Tags whose output is a block, so they end the paragraph around them. */
    private const BLOCKS = ['quote', 'spoiler', 'hide', 'hidden', 'spoil', 'center', 'left', 'right', 'justify', 'align', 'indent', 'list', 'ol', 'ul', 'table', 'heading', 'h', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'code', 'php', 'html', 'sql', 'js', 'javascript', 'css', 'xml', 'pre', 'media', 'video', 'youtube', 'yt', 'flash', 'ame', 'tube', 'gvideo', 'vimeo', 'dailymotion', 'tr', 'td', 'th', 'thead', 'tbody', 'li', '*'];

    private const KNOWN = ['b', 'i', 'u', 's', 'strike', 'del', 'ins', 'sup', 'sub', 'color', 'colour', 'size', 'font', 'face', 'highlight', 'hl', 'bgcolor', 'tt', 'glow', 'shadow', 'blur', 'small', 'big', 'abbr', 'acronym', 'bdo', 'time', 'me', 'move', 'marquee', 'url', 'link', 'email', 'iurl', 'ftp', 'user', 'mention', 'member', 'thread', 'post', 'topic', 'ispoiler', 'anchor', 'sigpic', 'gallery', 'left', 'center', 'right', 'justify'];

    private function __construct(private array $opts)
    {
    }

    /** @return list<array<string,mixed>> */
    private function parse(string $text): array
    {
        $re = '~\[(/?)(\*|[a-z][a-z0-9_-]*)((?:=|\s)(?:"[^"\]]*"|\'[^\'\]]*\'|[^\]"\'])*)?\]~i';
        preg_match_all($re, $text, $tok, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $root = ['tag' => '#root', 'kids' => []];
        $stack = [&$root];
        $pos = 0;
        $len = strlen($text);
        $i = 0;
        $n = count($tok);
        $known = fn (string $t) => in_array($t, self::RAW, true) || in_array($t, self::VOID, true) || in_array($t, self::BLOCKS, true) || in_array($t, self::KNOWN, true) || in_array($t, self::COLOR_TAGS, true);

        while ($i < $n) {
            [$full, $at] = $tok[$i][0];
            if ($at < $pos) {
                $i++;

                continue;
            }
            $close = $tok[$i][1][0] === '/';
            $tag = strtolower($tok[$i][2][0]);
            $arg = $tok[$i][3][0] ?? '';
            $top = &$stack[count($stack) - 1];
            if ($at > $pos) {
                $top['kids'][] = substr($text, $pos, $at - $pos);
            }
            $pos = $at + strlen($full);

            if (! $close) {
                if (in_array($tag, self::RAW, true)) {
                    $end = stripos($text, '[/'.$tag.']', $pos);
                    if ($end === false) {
                        $top['kids'][] = $full; // never closed: as written

                        $i++;
                        unset($top);

                        continue;
                    }
                    $top['kids'][] = ['tag' => $tag, 'arg' => $arg, 'raw' => substr($text, $pos, $end - $pos), 'open' => $full];
                    $pos = $end + strlen('[/'.$tag.']');
                    $i++;
                    unset($top);

                    continue;
                }
                if ($tag === '*' || $tag === 'li') {
                    // An item ends the previous item of the same list.
                    for ($k = count($stack) - 1; $k > 0; $k--) {
                        $t = $stack[$k]['tag'];
                        if (in_array($t, ['list', 'ol', 'ul'], true)) {
                            break;
                        }
                        if ($t === '*' || $t === 'li') {
                            array_splice($stack, $k);
                            break;
                        }
                    }
                    $top = &$stack[count($stack) - 1];
                    $top['kids'][] = ['tag' => 'li', 'arg' => '', 'kids' => [], 'open' => $full, 'closed' => true];
                    $stack[] = &$top['kids'][count($top['kids']) - 1];
                    $i++;
                    unset($top);

                    continue;
                }
                if (in_array($tag, self::VOID, true)) {
                    $top['kids'][] = ['tag' => $tag, 'arg' => $arg, 'kids' => [], 'open' => $full, 'closed' => true];
                    // vBulletin writes [HR][/HR].
                    if (isset($tok[$i + 1]) && strtolower($tok[$i + 1][2][0]) === $tag && $tok[$i + 1][1][0] === '/' && $tok[$i + 1][0][1] === $pos) {
                        $pos += strlen($tok[$i + 1][0][0]);
                        $i++;
                    }
                    $i++;
                    unset($top);

                    continue;
                }
                $top['kids'][] = ['tag' => $tag, 'arg' => $arg, 'kids' => [], 'open' => $full, 'closed' => false, 'known' => $known($tag)];
                $stack[] = &$top['kids'][count($top['kids']) - 1];
                $i++;
                unset($top);

                continue;
            }

            // A close tag: back to its open tag, closing anything left open inside it.
            $want = $tag === '*' ? 'li' : $tag;
            $found = null;
            for ($k = count($stack) - 1; $k > 0; $k--) {
                if ($stack[$k]['tag'] === $want || ($want === 'li' && $stack[$k]['tag'] === '*')) {
                    $found = $k;
                    break;
                }
            }
            if ($found === null) {
                $top['kids'][] = $full; // a close with no open: as written
            } else {
                $stack[$found]['closed'] = true;
                for ($k = $found + 1; $k < count($stack); $k++) {
                    // Tags we know left open inside are closed with it; unknown ones stay as written.
                    if ($stack[$k]['tag'] === 'li' || ! empty($stack[$k]['known'])) {
                        $stack[$k]['closed'] = true;
                    }
                }
                array_splice($stack, $found);
            }
            $i++;
            unset($top);
        }
        $top = &$stack[count($stack) - 1];
        if ($pos < $len) {
            $top['kids'][] = substr($text, $pos);
        }
        unset($top);

        return $root['kids'];
    }

    /* ── Rendering ──────────────────────────────────────────────────────── */

    /**
     * Children laid out as blocks: text split into paragraphs on blank
     * lines, line breaks kept, block tags between paragraphs.
     */
    private function flow(array $kids): string
    {
        if (($this->opts['html'] ?? '') === 'raw') {
            $out = '';
            foreach ($kids as $k) {
                $out .= $this->node($k)[1];
            }

            return $out;
        }
        $out = '';
        $buf = '';
        $afterBlock = false;
        $paragraphs = function (string $buf): string {
            $html = '';
            foreach (preg_split('/\n\s*\n/', $buf) ?: [] as $para) {
                $para = trim($para, "\n ");
                if (trim(strip_tags($para, '<img>')) === '') {
                    continue;
                }
                $html .= '<p>'.str_replace("\n", '<br>', $para).'</p>';
            }

            return $html;
        };
        foreach ($kids as $k) {
            [$kind, $html] = $this->node($k);
            if ($kind === 'block') {
                $out .= $paragraphs(preg_replace('/\n[ \t]*$/', '', $buf) ?? $buf).$html;
                $buf = '';
                $afterBlock = true;

                continue;
            }
            if ($afterBlock) {
                $html = preg_replace('/^[ \t]*\n/', '', $html) ?? $html;
                $afterBlock = $html === '';
            }
            $buf .= $html;
        }

        return $out.$paragraphs($buf);
    }

    /** Children as inline HTML: line breaks become <br>. */
    private function inline(array $kids): string
    {
        $out = '';
        foreach ($kids as $k) {
            [, $html] = $this->node($k, true);
            $out .= $html;
        }

        return str_replace("\n", '<br>', $out);
    }

    /** Does any child (at any depth through inline tags) render as a block? */
    private function hasBlock(array $kids): bool
    {
        foreach ($kids as $k) {
            if (is_array($k) && $this->isBlockNode($k)) {
                return true;
            }
            if (is_array($k) && isset($k['kids']) && $this->hasBlock($k['kids'])) {
                return true;
            }
        }

        return false;
    }

    private function isBlockNode(array $k): bool
    {
        if (! in_array($k['tag'], self::BLOCKS, true)) {
            return false;
        }

        // [url], [img] and friends are inline; a code tag is a block.
        return ! (in_array($k['tag'], ['left', 'center', 'right', 'justify'], true) && empty($k['closed']));
    }

    /**
     * @return array{0:'inline'|'block',1:string}
     */
    private function node(mixed $k, bool $inInline = false): array
    {
        if (is_string($k)) {
            return ['inline', empty($this->opts['html']) ? htmlspecialchars($k, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $k];
        }
        $tag = $k['tag'];

        // Never closed (or not a tag we know with no close): as written.
        if (isset($k['kids']) && empty($k['closed'])) {
            return ['inline', htmlspecialchars($k['open'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').$this->inline($k['kids'])];
        }
        if (isset($k['raw'])) {
            return $this->rawNode($tag, $this->arg($k['arg']), $k['raw'], $k['arg'], $inInline);
        }

        $arg = $this->arg($k['arg']);
        $kids = $k['kids'];
        $wrap = function (string $open, string $close) use ($kids, $inInline): array {
            // An inline tag around blocks (a colour around a quote): it applies inside each.
            if (! $inInline && $this->hasBlock($kids)) {
                return ['block', $this->flowWrapped($kids, $open, $close)];
            }

            return ['inline', $open.$this->inline($kids).$close];
        };
        $block = fn (string $open, string $close) => ['block', $open.$this->flow($kids).$close];

        switch ($tag) {
            case 'b':
                return $wrap('<strong>', '</strong>');
            case 'i':
                return $wrap('<em>', '</em>');
            case 'u':
            case 'ins':
                return $wrap('<u>', '</u>');
            case 's':
            case 'strike':
            case 'del':
                return $wrap('<del>', '</del>');
            case 'sup':
            case 'sub':
                return $wrap('<'.$tag.'>', '</'.$tag.'>');
            case 'tt':
                return ['inline', '<code>'.htmlspecialchars($this->plain($kids), ENT_QUOTES, 'UTF-8').'</code>'];
            case 'color':
            case 'colour':
                $c = Html::color($arg) ?? Html::color(preg_split('/[,\s]/', $arg)[0] ?? '');

                return $c ? $wrap('<span data-color="'.$c.'">', '</span>') : $wrap('', '');
            case 'glow':
            case 'shadow':
            case 'blur':
            case 'font':
            case 'face':
            case 'small':
            case 'big':
            case 'abbr':
            case 'acronym':
            case 'bdo':
            case 'time':
            case 'move':
            case 'marquee':
            case 'anchor':
                return $wrap('', '');
            case 'size':
                $s = Html::size($arg, $this->opts['size'] ?? 'html');

                return $s ? $wrap('<span data-size="'.$s.'">', '</span>') : $wrap('', '');
            case 'highlight':
            case 'hl':
                return $wrap('<mark data-color="#ffff00">', '</mark>');
            case 'bgcolor':
                $c = Html::color($arg);

                return $wrap('<mark'.($c ? ' data-color="'.$c.'"' : '').'>', '</mark>');
            case 'me':
                return ['inline', '* '.htmlspecialchars($arg, ENT_QUOTES, 'UTF-8').' '.$this->inline($kids)];
            case 'ispoiler':
                return ['inline', '<span data-spoiler="1">'.$this->inline($kids).'</span>'];
            case 'url':
            case 'link':
                return ['inline', $this->link($arg !== '' ? $arg : $this->plain($kids), $arg !== '' ? $this->inline($kids) : null)];
            case 'user':
            case 'mention':
            case 'member':
                $name = trim(ltrim(trim($this->plain($kids)), '@'));
                if ($tag === 'member' && $name === '') {
                    $name = $arg;
                }
                $id = ctype_digit($arg) ? $arg : '';

                return ['inline', '<span data-mention="'.htmlspecialchars($name, ENT_QUOTES, 'UTF-8').'"'.($id !== '' ? ' data-user="'.$id.'"' : '').'>@'.htmlspecialchars($name, ENT_QUOTES, 'UTF-8').'</span>'];
            case 'thread':
            case 'topic':
                $id = preg_replace('/\D/', '', $arg) ?: preg_replace('/\D/', '', $this->plain($kids));
                $label = $this->inline($kids);

                return ['inline', $id !== '' ? '<a data-topic="'.$id.'">'.($label !== '' ? $label : htmlspecialchars($this->plain($kids), ENT_QUOTES, 'UTF-8')).'</a>' : $label];
            case 'post':
            case 'sigpic':
            case 'gallery':
                return ['inline', $this->inline($kids)];
            case 'quote':
                return ['block', $this->quote($k['arg'], $this->flow($kids))];
            case 'spoiler':
            case 'hide':
            case 'hidden':
            case 'spoil':
                $title = trim($arg);

                return ['block', '<details'.($title !== '' ? ' data-title="'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'"' : '').'>'.$this->flow($kids).'</details>'];
            case 'center':
            case 'left':
            case 'right':
            case 'justify':
                return $block('<div data-align="'.$tag.'">', '</div>');
            case 'align':
                $a = strtolower(trim($arg));

                return in_array($a, ['center', 'right', 'justify'], true) ? $block('<div data-align="'.$a.'">', '</div>') : $block('<div>', '</div>');
            case 'indent':
                return $block('<div>', '</div>');
            case 'heading':
            case 'h':
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $lvl = $tag[1] ?? '';
                $lvl = max(1, min(6, (int) ($lvl !== '' && ctype_digit($lvl) ? $lvl : ($arg !== '' ? $arg : 2))));

                return ['block', '<h'.$lvl.'>'.$this->inline($kids).'</h'.$lvl.'>'];
            case 'hr':
                return ['block', '<hr>'];
            case 'br':
                return ['inline', "\n"];
            case 'list':
            case 'ol':
            case 'ul':
                return ['block', $this->listHtml($tag, $k['arg'], $kids)];
            case 'li':
            case '*':
                return ['block', '<li>'.$this->flow($kids).'</li>'];
            case 'table':
                return ['block', '<table>'.$this->tableRows($kids).'</table>'];
            case 'thead':
            case 'tbody':
                return ['block', $this->tableRows($kids)];
            case 'tr':
                return ['block', '<tr>'.$this->tableCells($kids).'</tr>'];
            case 'td':
            case 'th':
                return ['block', '<'.$tag.'>'.$this->flow($kids).'</'.$tag.'>'];
        }

        if (in_array($tag, self::COLOR_TAGS, true)) {
            return $wrap('<span data-color="'.Html::color($tag === 'limegreen' ? 'lime' : $tag).'">', '</span>');
        }

        // Known to no board we read: the markup goes, the words stay.
        return $wrap('', '');
    }

    /** An inline tag around block content: applied to each run of text inside. */
    private function flowWrapped(array $kids, string $open, string $close): string
    {
        $out = [];
        $run = [];
        foreach ($kids as $k) {
            if (is_array($k) && $this->isBlockNode($k)) {
                if ($run) {
                    $out[] = ['tag' => '#wrap', 'kids' => $run];
                    $run = [];
                }
                $out[] = $k;
            } else {
                $run[] = $k;
            }
        }
        if ($run) {
            $out[] = ['tag' => '#wrap', 'kids' => $run];
        }
        $html = '';
        foreach ($out as $k) {
            if ($k['tag'] === '#wrap') {
                $inner = $this->flow($k['kids']);
                $html .= $open === '' ? $inner : preg_replace('#<p>(.*?)</p>#s', '<p>'.$open.'$1'.$close.'</p>', $inner);
            } else {
                $html .= $this->node($k)[1];
            }
        }

        return $html;
    }

    private function rawNode(string $tag, string $arg, string $raw, string $rawArg, bool $inInline): array
    {
        switch ($tag) {
            case 'code':
            case 'php':
            case 'html':
            case 'sql':
            case 'js':
            case 'javascript':
            case 'css':
            case 'xml':
            case 'pre':
                $lang = $tag === 'code' || $tag === 'pre' ? $this->codeLang($rawArg) : ($tag === 'javascript' ? 'js' : $tag);
                $code = str_replace("\u{00A0}", ' ', trim($raw, "\n"));
                $esc = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                // [code] on one line inside a sentence is inline code (XenForo, vBulletin do this).
                if ($inInline && ! str_contains($code, "\n")) {
                    return ['inline', '<code>'.$esc.'</code>'];
                }

                return ['block', '<pre'.($lang !== '' ? ' data-lang="'.$lang.'"' : '').'>'.$esc.'</pre>'];
            case 'icode':
            case 'c':
                return ['inline', '<code>'.htmlspecialchars(str_replace("\n", ' ', $raw), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code>'];
            case 'noparse':
            case 'nobbc':
            case 'plain':
                return ['inline', htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')];
            case 'img':
                $src = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (! preg_match('#^https?://#i', $src) && preg_match('#^https?://#i', $arg)) {
                    $src = $arg; // [img=URL]
                }
                $alt = '';
                if (preg_match('/\balt\s*=\s*(["\']?)(.*?)\1(?:\s|$)/i', $rawArg, $m)) {
                    $alt = trim($m[2]);
                }
                if (! preg_match('#^(?:https?:)?//#i', $src)) {
                    return ['inline', htmlspecialchars($raw, ENT_QUOTES, 'UTF-8')];
                }
                if (str_starts_with($src, '//')) {
                    $src = 'https:'.$src;
                }

                return ['inline', '<img src="'.htmlspecialchars($src, ENT_QUOTES, 'UTF-8').'"'.($alt !== '' ? ' alt="'.htmlspecialchars($alt, ENT_QUOTES, 'UTF-8').'"' : '').'>'];
            case 'email':
                $addr = trim($arg !== '' ? $arg : $raw);
                $label = $arg !== '' ? $raw : $addr;
                if (! filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    return ['inline', htmlspecialchars($label, ENT_QUOTES, 'UTF-8')];
                }

                return ['inline', '<a href="mailto:'.htmlspecialchars($addr, ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</a>'];
            case 'iurl':
            case 'ftp':
                return ['inline', $this->link($arg !== '' ? $arg : $raw, $arg !== '' ? htmlspecialchars($raw, ENT_QUOTES, 'UTF-8') : null)];
            case 'attach':
            case 'attachment':
                return ['inline', $this->attachment($rawArg, $raw)];
        }

        // Media: [media=youtube]id, [video=youtube]url, [video=youtube;id]url, [youtube]id, [flash=w,h]url…
        $value = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $provider = match ($tag) {
            'youtube', 'yt', 'tube' => 'youtube',
            'vimeo' => 'vimeo',
            'dailymotion' => 'dailymotion',
            default => strtolower(trim(explode(';', explode(',', $arg)[0])[0])),
        };
        $url = preg_match('#^https?://#i', $value) ? $value : null;
        if (! $url && str_contains($arg, ';')) {
            $url = Html::mediaUrl($provider, trim(explode(';', $arg, 2)[1]));
        }
        $url ??= Html::mediaUrl($provider, $value);
        if (! $url) {
            return ['inline', htmlspecialchars($value, ENT_QUOTES, 'UTF-8')];
        }
        $esc = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        return $inInline ? ['inline', '<a href="'.$esc.'">'.$esc.'</a>'] : ['block', '<figure data-media="'.$esc.'"></figure>'];
    }

    /** The attachment, rehosted when the importer can copy it, else its name — never a broken reference. */
    private function attachment(string $rawArg, string $raw): string
    {
        $value = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $srcId = null;
        $name = '';
        $json = json_decode($value, true);
        if (is_array($json)) {
            $srcId = isset($json['data-attachmentid']) ? (string) $json['data-attachmentid'] : null;
            $name = (string) ($json['data-filename'] ?? $json['alt'] ?? $json['title'] ?? '');
        } elseif (preg_match('/^n?(\d+)$/', $value, $m)) {
            $srcId = $m[1]; // vBulletin [ATTACH=CONFIG]n123[/ATTACH], XenForo [ATTACH]123[/ATTACH]
        } else {
            $name = strip_tags(preg_replace('/<!--.*?-->/s', '', $value) ?? $value); // phpBB [attachment=0]file.jpg[/attachment]
        }
        if (preg_match('/\balt\s*=\s*"([^"]*)"/i', $rawArg, $m) || preg_match('/\bname\s*=\s*"?([^"\]\s]+)/i', $rawArg, $m)) {
            $name = $name ?: trim($m[1]);
        }
        if (preg_match('/\bid\s*=\s*"?(\d+)/i', $rawArg, $m)) {
            $srcId ??= $m[1]; // SMF [attach id=12]
        }

        $resolver = $this->opts['attachment'] ?? null;
        if ($resolver && $srcId !== null && ($found = $resolver($srcId)) && ! empty($found['url'])) {
            $url = htmlspecialchars($found['url'], ENT_QUOTES, 'UTF-8');
            if (! empty($found['isImage'])) {
                return '<img src="'.$url.'" alt="'.htmlspecialchars((string) ($found['filename'] ?? ''), ENT_QUOTES, 'UTF-8').'">';
            }

            return '<a href="'.$url.'">'.htmlspecialchars((string) ($found['filename'] ?? $found['url']), ENT_QUOTES, 'UTF-8').'</a>';
        }

        $name = trim($name) !== '' ? trim($name) : ($srcId !== null ? '#'.$srcId : '');

        return $name === '' ? '' : '<span data-attachment="1">['.htmlspecialchars(Html::attachmentLabel($name), ENT_QUOTES, 'UTF-8').']</span>';
    }

    private function link(string $href, ?string $labelHtml): string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $label = $labelHtml ?? htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
        if (preg_match('#^www\.#i', $href)) {
            $href = 'http://'.$href;
        }
        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        }
        if (! preg_match('#^(?:https?|ftp)://#i', $href)) {
            return $label; // javascript:, data:, a path on the old board: the words stay
        }

        return '<a href="'.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').'">'.($label !== '' ? $label : htmlspecialchars($href, ENT_QUOTES, 'UTF-8')).'</a>';
    }

    private function listHtml(string $tag, string $rawArg, array $kids): string
    {
        $arg = strtolower($this->arg($rawArg));
        if (preg_match('/type\s*=\s*"?([a-z-]+)/', strtolower($rawArg), $m)) {
            $arg = $m[1];
        }
        $ordered = $tag === 'ol' || ($tag === 'list' && $arg !== '' && ! in_array($arg, ['*', 'disc', 'circle', 'square', 'none', 'bullet'], true));
        $start = ctype_digit($arg) && (int) $arg > 1 ? (int) $arg : 0;
        $items = '';
        $loose = [];
        foreach ($kids as $k) {
            if (is_array($k) && in_array($k['tag'], ['li', '*'], true)) {
                $items .= $this->looseItem($loose).$this->node($k)[1];
            } elseif (is_array($k) && in_array($k['tag'], ['list', 'ol', 'ul'], true) && $items !== '') {
                // A nested list belongs to the item before it.
                $items .= $this->looseItem($loose);
                $items = preg_replace('#</li>$#', $this->node($k)[1].'</li>', $items) ?? $items;
            } else {
                $loose[] = $k;
            }
        }
        $items .= $this->looseItem($loose);

        return $ordered ? '<ol'.($start ? ' start="'.$start.'"' : '').'>'.$items.'</ol>' : '<ul>'.$items.'</ul>';
    }

    /** Text in a list before (or between) its [*] items is an item of its own. */
    private function looseItem(array &$loose): string
    {
        $html = $loose && trim(strip_tags($this->inline($loose))) !== '' ? '<li>'.$this->flow($loose).'</li>' : '';
        $loose = [];

        return $html;
    }

    private function tableRows(array $kids): string
    {
        $html = '';
        foreach ($kids as $k) {
            if (is_array($k) && in_array($k['tag'], ['tr', 'thead', 'tbody'], true)) {
                $html .= $this->node($k)[1];
            }
        }

        return $html;
    }

    private function tableCells(array $kids): string
    {
        $html = '';
        foreach ($kids as $k) {
            if (is_array($k) && in_array($k['tag'], ['td', 'th'], true)) {
                $html .= $this->node($k)[1];
            }
        }

        return $html;
    }

    /** The text of some nodes, without markup (a URL inside [url], a name inside [user]). */
    private function plain(array $kids): string
    {
        $out = '';
        foreach ($kids as $k) {
            $out .= is_string($k) ? $k : ($k['raw'] ?? $this->plain($k['kids'] ?? []));
        }

        return html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** `=value`, `="value"` or `='value'` → value; attribute-style args are left for the tag to read. */
    private function arg(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw[0] !== '=') {
            return '';
        }
        $v = trim(substr($raw, 1));
        if (preg_match('/^(["\'])(.*)\1/s', $v, $m)) {
            return $m[2];
        }

        return $v;
    }

    private function codeLang(string $rawArg): string
    {
        $v = $this->arg($rawArg);
        if ($v === '' && preg_match('/\blang\s*=\s*["\']?([\w+#.-]+)/i', $rawArg, $m)) {
            $v = $m[1];
        }
        $v = strtolower(preg_replace('/[^\w+#.-]/', '', $v) ?? '');

        return in_array($v, ['', 'rich', 'text', 'plain', 'none', 'code'], true) ? '' : $v;
    }

    /**
     * A quote keeps who it quotes, and which post when the board says, as
     * data-cite and data-post. The attribute arrives in whichever form the
     * board wrote it:
     *   [quote="Kryptos;c-123"]  Vanilla      [quote=Kryptos;123]  vBulletin
     *   [quote="Kryptos" post_id=1 time=… user_id=…]  phpBB 3.2+
     *   [quote='Kryptos' pid='1' dateline='…']  MyBB   [quote author=Kryptos link=… date=…]  SMF
     *   [QUOTE="Kryptos, post: 1, member: 2"]  XenForo
     */
    private function quote(string $attr, string $body): string
    {
        $a = trim(html_entity_decode($attr, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $name = match (true) {
            (bool) preg_match('/^=\s*(["\'])(.*?)\1/s', $a, $m) => $m[2],
            (bool) preg_match('/^=(.*?)(?=\s+\w+=|$)/s', $a, $m) => $m[1],
            (bool) preg_match('/^author=(.*?)(?=\s+\w+=|$)/s', $a, $m) => $m[1],
            default => '',
        };
        $post = match (true) {
            (bool) preg_match('/\bpost(?:_id)?\s*[:=]\s*["\']?(\d+)/i', $a, $m) => $m[1],
            (bool) preg_match('/\bpid\s*=\s*["\']?(\d+)/i', $a, $m) => $m[1],
            (bool) preg_match('/\blink=(?:msg=|topic=\d+\.msg)(\d+)/i', $a, $m) => $m[1],
            (bool) preg_match('/;n?(\d+)\s*$/', $name, $m) => $m[1],
            default => '',
        };
        $name = trim((string) preg_replace('/;.*$/s', '', $name)); // ;123 / ;c-123 is the quoted post
        $name = trim((string) preg_replace('/,\s*(?:post|member|user|thread)\s*:.*$/is', '', $name)); // XenForo's ", post: 1, member: 2"

        return '<blockquote'
            .($name !== '' ? ' data-cite="'.htmlspecialchars($name, ENT_QUOTES, 'UTF-8').'"' : '')
            .($post !== '' ? ' data-post="'.$post.'"' : '')
            .'>'.$body.'</blockquote>';
    }
}
