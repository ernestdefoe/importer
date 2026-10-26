<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use ErnestDefoe\Importer\Importers\Dst;
use ErnestDefoe\Importer\Markup\FromXml;
use ErnestDefoe\Importer\Markup\Target;
use ErnestDefoe\Importer\Markup\Writer;
use ErnestDefoe\Importer\Tests\integration\FormattingCorpus;
use Flarum\Formatter\Formatter;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every source's post, imported into every editor setup a Flarum 2 forum
 * can have, through that forum's real formatter.
 *
 * For each pair: nothing the member wrote is lost; nothing shows as raw
 * markup; each construct renders as the editor can show it (or degrades to
 * readable text where it cannot); a text editor's edit-and-save leaves the
 * post as it was; and `importer:reformat` on an unchanged forum changes
 * nothing.
 */
class EditorFormattingTest extends TestCase
{
    public const EDITORS = [
        'Markdown + BBCode' => ['flarum-markdown', 'flarum-bbcode'],
        'Markdown' => ['flarum-markdown'],
        'BBCode' => ['flarum-bbcode'],
        'neither' => [],
        'Scribe' => ['ernestdefoe-scribe'],
        'Scribe + BBCode' => ['ernestdefoe-scribe', 'flarum-bbcode'],
    ];

    public static function cases(): array
    {
        $out = [];
        foreach (array_keys(FormattingCorpus::sources()) as $source) {
            foreach (self::EDITORS as $editor => $exts) {
                $out["$source → $editor"] = [$source, $exts];
            }
        }

        return $out;
    }

