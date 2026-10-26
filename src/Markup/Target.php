<?php

namespace ErnestDefoe\Importer\Markup;

use Flarum\Extension\ExtensionManager;
use Flarum\Formatter\Formatter;

/**
 * What the forum being imported INTO can store and show.
 *
 * Flarum keeps a post as the XML its own formatter produced, and which
 * syntaxes that formatter understands depends entirely on the extensions the
 * forum has enabled: flarum/markdown, flarum/bbcode, both, neither, or an HTML
 * editor such as Scribe. A post written for the wrong one shows its markup as
 * text: `**bold**` on a BBCode forum, `[b]bold[/b]` on a Markdown one.
 *
 * 🚨 So nothing here is decided by extension NAME. Each construct is proved by
 * handing the forum's real formatter a few characters and looking for the tag
 * it produced. That covers editors this file has never heard of: an extension
 * that adds `[spoiler]`, or pipe tables to Markdown, is used automatically, and
 * one that takes `<u>` away is never sent it.
 */
class Target
{
    /** Primary syntax: what a post is written in. */
    public const MARKDOWN = 'md';
    public const HTML = 'html';
    public const BBCODE = 'bb';
    public const TEXT = 'text';

    private static ?self $current = null;

    /**
     * @param  string  $mode  one of the constants above
     * @param  array<string,bool>  $md  Markdown constructs (Litedown + plugins)
     * @param  array<string,bool>  $html  HTML elements the formatter maps to tags
     * @param  array<string,bool>  $bb  BBCodes it knows
     */
    public function __construct(
        public string $mode,
        public array $md = [],
        public array $html = [],
        public array $bb = [],
        public bool $mentions = false,
    ) {
    }

    public static function current(): self
    {
        return self::$current ??= self::probe(resolve(Formatter::class));
    }

    /** For tests, and for a forum whose extensions changed mid-process. */
    public static function set(?self $target): void
    {
        self::$current = $target;
    }

    private const MD = [
        'strong' => ['**a**', 'STRONG'],
        'table' => ["| a | b |\n|---|---|\n| 1 | 2 |", 'TABLE'],
        'spoiler' => ['>! a', 'SPOILER'],
        'ispoiler' => ['>!a!<', 'ISPOILER'],
        'sup' => ['^(a)', 'SUP'],
        'sub' => ['~(a)', 'SUB'],
    ];

    private const HTML_PROBES = [
        'strong' => ['<strong>a</strong>', 'STRONG'],
        'u' => ['<u>a</u>', 'U'],
        'p' => ['<p>a</p>', 'P'],
        'align' => ['<p data-align="center">a</p>', 'P align='],
        'halign' => ['<h2 data-align="center">a</h2>', 'H2 align='],
        'h' => ['<h2>a</h2>', 'H2'],
        'hr' => ['<hr>', 'HR'],
        'del' => ['<del>a</del>', 'DEL'],
        'sup' => ['<sup>a</sup>', 'SUP'],
        'sub' => ['<sub>a</sub>', 'SUB'],
        'code' => ['<code>a</code>', 'C'],
        'pre' => ['<pre data-lang="php">a</pre>', 'CODE lang='],
        'list' => ['<ol data-type="1"><li>a</li></ol>', 'LIST'],
        'quote' => ['<blockquote>a</blockquote>', 'QUOTE'],
        'a' => ['<a href="https://example.com/">a</a>', 'URL'],
        'img' => ['<img src="https://example.com/a.png">', 'IMG'],
        'table' => ['<table><tr><td>a</td></tr></table>', 'TABLE'],
        'color' => ['<span data-color="red">a</span>', 'SPAN color='],
        'mark' => ['<mark data-color="yellow">a</mark>', 'MARK'],
        'details' => ['<details data-title="t">a</details>', 'SCRIBESPOILER'],
        'aside' => ['<aside data-title="t">a</aside>', 'SCRIBEINFO'],
        'figure' => ['<figure data-align="center"><img src="https://example.com/a.png"></figure>', 'SCRIBEIMGALIGN'],
        'video' => ['<video data-provider="youtube" data-id="dQw4w9WgXcQ"></video>', 'SCRIBEVIDEO'],
    ];

