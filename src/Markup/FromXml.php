<?php

namespace ErnestDefoe\Importer\Markup;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * A post as Flarum stores it (s9e XML) → canonical HTML ({@see Html}).
 *
 * The stored XML names what each piece IS — STRONG, QUOTE author="Ada",
 * CODE lang="php", SCRIBESPOILER label="…" — whichever syntax produced it. So
 * a post imported for a Markdown forum can be rewritten for Scribe, or BBCode,
 * without the old board: read it back to canonical HTML and write it out with
 * {@see Writer} for the editor the forum uses now. That is `importer:reformat`.
 *
 * The markers that held the old syntax (`<s>**</s>`, `<i>&gt; </i>`) are
 * dropped. A tag this file does not know keeps its original text exactly, so
 * the forum's own formatter parses it again as it did before.
 */
final class FromXml
{
    private const MAP = [
        'STRONG' => 'strong', 'B' => 'strong', 'EM' => 'em', 'I' => 'em', 'U' => 'u',
        'DEL' => 'del', 'S' => 'del', 'SUP' => 'sup', 'SUB' => 'sub', 'C' => 'code',
        'HR' => 'hr', 'LI' => 'li', 'P' => 'p',
        'H1' => 'h1', 'H2' => 'h2', 'H3' => 'h3', 'H4' => 'h4', 'H5' => 'h5', 'H6' => 'h6',
        'TABLE' => 'table', 'THEAD' => 'thead', 'TBODY' => 'tbody', 'TR' => 'tr', 'TH' => 'th', 'TD' => 'td',
        'SCRIBEREPLY' => 'div',
    ];

    public static function toHtml(string $xml): string
    {
        $xml = trim($xml);
        if ($xml === '') {
            return '';
        }
        if ($xml[0] !== '<') {
            return '<p>'.nl2br(htmlspecialchars($xml, ENT_QUOTES, 'UTF-8'), false).'</p>';
        }
        $src = new DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $ok = $src->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $ok || ! $src->documentElement) {
            return '<p>'.htmlspecialchars(strip_tags($xml), ENT_QUOTES, 'UTF-8').'</p>';
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElement('div');
        $doc->appendChild($root);
        $self = new self($doc);
        $plain = $src->documentElement->nodeName === 't';
        foreach (iterator_to_array($src->documentElement->childNodes) as $c) {
            $self->node($c, $root, $plain);
        }
        $self->cites($root);
        $self->titles($root);

        return Html::save($root);
    }

    private function __construct(private DOMDocument $doc)
    {
    }