    #[Test]
    #[DataProvider('cases')]
    public function a_source_s_post_renders_in_this_editor(string $source, array $extensions)
    {
        $this->extension('ernestdefoe-importer', ...$extensions);
        $this->app();
        Dst::reset();
        $fixture = FormattingCorpus::sources()[$source];
        Dst::$base = $fixture['base'] ?? '';

        $f = $this->app()->getContainer()->make(Formatter::class);
        $t = Target::current();
        $xml = Dst::content(($fixture['read'])($fixture['raw']));
        $html = $f->render($xml, null);
        // What a reader sees: the text, without the highlighter's script.
        $text = html_entity_decode(strip_tags(preg_replace('#<script\b.*?</script>#s', '', $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $why = "\n--- stored:\n".$xml."\n--- rendered:\n".$html;

        // Nothing lost.
        foreach ($fixture['has'] as $c) {
            $word = FormattingCorpus::WORDS[$c] ?? null;
            if ($word === null) {
                continue;
            }
            $where = in_array($c, ['image', 'media'], true) ? $html : $text;
            $this->assertStringContainsString($word, $where, "$c lost$why");
        }
        if (in_array('literal', $fixture['has'], true) && str_contains($fixture['raw'], '[sic]')) {
            $this->assertStringContainsString('2*3', $text, "a literal * was read as markup$why");
            $this->assertStringContainsString('[sic]', $text, "a literal [bracket] was lost$why");
            if (str_contains($fixture['raw'], '[NOPARSE]')) {
                $this->assertStringContainsString('[b]LITERALS[/b]', $text, "[noparse] text was read as markup$why");
            }
            if (str_contains($fixture['raw'], 'not bold')) {
                $this->assertStringContainsString('<b>not bold</b>', $text, "typed angle brackets did not stay text$why");
                $this->assertStringNotContainsString('<b>not bold', $html, "typed HTML became markup$why");
            }
        }

        // Nothing shows as markup. (Scribe shows entities inside code — its own
        // code blocks do the same, so imported ones are written exactly as it
        // writes them, and edit cleanly; that is Scribe's to fix.)
        $shown = $t->mode === Target::HTML ? (preg_replace('#<(pre|code)\b.*?</\1>#s', '', $html) ?? $html) : $html;
        $visible = str_replace(['[b]LITERALS[/b]', '<b>not bold</b>', '[sic]'], '', html_entity_decode(strip_tags(preg_replace('#<script\b.*?</script>#s', '', $shown) ?? $shown), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach (['**', '[b]', '[/', '[quote', '[url', '[img', '[list', '[*]', '[code', '[spoiler', '[color', '[size', '```', '>!', '!<', '&lt;', '&amp;', '<strong', '<p>', 'data-', '~~', 'fileStore', '___base_url', '"#', '\\'] as $raw) {
            $this->assertStringNotContainsString($raw, $visible, "raw markup [$raw] shows in the post$why");
        }

        // Each construct as this editor can show it.
        $md = $t->mode === Target::MARKDOWN;
        $hm = $t->mode === Target::HTML;
        $bb = $t->mode === Target::BBCODE;
        $rich = $md || $hm || $bb;
        $expect = function (string $c, bool $when, string $re) use ($fixture, $html, $why) {
            if ($when && in_array($c, $fixture['has'], true)) {
                $this->assertMatchesRegularExpression($re, $html, "$c not rendered$why");
            }
        };
        $expect('bold', $rich, '#<(strong|b)>(<[^>]+>)*[^<]*BOLDWORD#');
        $expect('italic', $rich, '#<(em|i)>(<[^>]+>)*[^<]*ITALICWORD#');
        $expect('underline', ($hm && $t->html('u')) || (! $hm && $t->bb('u')), '#<u>[^<]*UNDERWORD#');
        $expect('strike', $md || $hm || ($bb && $t->bb('s')), '#<(del|s)>[^<]*STRIKEWORD#');
        $expect('color', ($hm && $t->html('color')) || (! $hm && $t->bb('color')), '#color:[^>]*>(<[^>]+>)*REDWORD#i');
        $expect('size', ! $hm && $t->bb('size'), '#font-size:\s*\d+px[^>]*>(<[^>]+>)*BIGWORD#');
        $expect('heading', $md || $hm, '#<h[1-6][^>]*>(<[^>]+>)*[^<]*HEADINGWORD#');
        $expect('quote', $rich, '#<blockquote[\s\S]*QUOTEDTEXT[\s\S]*</blockquote>#');
        $expect('list', $md || $hm || ($bb && $t->bb('list')), '#<ul>[\s\S]*<li>(<[^>]+>)*LISTITEM1#');
        $expect('olist', $md || $hm || ($bb && $t->bb('list')), '#<ol[^>]*>[\s\S]*NUMITEM#');
        $expect('code', $md || $hm || ($bb && $t->bb('code')), '#<pre[^>]*><code[^>]*>[\s\S]*CODELINE#');
        $expect('codelang', $md || $hm || ($bb && $t->bb('code')), '#<code class="language-php">#');
        $expect('icode', $md || $hm, '#<code>ICODEWORD</code>#');
        $expect('link', $rich, '#<a href="https://example\.com/link"[^>]*>LINKLABEL</a>#');
        $expect('link', ! $rich, '#LINKLABEL \(<a href="https://example\.com/link"#');
        $expect('image', $md || $hm || ($bb && $t->bb('img')), '#<img[^>]+src="https://[^"]*image[^"]*\.png"#');
        $expect('image', ! ($md || $hm || ($bb && $t->bb('img'))), '#href="https://[^"]*image[^"]*\.png"#');
        $expect('spoiler', $md && $t->md('spoiler'), '#<details class="spoiler">[\s\S]*SPOILERTEXT#');
        $expect('spoiler', $hm && $t->html('details'), '#<details class="Scribe-spoiler"[\s\S]*SPOILERTEXT#');
        $expect('ispoiler', $md && $t->md('ispoiler'), '#class="spoiler"[^>]*>INLINESPOILER#');
        $expect('table', $hm && $t->html('table'), '#<table>[\s\S]*TABLECELL#');
        $expect('center', ($hm && $t->html('align')) || (! $hm && $t->bb('center')), '#text-align:\s*center[^>]*>(<[^>]+>)*CENTERWORD#');
        $expect('media', $hm && $t->html('video'), '#Scribe-video[\s\S]*dQw4w9WgXcQ#');
        $expect('hr', $md || $hm, '#<hr#');
        $expect('email', true, '#mailto:ada@example\.com#');
        // A bare address ending a paragraph links to itself, and takes nothing after it along.
        $expect('bareurl', true, '#<a href="https://example\.com/bare"[^>]*>https://example\.com/bare</a>#');

        // A text editor's edit-and-save, and `importer:reformat` on this same forum, change nothing.
        $this->assertSame($xml, $f->parse($f->unparse($xml, null), null), 'Editing and saving changes the post'.$why);
        $again = $f->parse(Writer::write(FromXml::toHtml($xml), $t) ?: "\u{200B}", null);
        $this->assertSame($f->render($xml, null), $f->render($again, null), 'importer:reformat changes an unchanged post'."\n--- reformatted:\n".$again.$why);
    }
}
