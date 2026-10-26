<?php

namespace ErnestDefoe\Importer\Markup;

use DOMElement;
use DOMNode;
use DOMText;

/**
 * Canonical HTML → the text the target forum's formatter parses.
 *
 * Every source reader (BBCode, source HTML, Markdown, Quill) produces the same
 * small HTML vocabulary (see {@see Html}); this writes it in whatever the
 * forum understands, per construct, as {@see Target} measured:
 *
 *   Markdown forum   `**b**`, `> quote`, fenced code, `>!` spoilers — plus
 *                    BBCode (or Scribe's HTML) for what Markdown cannot say,
 *                    when the forum has them: underline, colour, size, centre,
 *                    tables.
 *   HTML (Scribe)    the editor's own elements and data attributes, so a post
 *                    opens in the editor exactly as if it had been typed there.
 *   BBCode only      flarum/bbcode's tags; a quote keeps its author natively.
 *   neither          readable plain text.
 *
 * 🚨 A construct the forum cannot show is DEGRADED, never dropped: a table
 * becomes rows of "a | b", a spoiler its title and text, a heading a bold
 * line, a colour or font just its words. Nothing a member wrote disappears.
 *
 * All text is escaped for the syntax being written AND for the backslash
 * escaper core always runs, so a member's literal `**`, `[b]`, `<u>` or `\_`
 * reads exactly as typed.
 */
final class Writer
{
    private string $mode;

    /** Inline state: the next character starts a line (for Markdown's line-start syntax). */
    private bool $lineStart = true;

    private int $depth = 0;

    /** Inside a link's label: no links within it. */
    private bool $inLink = false;

    /**
     * @param  array{user?:array<string,int>,post?:array<string,int>,topic?:array<string,int>,name?:array<string,int>}  $refs
     *         source id (or lowercased name) → Flarum id, resolved for this batch by {@see Refs}
     */
    private function __construct(private Target $t, private array $refs, private ?\Closure $topicUrl)
    {
        $this->mode = $t->mode;
    }

    public static function write(string|DOMElement $html, ?Target $target = null, array $refs = [], ?\Closure $topicUrl = null): string
    {
        $root = is_string($html) ? Html::load($html) : $html;
        if (! $root) {
            return '';
        }
        $w = new self($target ?? Target::current(), $refs, $topicUrl);

        return trim($w->join($w->blocks($root)), "\n");
    }

    /* ── Blocks ─────────────────────────────────────────────────────────── */

