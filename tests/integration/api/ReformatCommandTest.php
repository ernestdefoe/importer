<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use ErnestDefoe\Importer\Tests\integration\MarkdownStoredPost;
use Flarum\Formatter\Formatter;
use Flarum\Testing\integration\ConsoleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A forum imported while it used Markdown, which has since switched editor:
 * `importer:reformat` rewrites the imported posts for the editor it uses now,
 * without the old board.
 */
class ReformatCommandTest extends ConsoleTestCase
{
    public static function editors(): array
    {
        return [
            'Scribe' => [['ernestdefoe-scribe'], ['<SCRIBESPOILER label="Plot">', '<CODE lang="php">', '<LIST', '<DEL>', '<H2', '<HR', '<P>']],
            'BBCode' => [['flarum-bbcode'], ['<QUOTE author="Ada">', '<CODE lang="php">', '<LIST', '<B>', '<I>']],
        ];
    }

    #[Test]
    #[DataProvider('editors')]
    public function imported_posts_are_rewritten_for_the_editor_in_use_now(array $editor, array $expect)
    {
        $this->extension('ernestdefoe-importer', ...$editor);
        $this->prepareDatabase([
            'users' => [['id' => 3, 'username' => 'ada', 'email' => 'ada@forum.example', 'is_email_confirmed' => 1, 'password' => '']],
            'discussions' => [['id' => 7, 'title' => 'Imported', 'slug' => 'imported', 'created_at' => '2020-01-01 00:00:00', 'user_id' => 3, 'first_post_id' => 70, 'comment_count' => 1],
                ['id' => 8, 'title' => 'Written here', 'slug' => 'written-here', 'created_at' => '2020-01-02 00:00:00', 'user_id' => 3, 'first_post_id' => 80, 'comment_count' => 1]],
            'posts' => [['id' => 70, 'discussion_id' => 7, 'number' => 1, 'created_at' => '2020-01-01 00:00:00', 'user_id' => 3, 'type' => 'comment', 'content' => MarkdownStoredPost::XML],
                ['id' => 80, 'discussion_id' => 8, 'number' => 1, 'created_at' => '2020-01-02 00:00:00', 'user_id' => 3, 'type' => 'comment', 'content' => MarkdownStoredPost::XML]],
            'importer_runs' => [['id' => 1, 'source' => 'xenforo', 'config' => '{}', 'state' => '{}', 'status' => 'done']],
            'importer_map' => [['run_id' => 1, 'kind' => 'topic', 'source_id' => '5', 'target_id' => 7], ['run_id' => 1, 'kind' => 'post', 'source_id' => '50', 'target_id' => 70, 'hash' => sha1(MarkdownStoredPost::XML)]],
        ]);
        $db = $this->database();

        $out = $this->runCommand(['command' => 'importer:reformat', '--dry-run' => true]);
        $this->assertStringContainsString('Would rewrite 1 of 1', $out);
        $this->assertSame(MarkdownStoredPost::XML, $db->table('posts')->where('id', 70)->value('content'), 'A dry run saves nothing');

        $out = $this->runCommand(['command' => 'importer:reformat']);
        $this->assertStringContainsString('Rewrote 1 of 1', $out);
        $new = (string) $db->table('posts')->where('id', 70)->value('content');
        foreach ($expect as $tag) {
            $this->assertStringContainsString($tag, $new);
        }
        foreach (['<s>**</s>', '<s>```php</s>', '<i>&gt; </i>'] as $markdown) {
            $this->assertStringNotContainsString($markdown, $new, 'Markdown syntax left in the post');
        }
        $html = $this->app()->getContainer()->make(Formatter::class)->render($new, null);
        foreach (['BOLDWORD', 'QUOTEDTEXT', 'LISTITEM1', 'NUMITEM', 'CODELINE', 'LINKLABEL', 'SPOILERTEXT', 'INLINESPOILER', 'TABLECELL', 'LITERALS', '2*3', '[sic]'] as $word) {
            $this->assertStringContainsString($word, html_entity_decode(strip_tags($html)), "$word lost");
        }

        $this->assertSame(MarkdownStoredPost::XML, $db->table('posts')->where('id', 80)->value('content'), 'A post written on this forum is left alone');
        $this->assertSame(sha1($new), $db->table('importer_map')->where('target_id', 70)->value('hash'), 'A later sync still knows nobody edited it here');

        $this->assertStringContainsString('Rewrote 0 of 1', $this->runCommand(['command' => 'importer:reformat']), 'Running it again changes nothing');
    }

    /** Before 0.6.0 an import into a forum without Markdown stored Markdown as text. */
    #[Test]
    public function markdown_left_as_text_by_an_older_import_is_read_as_markdown()
    {
        $this->extension('ernestdefoe-importer', 'ernestdefoe-scribe');
        $old = "<r><ESC><s>\\</s>*</ESC>not this<ESC><s>\\</s>*</ESC> but **Bold** and *it*<br/>\n<br/>\n&gt; **Ada wrote:**<br/>\n&gt;<br/>\n&gt; quoted<br/>\n<br/>\n- one<br/>\n- two<br/>\n<br/>\n[a link](<URL url=\"https://example.com/\">https://example.com/</URL>)</r>";
        $this->prepareDatabase([
            'users' => [['id' => 3, 'username' => 'ada', 'email' => 'ada@forum.example', 'is_email_confirmed' => 1, 'password' => '']],
            'discussions' => [['id' => 7, 'title' => 'Imported', 'slug' => 'imported', 'created_at' => '2020-01-01 00:00:00', 'user_id' => 3, 'first_post_id' => 70, 'comment_count' => 1]],
            'posts' => [['id' => 70, 'discussion_id' => 7, 'number' => 1, 'created_at' => '2020-01-01 00:00:00', 'user_id' => 3, 'type' => 'comment', 'content' => $old]],
            'importer_runs' => [['id' => 1, 'source' => 'phpbb', 'config' => '{}', 'state' => '{}', 'status' => 'done']],
            'importer_map' => [['run_id' => 1, 'kind' => 'topic', 'source_id' => '5', 'target_id' => 7]],
        ]);

        $this->assertStringContainsString('Rewrote 1 of 1', $this->runCommand(['command' => 'importer:reformat', '--markdown-text' => true]));
        $html = $this->app()->getContainer()->make(Formatter::class)->render((string) $this->database()->table('posts')->where('id', 70)->value('content'), null);

        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringContainsString('<em>it</em>', $html);
        $this->assertMatchesRegularExpression('#<blockquote>.*Ada wrote:.*quoted.*</blockquote>#s', $html);
        $this->assertMatchesRegularExpression('#<ul><li>(<p>)?one#', $html);
        $this->assertStringContainsString('<a href="https://example.com/"', $html);
        $this->assertStringContainsString('*not this*', html_entity_decode(strip_tags($html)), 'An escaped * stays a *');
        $this->assertStringContainsString('Rewrote 0 of 1', $this->runCommand(['command' => 'importer:reformat', '--markdown-text' => true]), 'Running it again changes nothing');
    }
}