    private function node(DOMNode $n, DOMElement $to, bool $plain = false): void
    {
        if ($n instanceof DOMText) {
            // A plain <t> post keeps its line breaks as text; they are breaks.
            if ($plain && str_contains($n->data, "\n")) {
                foreach (explode("\n", $n->data) as $i => $line) {
                    if ($i > 0) {
                        $to->appendChild($this->doc->createElement('br'));
                    }
                    $to->appendChild($this->doc->createTextNode($line));
                }

                return;
            }
            $to->appendChild($this->doc->createTextNode($n->data));

            return;
        }
        if (! $n instanceof DOMElement) {
            return;
        }
        $name = $n->nodeName;
        $local = $n->localName ?? $name;
        if (in_array($name, ['s', 'e', 'i'], true)) {
            return; // the old syntax's markers
        }
        if ($local === 'br') {
            $to->appendChild($this->doc->createElement('br'));

            return;
        }
        if ($name === 'p') {
            $this->kids($n, $this->el('p', $to));

            return;
        }
        $a = fn (string $k) => $n->getAttribute($k);

        if (isset(self::MAP[$name])) {
            $el = $this->el(self::MAP[$name], $to);
            if (($v = $a('align')) !== '' && in_array($name, ['P', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'TH', 'TD'], true)) {
                $el->setAttribute('data-align', $v);
            }
            foreach (['colspan', 'rowspan'] as $k) {
                if ((int) $a($k) > 1) {
                    $el->setAttribute($k, $a($k));
                }
            }
            $name === 'C' ? $el->appendChild($this->doc->createTextNode($this->text($n))) : $this->kids($n, $el);

            return;
        }

        switch ($name) {
            case 'ESC':
                $this->kids($n, $to);

                return;
            case 'E':
            case 'EMOJI':
                $to->appendChild($this->doc->createTextNode($a('alt') ?: $this->text($n)));

                return;
            case 'CODE':
                $el = $this->el('pre', $to);
                if (($l = $a('lang')) !== '') {
                    $el->setAttribute('data-lang', $l);
                }
                $el->appendChild($this->doc->createTextNode(trim($this->text($n), "\n")));

                return;
            case 'QUOTE':
                $el = $this->el('blockquote', $to);
                if (($v = $a('author')) !== '') {
                    $el->setAttribute('data-cite', $v);
                }
                $this->kids($n, $el);

                return;
            case 'LIST':
                $type = strtolower($a('type'));
                $el = $this->el($type !== '' && ! in_array($type, ['disc', 'circle', 'square'], true) ? 'ol' : 'ul', $to);
                if ((int) $a('start') > 1) {
                    $el->setAttribute('start', $a('start'));
                }
                $this->kids($n, $el);

                return;
            case 'URL':
                $el = $this->el('a', $to);
                $el->setAttribute('href', $a('url'));
                $this->kids($n, $el);

                return;
            case 'EMAIL':
                $el = $this->el('a', $to);
                $el->setAttribute('href', 'mailto:'.$a('email'));
                $this->kids($n, $el);

                return;
            case 'IMG':
                $el = $this->el('img', $to);
                $el->setAttribute('src', $a('src'));
                if ($a('alt') !== '') {
                    $el->setAttribute('alt', $a('alt'));
                }

                return;
            case 'COLOR':
            case 'SPAN':
            case 'MARK':
                $el = $this->el($name === 'MARK' ? 'mark' : 'span', $to);
                if ($a('color') !== '') {
                    $el->setAttribute('data-color', $a('color'));
                }
                $this->kids($n, $el);

                return;
            case 'SIZE':
                $el = $this->el('span', $to);
                $el->setAttribute('data-size', (string) (int) $a('size'));
                $this->kids($n, $el);

                return;
            case 'CENTER':
            case 'RIGHT':
            case 'LEFT':
            case 'JUSTIFY':
                $el = $this->el('div', $to);
                $el->setAttribute('data-align', strtolower($name));
                $this->kids($n, $el);

                return;
            case 'SPOILER':
            case 'SCRIBESPOILER':
                $el = $this->el('details', $to);
                if (($v = $a('label') ?: $a('title')) !== '') {
                    $el->setAttribute('data-title', $v);
                }
                $this->kids($n, $el);

                return;
            case 'ISPOILER':
                $el = $this->el('span', $to);
                $el->setAttribute('data-spoiler', '1');
                $this->kids($n, $el);

                return;
            case 'SCRIBEINFO':
                $el = $this->el('aside', $to);
                if ($a('label') !== '') {
                    $el->setAttribute('data-title', $a('label'));
                }
                $this->kids($n, $el);

                return;
            case 'SCRIBEIMGALIGN':
                $el = $this->el('figure', $to);
                if ($a('align') !== '') {
                    $el->setAttribute('data-align', $a('align'));
                }
                $this->kids($n, $el);

                return;
            case 'SCRIBEVIDEO':
                if ($url = Html::mediaUrl($a('provider') === 'youtubeshorts' ? 'youtube' : $a('provider'), $a('id'))) {
                    $el = $this->el('figure', $to);
                    $el->setAttribute('data-media', $url);
                }

                return;
            case 'USERMENTION':
                $this->span($to, ['data-mention' => $a('displayname'), 'data-flarum-user' => $a('id')], '@'.$a('displayname'));

                return;
            case 'POSTMENTION':
                $this->span($to, ['data-mention' => $a('displayname'), 'data-flarum-post' => $a('id')], '@'.$a('displayname'));

                return;
            case 'GROUPMENTION':
                $this->span($to, ['data-mention' => $a('groupname'), 'data-flarum-group' => $a('id')], '@'.$a('groupname'));

                return;
            case 'TAGMENTION':
                $this->span($to, ['data-tag' => $a('slug') ?: $a('tagname')], '#'.($a('slug') ?: $a('tagname')));

                return;
        }

        // Anything else (an upload, an embed from another extension): its text
        // exactly as written, which the forum's formatter reads again.
        $this->span($to, ['data-raw' => '1'], $n->textContent);
    }