    private const BB = [
        'b' => ['[b]a[/b]', 'B'],
        'i' => ['[i]a[/i]', 'I'],
        'u' => ['[u]a[/u]', 'U'],
        's' => ['[s]a[/s]', 'S'],
        'url' => ['[url=https://example.com/]a[/url]', 'URL'],
        'img' => ['[img]https://example.com/a.png[/img]', 'IMG'],
        'email' => ['[email]a@example.com[/email]', 'EMAIL'],
        'code' => ['[code=php]a[/code]', 'CODE'],
        'quote' => ['[quote="a"]b[/quote]', 'QUOTE author='],
        'list' => ['[list=1][*]a[/list]', 'LIST'],
        'color' => ['[color=red]a[/color]', 'COLOR'],
        'size' => ['[size=20]a[/size]', 'SIZE'],
        'center' => ['[center]a[/center]', 'CENTER'],
        'right' => ['[right]a[/right]', 'RIGHT'],
        'spoiler' => ['[spoiler]a[/spoiler]', 'SPOILER'],
        'table' => ['[table][tr][td]a[/td][/tr][/table]', 'TABLE'],
        'hr' => ['[hr]', 'HR'],
        'sup' => ['[sup]a[/sup]', 'SUP'],
        'sub' => ['[sub]a[/sub]', 'SUB'],
    ];

    /**
     * 🚨 No probe may contain `#` or `@`: with flarum/mentions on, either one
     * sends the parser to the database to look the "mention" up.
     */
    public static function probe(Formatter $formatter): self
    {
        // `TAG` matches the tag itself; `TAG attr=` also needs the attribute,
        // proving the formatter kept it rather than dropping it as unknown.
        $has = function (string $text, string $tag) use ($formatter): bool {
            try {
                $xml = $formatter->parse($text, null);
            } catch (\Throwable) {
                return false;
            }

            return str_contains($tag, ' ')
                ? str_contains($xml, '<'.$tag)
                : (bool) preg_match('/<'.preg_quote($tag, '/').'[\s>\/]/', $xml);
        };
        $run = function (array $probes) use ($has): array {
            $out = [];
            foreach ($probes as $key => [$text, $tag]) {
                $out[$key] = $has($text, $tag);
            }

            return $out;
        };

        $md = $has('**a**', 'STRONG') && $has('*a*', 'EM') ? $run(self::MD) : [];
        $html = $has('<strong>a</strong>', 'STRONG') ? $run(self::HTML_PROBES) : [];
        $bb = $run(self::BB);
        $mode = match (true) {
            $md !== [] => self::MARKDOWN,
            $html !== [] => self::HTML,
            $bb['b'] => self::BBCODE,
            default => self::TEXT,
        };

        $mentions = false;
        try {
            $mentions = resolve(ExtensionManager::class)->isEnabled('flarum-mentions');
        } catch (\Throwable) {
        }

        return new self($mode, array_filter($md), array_filter($html), array_filter($bb), $mentions);
    }

    /** For tests: a target described without a formatter. */
    public static function of(string $mode, array $md = [], array $html = [], array $bb = [], bool $mentions = false): self
    {
        $flags = fn (array $keys) => array_fill_keys($keys, true);

        return new self($mode, $flags($md), $flags($html), $flags($bb), $mentions);
    }

    public function md(string $k): bool
    {
        return ! empty($this->md[$k]);
    }

    public function html(string $k): bool
    {
        return ! empty($this->html[$k]);
    }

    public function bb(string $k): bool
    {
        return ! empty($this->bb[$k]);
    }

    /** A short description for the CLI and the run summary, e.g. "Markdown + BBCode". */
    public function label(): string
    {
        $parts = [];
        if ($this->md !== []) {
            $parts[] = 'Markdown';
        }
        if ($this->bb('b')) {
            $parts[] = 'BBCode';
        }
        if ($this->html !== []) {
            $parts[] = 'HTML';
        }

        return $parts ? implode(' + ', $parts) : 'plain text';
    }
}