    private const BLOCK = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'ul', 'ol', 'pre', 'hr', 'table', 'details', 'aside', 'figure', 'li'];

    public static function isBlock(DOMNode $n): bool
    {
        return $n instanceof DOMElement && in_array(strtolower($n->nodeName), self::BLOCK, true);
    }

    /**
     * Render a container's children as blocks. Loose inline content between
     * block elements becomes an implicit paragraph.
     *
     * @return list<array{0:string,1:string}>  [kind, text]
     */
    private function blocks(DOMNode $parent, ?string $align = null): array
    {
        $out = [];
        $run = [];
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMElement && self::isBlock($child)) {
                $this->flushRun($run, $out, $align);
                array_push($out, ...$this->block($child));
            } else {
                $run[] = $child;
            }
        }
        $this->flushRun($run, $out, $align);

        return $out;
    }

    /** @param DOMNode[] $run */
    private function flushRun(array &$run, array &$out, ?string $align): void
    {
        if ($run && ($p = $this->paragraph($run, $align)) !== null) {
            $out[] = [$this->kindOf($p), $p];
        }
        $run = [];
    }

    /** @return list<array{0:string,1:string}> */
    private function block(DOMElement $el): array
    {
        $tag = strtolower($el->nodeName);
        $this->depth++;
        try {
            return match ($tag) {
                'p' => ($p = $this->paragraph(iterator_to_array($el->childNodes), $el->getAttribute('data-align') ?: null)) === null ? [] : [[$this->kindOf($p), $p]],
                'div' => $this->aligned($el),
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $this->heading((int) $tag[1], $el),
                'blockquote' => [['quote', $this->quote($el)]],
                'ul', 'ol' => ($l = $this->listBlock($el)) === '' ? [] : [['list', $l]],
                'li' => $this->blocks($el),
                'pre' => [['code', $this->code($el)]],
                'hr' => [['hr', $this->hr()]],
                'table' => $this->table($el),
                'details' => [['spoiler', $this->spoiler($el)]],
                'aside' => [['quote', $this->aside($el)]],
                'figure' => $this->figure($el),
                default => $this->blocks($el),
            };
        } finally {
            $this->depth--;
        }
    }

    /** @param list<array{0:string,1:string}> $blocks */
    private function join(array $blocks): string
    {
        if ($this->mode === Target::HTML) {
            return implode('', array_column($blocks, 1));
        }
        $out = '';
        $prev = null;
        foreach ($blocks as [$kind, $text]) {
            if ($text === '') {
                continue;
            }
            if ($prev !== null) {
                $out .= match (true) {
                    // Two lists in a row merge into one unless kept apart by
                    // two blank lines — measured on Flarum's Markdown.
                    $this->mode === Target::MARKDOWN && $prev === 'list' && $kind === 'list' => "\n\n\n",
                    // BBCode block tags sit on their own line; a blank one
                    // around them would show as an extra gap.
                    $this->mode === Target::BBCODE && ($prev !== 'p' || $kind !== 'p') => "\n",
                    default => "\n\n",
                };
            }
            $out .= $text;
            $prev = $kind;
        }

        return $out;
    }

    /** @param DOMNode[] $nodes */
    private function paragraph(array $nodes, ?string $align): ?string
    {
        $this->lineStart = true;
        $inner = $this->trimInline($this->inlines($nodes));
        if ($inner === '') {
            return null;
        }

        return match ($this->mode) {
            Target::HTML => '<p'.($align && $align !== 'left' && $this->t->html('align') ? ' data-align="'.$this->attr($align).'"' : '').'>'.$inner.'</p>',
            default => $this->alignText($inner, $align),
        };
    }

    /** Centre/right a run of text where the forum has a BBCode for it; otherwise it is left as it is. */
    private function alignText(string $inner, ?string $align): string
    {
        if ($align === 'center' && $this->t->bb('center')) {
            return '[center]'.$inner.'[/center]';
        }
        if ($align === 'right' && $this->t->bb('right')) {
            return '[right]'.$inner.'[/right]';
        }

        return $inner;
    }

    /** `<div data-align>` around blocks: alignment for each, where it can be kept. */
    private function aligned(DOMElement $el): array
    {
        $align = $el->getAttribute('data-align') ?: null;
        if ($align && $this->mode === Target::HTML) {
            foreach ($el->childNodes as $c) {
                if ($c instanceof DOMElement && in_array(strtolower($c->nodeName), ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'figure'], true) && ! $c->hasAttribute('data-align')) {
                    $c->setAttribute('data-align', $align);
                }
            }
            // Loose text in the div becomes an aligned paragraph too.
            return $this->blocks($el, $align);
        }
        $blocks = $this->blocks($el);
        if (! $align || $blocks === [] || $this->mode === Target::TEXT) {
            return $blocks;
        }
        $tag = $align === 'center' && $this->t->bb('center') ? 'center' : ($align === 'right' && $this->t->bb('right') ? 'right' : null);
        if (! $tag) {
            return $blocks;
        }
        return [['center', '['.$tag.']'.$this->join($blocks).'[/'.$tag.']']];
    }

    /** A paragraph wrapped in a BBCode block tag sits on its own line like the other blocks. */
    private function kindOf(string $p): string
    {
        return $this->mode === Target::BBCODE && preg_match('#^\[(center|right)\]#', $p) ? 'center' : 'p';
    }

    private function heading(int $level, DOMElement $el): array
    {
        $this->lineStart = false; // `#` inside a heading is not a heading marker
        $inner = $this->trimInline(str_replace("\n", ' ', $this->inlines(iterator_to_array($el->childNodes))));
        if ($inner === '') {
            return [];
        }
        $align = $el->getAttribute('data-align');

        return [['p', match ($this->mode) {
            Target::MARKDOWN => str_repeat('#', $level).' '.$inner,
            Target::HTML => $this->t->html('h')
                ? '<h'.$level.($align && $align !== 'left' && $this->t->html('halign') ? ' data-align="'.$this->attr($align).'"' : '').'>'.$inner.'</h'.$level.'>'
                : '<p><strong>'.$inner.'</strong></p>',
            Target::BBCODE => '[b]'.($this->t->bb('size') ? '[size='.[1 => 28, 24, 20, 18, 16, 14][$level].']'.$inner.'[/size]' : $inner).'[/b]',
            default => $inner,
        }]];
    }

    private function hr(): string
    {
        return match ($this->mode) {
            Target::MARKDOWN => '---',
            Target::HTML => $this->t->html('hr') ? '<hr>' : '<p>⸻</p>',
            Target::BBCODE => $this->t->bb('hr') ? '[hr]' : '⸻',
            default => '⸻',
        };
    }

    /** A quote, with the attribution line in the forum's language. */
    private function quote(DOMElement $el): string
    {
        $cite = trim($el->getAttribute('data-cite'));
        $body = $this->blocks($el);

        if ($this->mode === Target::BBCODE && $this->t->bb('quote')) {
            $author = trim(str_replace(['"', ']', '['], '', $cite));

            return '[quote'.($author !== '' ? '="'.$author.'"' : '').']'."\n".$this->join($body)."\n".'[/quote]';
        }

        if ($cite !== '') {
            $name = $this->citeName($el, $cite);
            $line = Html::wrote($name);
            array_unshift($body, ['p', match ($this->mode) {
                Target::HTML => '<p><strong>'.$line.'</strong></p>',
                Target::MARKDOWN => '**'.$line.'**',
                Target::BBCODE => '[b]'.$line.'[/b]',
                default => $line,
            }]);
        }
        $inner = $this->join($body);

        return match ($this->mode) {
            Target::HTML => '<blockquote>'.$inner.'</blockquote>',
            Target::MARKDOWN => $this->prefix($inner, '> ', '>'),
            default => $this->prefix($inner, '> ', '>'),
        };
    }

    /**
     * The quoted member as written in the attribution: a post mention
     * (`@"Ada"#p45`, exactly how Flarum itself quotes a reply) when the quoted
     * post was imported and mentions are on, else the escaped name.
     */
    private function citeName(DOMElement $el, string $cite): string
    {
        $post = $el->getAttribute('data-flarum-post') ?: ($this->refs['post'][$el->getAttribute('data-post')] ?? null);
        if ($post && $this->t->mentions && $this->mode !== Target::BBCODE) {
            return '@"'.str_replace(['"', "\n"], ['', ' '], $cite).'"#p'.(int) $post;
        }
        $this->lineStart = false;

        return $this->text($cite);
    }

    private function listBlock(DOMElement $el): string
    {
        $ordered = strtolower($el->nodeName) === 'ol';
        $n = max(1, (int) ($el->getAttribute('start') ?: 1));
        $items = [];
        $loose = false;
        foreach ($el->childNodes as $li) {
            if (! $li instanceof DOMElement) {
                if (trim($li->textContent) !== '') {
                    $items[] = $this->blocks($this->wrap($li));
                }

                continue;
            }
            $sub = strtolower($li->nodeName) === 'li' ? $this->blocks($li) : $this->block($li);
            if (count(array_filter($sub, fn ($b) => $b[0] !== 'list')) > 1) {
                $loose = true;
            }
            $items[] = $sub;
        }
        if (! $items) {
            return '';
        }

        if ($this->mode === Target::HTML) {
            $html = '';
            foreach ($items as $sub) {
                $html .= '<li>'.($sub ? $this->join($sub) : '<p></p>').'</li>';
            }

            return $ordered
                ? '<ol data-type="1"'.($n > 1 ? ' start="'.$n.'"' : '').'>'.$html.'</ol>'
                : '<ul>'.$html.'</ul>';
        }

        if ($this->mode === Target::BBCODE && $this->t->bb('list')) {
            $out = $ordered ? '[list=1]' : '[list]';
            foreach ($items as $sub) {
                $out .= "\n[*]".$this->joinItem($sub);
            }

            return $out."\n[/list]";
        }

        $lines = [];
        foreach ($items as $sub) {
            $marker = match (true) {
                $this->mode === Target::MARKDOWN => $ordered ? $n.'. ' : '- ',
                default => $ordered ? $n.'. ' : '• ',
            };
            $n++;
            $body = $this->joinItem($sub);
            $indent = $this->mode === Target::MARKDOWN ? str_repeat(' ', strlen($marker)) : str_repeat("\u{00A0}", 4);
            $lines[] = $marker.$this->prefix($body, $indent, '', true);
        }

        return implode($loose && $this->mode === Target::MARKDOWN ? "\n\n" : "\n", $lines);
    }

    /** A list item's blocks: a nested list follows its text directly, as Markdown expects. */
    private function joinItem(array $sub): string
    {
        $out = '';
        $prev = null;
        foreach ($sub as [$kind, $text]) {
            if ($text === '') {
                continue;
            }
            if ($prev !== null) {
                $out .= ($kind === 'list' || $this->mode !== Target::MARKDOWN) ? "\n" : "\n\n";
            }
            $out .= $text;
            $prev = $kind;
        }

        return $out;
    }

    private function code(DOMElement $el): string
    {
        $code = rtrim(str_replace(["\r\n", "\r"], "\n", $el->textContent), "\n");
        $code = preg_replace('/^\n+/', '', $code) ?? $code;
        $lang = strtolower(preg_replace('/[^\w+#.-]/', '', $el->getAttribute('data-lang')) ?? '');

        switch ($this->mode) {
            case Target::MARKDOWN:
                preg_match_all('/`+/', $code, $m);
                $fence = str_repeat('`', max(3, ...array_map(fn ($r) => strlen($r) + 1, $m[0] ?: [''])));

                return $fence.$lang."\n".$code."\n".$fence;
            case Target::HTML:
                // Escaped, exactly as Scribe's own editor stores a code block: it
                // reloads the stored HTML to edit, and a raw `<?php` would vanish.
                return '<pre'.($lang !== '' && $this->t->html('pre') ? ' data-lang="'.$lang.'"' : '').'>'.htmlspecialchars($code, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre>';
            case Target::BBCODE:
                if ($this->t->bb('code')) {
                    return '[code'.($lang !== '' ? '='.$lang : '').']'.str_replace('[/code]', '[/ code]', $code).'[/code]';
                }
                // fall through
            default:
                return $this->escapeBackslashes($code);
        }
    }

    private function table(DOMElement $el): array
    {
        $rows = [];
        foreach ($this->descendants($el, 'tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $c) {
                if ($c instanceof DOMElement && in_array(strtolower($c->nodeName), ['td', 'th'], true)) {
                    $cells[] = $c;
                }
            }
            if ($cells) {
                $rows[] = $cells;
            }
        }
        if (! $rows) {
            return $this->blocks($el);
        }
        $isHead = fn (array $cells) => array_reduce($cells, fn ($all, $c) => $all && strtolower($c->nodeName) === 'th', true);

        if ($this->mode === Target::HTML && $this->t->html('table')) {
            $head = $isHead($rows[0]) ? array_shift($rows) : null;
            $cell = fn (DOMElement $c) => '<'.strtolower($c->nodeName)
                .(($s = (int) $c->getAttribute('colspan')) > 1 ? ' colspan="'.$s.'"' : '')
                .(($s = (int) $c->getAttribute('rowspan')) > 1 ? ' rowspan="'.$s.'"' : '')
                .'>'.($this->join($this->blocks($c)) ?: '<p></p>').'</'.strtolower($c->nodeName).'>';
            $html = '<table>';
            if ($head) {
                $html .= '<thead><tr>'.implode('', array_map($cell, $head)).'</tr></thead>';
            }
            $html .= '<tbody>';
            foreach ($rows as $r) {
                $html .= '<tr>'.implode('', array_map($cell, $r)).'</tr>';
            }

            return [['table', $html.'</tbody></table>']];
        }

        // One line per cell, with no paragraph breaks inside a cell.
        $flat = function (DOMElement $c): string {
            $this->lineStart = false;

            return $this->trimInline(preg_replace('/\s*\n+\s*/', ' ', $this->inlines(iterator_to_array($c->childNodes))) ?? '');
        };

        if ($this->mode === Target::MARKDOWN && $this->t->md('table')) {
            $head = array_shift($rows);
            $width = max(count($head), ...array_map('count', $rows ?: [[]]));
            $line = fn (array $cells) => '| '.implode(' | ', array_pad(array_map($flat, $cells), $width, '')).' |';
            $out = [$line($head), '|'.str_repeat('---|', $width)];
            foreach ($rows as $r) {
                $out[] = $line($r);
            }

            return [['table', implode("\n", $out)]];
        }

        if ($this->mode === Target::BBCODE && $this->t->bb('table')) {
            $out = '[table]';
            foreach ($rows as $r) {
                $out .= '[tr]';
                foreach ($r as $c) {
                    $t = strtolower($c->nodeName) === 'th' && $this->t->bb('table') ? 'td' : 'td';
                    $out .= '['.$t.']'.$flat($c).'[/'.$t.']';
                }
                $out .= '[/tr]';
            }

            return [['table', $out.'[/table]']];
        }

        // Degraded: one line per row, cells kept apart by a bar, the header bold.
        $bold = fn (string $s) => match ($this->mode) {
            Target::MARKDOWN => '**'.$s.'**',
            Target::BBCODE => '[b]'.$s.'[/b]',
            Target::HTML => '<strong>'.$s.'</strong>',
            default => $s,
        };
        $sep = $this->mode === Target::MARKDOWN ? ' \| ' : ' | ';
        $lines = [];
        foreach ($rows as $i => $r) {
            $cells = array_map($flat, $r);
            if ($i === 0 && $isHead($r)) {
                $cells = array_map(fn ($c) => $c === '' ? '' : $bold($c), $cells);
            }
            $lines[] = implode($sep, $cells);
        }
        if ($this->mode === Target::HTML) {
            return [['p', '<p>'.implode('<br>', $lines).'</p>']];
        }

        return [['p', implode("\n", $lines)]];
    }

    private function spoiler(DOMElement $el): string
    {
        $title = trim($el->getAttribute('data-title'));
        $body = $this->blocks($el);

        if ($this->mode === Target::HTML && $this->t->html('details')) {
            // The class is how Scribe's editor recognises its spoiler when the post is edited.
            return '<details class="Scribe-spoiler" data-title="'.$this->attr($title !== '' ? $title : Html::spoilerLabel()).'">'.($this->join($body) ?: '<p></p>').'</details>';
        }
        if ($this->mode === Target::MARKDOWN && $this->t->md('spoiler')) {
            if ($title !== '') {
                $this->lineStart = true;
                array_unshift($body, ['p', '**'.$this->text($title).'**']);
            }

            return $this->prefix($this->join($body), '>! ', '>!');
        }
        if ($this->mode === Target::BBCODE && $this->t->bb('spoiler')) {
            if ($title !== '') {
                $this->lineStart = true;
                array_unshift($body, ['p', '[b]'.$this->text($title).'[/b]']);
            }

            return '[spoiler]'.$this->join($body).'[/spoiler]';
        }

        // Degraded: the label and the text, readable, never hidden away.
        $this->lineStart = true;
        $label = Html::spoilerLabel().($title !== '' ? ': '.$title : '');
        array_unshift($body, ['p', match ($this->mode) {
            Target::MARKDOWN => '**'.$this->text($label).'**',
            Target::BBCODE => '[b]'.$this->text($label).'[/b]',
            Target::HTML => '<p><strong>'.$this->text($label).'</strong></p>',
            default => $this->text($label),
        }]);

        return $this->mode === Target::HTML
            ? '<blockquote>'.$this->join($body).'</blockquote>'
            : $this->join($body);
    }

    private function aside(DOMElement $el): string
    {
        $title = trim($el->getAttribute('data-title'));
        if ($this->mode === Target::HTML && $this->t->html('aside')) {
            return '<aside class="Scribe-info"'.($title !== '' ? ' data-title="'.$this->attr($title).'"' : '').'>'.($this->join($this->blocks($el)) ?: '<p></p>').'</aside>';
        }
        $bq = $el->ownerDocument->createElement('blockquote');
        if ($title !== '') {
            $p = $el->ownerDocument->createElement('p');
            $s = $el->ownerDocument->createElement('strong');
            $s->appendChild($el->ownerDocument->createTextNode($title));
            $p->appendChild($s);
            $bq->appendChild($p);
        }
        while ($el->firstChild) {
            $bq->appendChild($el->firstChild);
        }

        return $this->quote($bq);
    }

    /** `<figure data-media>` (an embed) or `<figure data-align>` around an image. */
    private function figure(DOMElement $el): array
    {
        $url = $el->getAttribute('data-media');
        if ($url !== '') {
            return [['p', $this->media($url)]];
        }
        $align = $el->getAttribute('data-align');
        if ($this->mode === Target::HTML && $align && $this->t->html('figure')) {
            $inner = $this->trimInline($this->inlines(iterator_to_array($el->childNodes)));

            return $inner === '' ? [] : [['p', '<figure class="Scribe-imgAlign" data-align="'.$this->attr($align).'">'.$inner.'</figure>']];
        }
        $p = $this->paragraph(iterator_to_array($el->childNodes), $align ?: null);

        return $p === null ? [] : [['p', $p]];
    }

    /** A video or other embed: Scribe's own embed where it has the provider, else the address alone on its line. */
    private function media(string $url): string
    {
        if ($this->mode === Target::HTML) {
            if ($this->t->html('video') && ($v = Html::video($url))) {
                return '<video data-provider="'.$v[0].'" data-id="'.$this->attr($v[1]).'"></video>';
            }

            return '<p><a href="'.$this->attr($url).'">'.$this->text($url).'</a></p>';
        }

        // On its own line the forum's autolinker (and any embed extension) picks it up.
        return $url;
    }

    /* ── Inline ─────────────────────────────────────────────────────────── */

    /** @param DOMNode[] $nodes */
    private function inlines(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $n) {
            $out .= $this->inline($n);
        }

        return $out;
    }

    private function inline(DOMNode $n): string
    {
        if ($n instanceof DOMText) {
            $s = preg_replace('/\s+/u', ' ', $n->data) ?? $n->data;
            if ($s === '' || ($this->lineStart && trim($s) === '')) {
                return '';
            }
            if ($this->lineStart) {
                $s = ltrim($s);
            }
            $out = $this->text($s);
            $this->lineStart = false;

            return $out;
        }
        if (! $n instanceof DOMElement) {
            return '';
        }
        if (self::isBlock($n)) {
            // A block inside inline context (a list in a table cell, say).
            $this->lineStart = true;
            $inner = $this->join($this->block($n));
            $this->lineStart = false;

            return $this->mode === Target::HTML ? $inner : "\n".$inner."\n";
        }

        $tag = strtolower($n->nodeName);
        $kids = fn () => $this->inlines(iterator_to_array($n->childNodes));

        switch ($tag) {
            case 'br':
                $this->lineStart = true;

                return $this->mode === Target::HTML ? '<br>' : "\n";
            case 'strong':
            case 'b':
                return $this->wrapInline($kids(), ['md' => ['**', '**'], 'html' => ['<strong>', '</strong>'], 'bb' => ['[b]', '[/b]']]);
            case 'em':
            case 'i':
                return $this->wrapInline($kids(), ['md' => ['*', '*'], 'html' => ['<em>', '</em>'], 'bb' => ['[i]', '[/i]']]);
            case 'u':
                return $this->wrapInline($kids(), [
                    'md' => $this->t->bb('u') ? ['[u]', '[/u]'] : ($this->t->html('u') ? ['<u>', '</u>'] : null),
                    'html' => $this->t->html('u') ? ['<u>', '</u>'] : null,
                    'bb' => $this->t->bb('u') ? ['[u]', '[/u]'] : null,
                ]);
            case 'del':
            case 's':
                return $this->wrapInline($kids(), ['md' => ['~~', '~~'], 'html' => ['<del>', '</del>'], 'bb' => $this->t->bb('s') ? ['[s]', '[/s]'] : null]);
            case 'sup':
            case 'sub':
                $md = $tag === 'sup' ? ($this->t->md('sup') ? ['^(', ')'] : null) : ($this->t->md('sub') ? ['~(', ')'] : null);

                return $this->wrapInline($kids(), ['md' => $md, 'html' => $this->t->html($tag) ? ['<'.$tag.'>', '</'.$tag.'>'] : null, 'bb' => $this->t->bb($tag) ? ['['.$tag.']', '[/'.$tag.']'] : null]);
            case 'code':
                return $this->inlineCode($n->textContent);
            case 'a':
                return $this->link($n);
            case 'img':
                return $this->image($n);
            case 'span':
            case 'mark':
                return $this->span($n, $kids);
            default:
                return $kids();
        }
    }

    /**
     * @param  array{md?:?array,html?:?array,bb?:?array}  $syntax  open/close per mode; null = not available → the bare text
     */
    private function wrapInline(string $inner, array $syntax): string
    {
        if (trim($inner) === '') {
            return $inner;
        }
        $pair = $syntax[$this->mode] ?? null;
        if (! $pair) {
            return $inner;
        }
        // Delimiters hug the text; spaces go outside (`** a **` is not bold).
        preg_match('/^(\s*)(.*?)(\s*)$/su', $inner, $m);

        return $m[1].$pair[0].$m[2].$pair[1].$m[3];
    }

    private function inlineCode(string $code): string
    {
        $code = preg_replace('/\s*\n\s*/', ' ', $code) ?? $code;
        if ($code === '') {
            return '';
        }
        $this->lineStart = false;
        switch ($this->mode) {
            case Target::MARKDOWN:
                preg_match_all('/`+/', $code, $m);
                $len = 1;
                while (in_array(str_repeat('`', $len), $m[0], true)) {
                    $len++;
                }
                $fence = str_repeat('`', $len);
                $pad = str_starts_with($code, '`') || str_ends_with($code, '`') ? ' ' : '';

                return $fence.$pad.$code.$pad.$fence;
            case Target::HTML:
                if ($this->t->html('code')) {
                    return '<code>'.htmlspecialchars($code, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code>';
                }

                return $this->text($code);
            default:
                return $this->text($code);
        }
    }

    private function link(DOMElement $a): string
    {
        $href = trim($a->getAttribute('href'));
        if ($href === '' && ($topic = $a->getAttribute('data-topic')) !== '') {
            $did = $this->refs['topic'][$topic] ?? null;
            $href = $did && $this->topicUrl ? (string) ($this->topicUrl)($did) : '';
        }
        $was = $this->inLink;
        $this->inLink = true;
        $label = $this->trimInline($this->inlines(iterator_to_array($a->childNodes)));
        $this->inLink = $was;
        if ($href === '' || ! Html::safeUrl($href)) {
            return $label;
        }
        $plain = trim(html_entity_decode(strip_tags($a->textContent), ENT_QUOTES, 'UTF-8'));
        $mail = stripos($href, 'mailto:') === 0 ? substr($href, 7) : null;
        $same = ($plain === '' && $a->getElementsByTagName('img')->length === 0) || $plain === $href || ($mail !== null && $plain === $mail)
            || rtrim($plain, '/') === rtrim(preg_replace('#^https?://(www\.)?#i', '', $href) ?? '', '/');
        $this->lineStart = false;

        switch ($this->mode) {
            case Target::MARKDOWN:
            case Target::HTML:
                // Flarum's link filter takes web addresses only: an e-mail address is
                // written out, for the forum's own e-mail autolinker.
                if ($mail !== null) {
                    return $same ? $this->text($mail) : $label.' ('.$this->text($mail).')';
                }
                if ($this->mode === Target::HTML) {
                    return '<a href="'.$this->attr($href).'">'.($label !== '' ? $label : $this->text($href)).'</a>';
                }
                if ($same && preg_match('#^https?://[^\s<>()\[\]]+$#i', $href)) {
                    return $href; // the autolinker makes it a link, and edits read naturally
                }

                return '['.($label !== '' ? $label : $this->text($plain)).']('.self::mdUrl($href).')';
            case Target::BBCODE:
                if ($mail !== null && $this->t->bb('email')) {
                    return $same ? '[email]'.$mail.'[/email]' : '[email='.$mail.']'.$label.'[/email]';
                }
                if ($same && preg_match('#^https?://\S+$#i', $href)) {
                    return $href;
                }
                if ($this->t->bb('url')) {
                    return '[url='.str_replace(['[', ']'], ['%5B', '%5D'], $href).']'.$label.'[/url]';
                }
                // fall through
            default:
                if ($same) {
                    return $this->text($mail ?? $href);
                }

                return $label.' ('.$this->text($mail ?? $href).')';
        }
    }

    private function image(DOMElement $img): string
    {
        $src = trim($img->getAttribute('src'));
        $alt = trim($img->getAttribute('alt'));
        if ($src === '' || ! Html::safeUrl($src) || stripos($src, 'mailto:') === 0) {
            return $alt !== '' ? $this->text($alt) : '';
        }
        $this->lineStart = false;

        return match ($this->mode) {
            Target::MARKDOWN => '!['.str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $alt).']('.self::mdUrl($src).')',
            Target::HTML => '<img src="'.$this->attr($src).'"'.($alt !== '' ? ' alt="'.$this->attr($alt).'"' : '').'>',
            Target::BBCODE => $this->t->bb('img') ? '[img]'.$src.'[/img]' : $src,
            default => $src,
        };
    }

    /** span: colour, size, inline spoiler, a mention, an attachment; or just its text. */
    private function span(DOMElement $el, \Closure $kids): string
    {
        if ($el->hasAttribute('data-mention')) {
            return $this->mention($el);
        }
        if ($el->hasAttribute('data-raw')) {
            // Another extension's markup, read back from a stored post: as written.
            $this->lineStart = false;

            return $el->textContent;
        }
        if (($tag = $el->getAttribute('data-tag')) !== '') {
            $this->lineStart = false;

            return $this->t->mentions ? '#'.$tag : $this->text('#'.$tag);
        }
        if ($el->hasAttribute('data-spoiler')) {
            $inner = $kids();
            if ($this->mode === Target::MARKDOWN && $this->t->md('ispoiler') && trim($inner) !== '' && ! str_contains($inner, '!<')) {
                return '>!'.$inner.'!<';
            }

            return $inner;
        }
        $inner = $kids();
        if (trim($inner) === '') {
            return $inner;
        }
        $color = Html::color($el->getAttribute('data-color'));
        $size = (int) $el->getAttribute('data-size');
        $mark = strtolower($el->nodeName) === 'mark';

        if ($mark) {
            return match (true) {
                ($this->mode === Target::HTML || $this->mode === Target::MARKDOWN) && $this->t->html('mark') => '<mark'.($color ? ' data-color="'.$color.'"' : '').'>'.$inner.'</mark>',
                default => $inner,
            };
        }
        if ($color) {
            $inner = match (true) {
                $this->mode !== Target::HTML && $this->t->bb('color') => '[color='.$color.']'.$inner.'[/color]',
                $this->mode === Target::HTML && $this->t->html('color') => '<span data-color="'.$color.'">'.$inner.'</span>',
                $this->mode === Target::MARKDOWN && $this->t->html('color') => '<span data-color="'.$color.'">'.$inner.'</span>',
                default => $inner,
            };
        }
        if ($size > 0 && $this->mode !== Target::HTML && $this->t->bb('size')) {
            $inner = '[size='.max(8, min(36, $size)).']'.$inner.'[/size]';
        }

        return $inner;
    }

    /** `@"Name"#123` when the member was imported and mentions are on; otherwise just the name. */
    private function mention(DOMElement $el): string
    {
        $name = trim($el->getAttribute('data-mention')) ?: ltrim(trim($el->textContent), '@');
        $this->lineStart = false;
        foreach (['data-flarum-post' => '#p', 'data-flarum-group' => '#g'] as $attr => $suffix) {
            if (($v = (int) $el->getAttribute($attr)) > 0) {
                return $this->t->mentions && $name !== ''
                    ? '@"'.str_replace(['"', "\n"], ['', ' '], $name).'"'.$suffix.$v
                    : $this->text('@'.$name);
            }
        }
        $id = $el->getAttribute('data-flarum-user')
            ?: ($this->refs['user'][$el->getAttribute('data-user')] ?? ($this->refs['name'][mb_strtolower($el->getAttribute('data-user-name') ?: $name)] ?? null));
        $this->lineStart = false;
        if ($id && $this->t->mentions && $name !== '') {
            return '@"'.str_replace(['"', "\n"], ['', ' '], $name).'"#'.(int) $id;
        }

        return $this->text('@'.$name);
    }

    /* ── Escaping ───────────────────────────────────────────────────────── */

    /** Text as the member typed it, safe from every syntax the forum parses. */
    private function text(string $s): string
    {
        $mentions = $this->t->mentions;

        return match ($this->mode) {
            Target::MARKDOWN => $this->mdText($s),
            Target::HTML => $this->htmlText($s),
            default => $this->mentionEscape($this->bbEscape($this->escapeBackslashes($s)), $mentions),
        };
    }

    /**
     * Text for an HTML editor. A bare web address becomes a link here: left
     * to the autolinker, one at the end of a paragraph runs on into the
     * markup after it (`…/page</p><p>Next…`), taking the rest of the post.
     */
    private function htmlText(string $s): string
    {
        if ($this->inLink || ! $this->t->html('a')) {
            return $this->htmlPlain($s);
        }
        $out = '';
        $pos = 0;
        preg_match_all(self::URL_RE, $s, $urls, PREG_OFFSET_CAPTURE);
        foreach ($urls[0] as [$url, $at]) {
            $url = rtrim($url, '.,;:!?)');
            $out .= $this->htmlPlain(substr($s, $pos, $at - $pos));
            $out .= '<a href="'.$this->attr($url).'">'.$this->htmlPlain($url).'</a>';
            $pos = $at + strlen($url);
        }

        return $out.$this->htmlPlain(substr($s, $pos));
    }

    private function htmlPlain(string $s): string
    {
        // With BBCode on as well, a typed [b] would become bold.
        return $this->mentionEscape($this->bbEscape($this->escapeBackslashes(htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'))), $this->t->mentions);
    }

    /**
     * Core's escaper runs on every forum: a backslash before any of
     * -!#()*+.:<>@[\]^_`{|} disappears. Double it so it stays.
     */
    private function escapeBackslashes(string $s): string
    {
        return str_replace('\\', '\\\\', $s);
    }

    /** Wherever the forum reads BBCode, a typed `[` must not open a tag. */
    private function bbEscape(string $s): string
    {
        return $this->t->bb !== [] ? str_replace('[', '\\[', $s) : $s;
    }

    /** With mentions on, `@name` and `#tag` would become links to whoever has that name now. */
    private function mentionEscape(string $s, bool $on): string
    {
        return $on ? (preg_replace('/(?<![\w\\\\])([@#])(?=["“\p{L}\p{N}_-])/u', '\\\\$1', $s) ?? $s) : $s;
    }

    private const URL_RE = '~https?://[^\s<>"\'\[\]{}|\\\\^`]+~i';

    private function mdText(string $s): string
    {
        $out = '';
        $pos = 0;
        $start = $this->lineStart;
        // Bare addresses stay exactly as written: an escape inside one would
        // become part of the link the autolinker makes.
        preg_match_all(self::URL_RE, $s, $urls, PREG_OFFSET_CAPTURE);
        foreach ($urls[0] as [$url, $at]) {
            $out .= $this->mdPlain(substr($s, $pos, $at - $pos), $start && $at === 0 ? true : ($pos === 0 && $start));
            $out .= $url;
            $pos = $at + strlen($url);
            $start = false;
        }
        $out .= $this->mdPlain(substr($s, $pos), $pos === 0 && $start);

        return $out;
    }

    private function mdPlain(string $s, bool $atLineStart): string
    {
        if ($s === '') {
            return '';
        }
        $s = preg_replace_callback('/[\\\\*`~^\[\]|<]|(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])|>(?=!)|!(?=<)/u', fn ($m) => '\\'.$m[0], $s) ?? $s;
        $s = $this->mentionEscape($s, $this->t->mentions);
        if ($atLineStart) {
            // Line-start syntax: headings, quotes, list markers, rules.
            $s = preg_replace(['/^(\s*)([#>+-])/', '/^(\s*\d+)([.)])(?=\s|$)/'], ['$1\\\\$2', '$1\\\\$2'], $s) ?? $s;
            if (preg_match('/^\s*=+\s*$/', $s)) {
                $s = "\u{200B}".$s;
            }
        }

        return $s;
    }

    private static function mdUrl(string $url): string
    {
        return str_replace([' ', '(', ')', '<', '>'], ['%20', '%28', '%29', '%3C', '%3E'], $url);
    }

    private function attr(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    private function trimInline(string $s): string
    {
        return trim($s, " \t\n\r\0\x0B");
    }

    /** Prefix every line (`> ` for a quote), with a bare marker on empty lines. */
    private function prefix(string $text, string $p, string $empty, bool $skipFirst = false): string
    {
        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            if ($skipFirst && $i === 0) {
                continue;
            }
            $lines[$i] = $line === '' ? $empty : $p.$line;
        }

        return implode("\n", $lines);
    }

    private function wrap(DOMNode $n): DOMElement
    {
        $div = $n->ownerDocument->createElement('div');
        $div->appendChild($n->cloneNode(true));

        return $div;
    }

    /** @return DOMElement[] */
    private function descendants(DOMElement $el, string $tag): array
    {
        $out = [];
        foreach ($el->getElementsByTagName($tag) as $n) {
            // Rows of THIS table only, not of a table nested in one of its cells.
            $p = $n->parentNode;
            while ($p && $p !== $el && strtolower($p->nodeName) !== 'table') {
                $p = $p->parentNode;
            }
            if ($p === $el) {
                $out[] = $n;
            }
        }

        return $out;
    }
}