    private function kids(DOMNode $from, DOMElement $to): void
    {
        foreach (iterator_to_array($from->childNodes) as $c) {
            $this->node($c, $to);
        }
    }

    private function el(string $tag, DOMElement $to): DOMElement
    {
        $el = $this->doc->createElement($tag);
        $to->appendChild($el);

        return $el;
    }

    private function span(DOMElement $to, array $attrs, string $text): void
    {
        $el = $this->el('span', $to);
        foreach ($attrs as $k => $v) {
            if ((string) $v !== '') {
                $el->setAttribute($k, (string) $v);
            }
        }
        $el->appendChild($this->doc->createTextNode($text));
    }

    /** Text without the syntax markers. */
    private function text(DOMNode $n): string
    {
        $out = '';
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMText) {
                $out .= $c->data;
            } elseif ($c instanceof DOMElement && ! in_array($c->nodeName, ['s', 'e', 'i'], true)) {
                $out .= $c->localName === 'br' ? "\n" : $this->text($c);
            }
        }

        return $out;
    }

    /** A spoiler whose first line is a lone bold line (how a title is written in Markdown) gets that title back. */
    private function titles(DOMElement $root): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('details')) as $d) {
            if ($d->hasAttribute('data-title') || ! ($first = $this->firstElement($d)) || $first->nodeName !== 'p' || ! ($strong = $this->lone($first, 'strong'))) {
                continue;
            }
            if (($t = trim($strong->textContent)) !== '' && $first->nextSibling !== null) {
                $d->setAttribute('data-title', $t);
                $d->removeChild($first);
            }
        }
    }

    private function firstElement(DOMElement $el): ?DOMElement
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement) {
                return $c;
            }
            if (trim($c->textContent) !== '') {
                return null;
            }
        }

        return null;
    }

    /** The element's only child, when it is a $tag (whitespace aside). */
    private function lone(DOMElement $el, string $tag): ?DOMElement
    {
        $found = null;
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMText && trim($c->data) === '') {
                continue;
            }
            if ($found !== null || ! $c instanceof DOMElement || $c->nodeName !== $tag) {
                return null;
            }
            $found = $c;
        }

        return $found;
    }

    /**
     * A quote written as "**Ada wrote:**" (how an import writes one where the
     * editor has no quote author) gets its author back, so a forum that does
     * have one — BBCode — shows it natively.
     */
    private function cites(DOMElement $root): void
    {
        [$before, $after] = explode("\x01", Html::wrote("\x01")) + ['', ''];
        $re = '/^'.preg_quote(trim($before), '/').'\s*(.+?)\s*'.preg_quote(trim($after), '/').'$/su';
        foreach (iterator_to_array($root->getElementsByTagName('blockquote')) as $bq) {
            if ($bq->hasAttribute('data-cite')) {
                continue;
            }
            $first = $this->firstElement($bq);
            $strong = $first && $first->nodeName === 'p' ? $this->lone($first, 'strong') : null;
            if (! $strong || ! preg_match($re, trim($strong->textContent), $m)) {
                continue;
            }
            $name = ltrim($m[1], '@');
            foreach ($strong->getElementsByTagName('span') as $s) {
                if ($s->hasAttribute('data-flarum-post')) {
                    $bq->setAttribute('data-flarum-post', $s->getAttribute('data-flarum-post'));
                    $name = $s->getAttribute('data-mention') ?: $name;
                }
            }
            $bq->setAttribute('data-cite', $name);
            $bq->removeChild($first);
        }
    }
}
