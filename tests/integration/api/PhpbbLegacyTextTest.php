<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use Flarum\Formatter\Formatter;
use Flarum\Testing\integration\ConsoleTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;

/**
 * phpBB 3.0 post_text through the real import and Flarum's own formatter (with
 * flarum/markdown, as on a stock forum), and the repair command for posts an
 * earlier version imported as mangled text.
 */
class PhpbbLegacyTextTest extends ConsoleTestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-markdown', 'ernestdefoe-importer');

        $this->source = tempnam(sys_get_temp_dir(), 'phpbb').'.sqlite';
        $pdo = new PDO('sqlite:'.$this->source);
        $pdo->exec('CREATE TABLE phpbb_forums (forum_id INTEGER PRIMARY KEY, forum_name TEXT, forum_desc TEXT, forum_type INTEGER, left_id INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_users (user_id INTEGER PRIMARY KEY, username TEXT, user_email TEXT, user_password TEXT, user_regdate INTEGER, user_type INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_topics (topic_id INTEGER PRIMARY KEY, forum_id INTEGER, topic_title TEXT, topic_poster INTEGER, topic_time INTEGER, topic_type INTEGER, topic_visibility INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_posts (post_id INTEGER PRIMARY KEY, topic_id INTEGER, poster_id INTEGER, post_text TEXT, bbcode_uid TEXT, post_time INTEGER, post_visibility INTEGER)');
        $pdo->exec("INSERT INTO phpbb_forums VALUES (2, 'General', '', 1, 1)");
        $pdo->exec("INSERT INTO phpbb_users VALUES (5, 'Ada', 'ada@board.example', '', 1600000000, 0)");
        $pdo->exec("INSERT INTO phpbb_topics VALUES (10, 2, 'Links', 5, 1600001000, 0, 1)");
        $insert = $pdo->prepare('INSERT INTO phpbb_posts VALUES (?, 10, 5, ?, ?, ?, 1)');
        $insert->execute([100, 'See <!-- m --><a class="postlink" href="https://www.ea.com/games/battlefield/battlefield-6?a=1&amp;b=2">https://www.ea.com/games/battlefield/battlefield-6?a=1&amp;b=2</a><!-- m -->. Tom &amp; Jerry &lt;script&gt;alert(1)&lt;/script&gt; <!-- s:) --><img src="{SMILIES_PATH}/icon_e_smile.gif" alt=":)" title="Smile" /><!-- s:) -->'."\n[list:abc12345][*:abc12345]one[/*:m:abc12345][*:abc12345]two[/*:m:abc12345][/list:u:abc12345]", 'abc12345', 1600001000]);
        $insert->execute([101, '<r><B><s>[b]</s>New<e>[/b]</e></B> format &amp; <URL url="https://example.com/x_y">https://example.com/x_y</URL></r>', 'zzz', 1600001100]);
    }

    protected function tearDown(): void
    {
        @unlink($this->source);

        parent::tearDown();
    }

    private function rendered(): array
    {
        $f = $this->app()->getContainer()->make(Formatter::class);

        return $this->database()->table('posts')->orderBy('number')->pluck('content')->map(fn ($c) => $f->render($c, null))->all();
    }

    #[Test]
    public function legacy_and_s9e_posts_import_as_real_links_and_text()
    {
        $this->runCommand(['command' => 'importer:run', '--source' => 'phpbb', '--driver' => 'sqlite', '--database' => $this->source, '--prefix' => 'phpbb_']);

        [$legacy, $s9e] = $this->rendered();
        $this->assertStringContainsString('See <a href="https://www.ea.com/games/battlefield/battlefield-6?a=1&amp;b=2"', $legacy);
        $this->assertStringContainsString('battlefield-6?a=1&amp;b=2</a>.', $legacy);
        $this->assertStringContainsString('Tom &amp; Jerry &lt;script&gt;alert(1)&lt;/script&gt;', $legacy, '& and < read as themselves');
        $this->assertStringNotContainsString('&amp;amp;', $legacy);
        $this->assertStringNotContainsString('&lt;!--', $legacy);
        $this->assertStringNotContainsString('<script', $legacy);
        $this->assertStringContainsString('<li>one</li>', $legacy);
        $this->assertStringNotContainsString(':m]', $legacy);

        $this->assertStringContainsString('<strong>New</strong> format &amp; <a href="https://example.com/x_y"', $s9e);
        $this->assertStringNotContainsString('&lt;B&gt;', $s9e);
    }

    #[Test]
    public function hostile_imported_content_never_becomes_markup()
    {
        $this->app();
        $f = $this->app()->getContainer()->make(Formatter::class);
        $hostile = [
            \ErnestDefoe\Importer\Importers\Bbcode::phpbb('<!-- m --><a class="postlink" href="javascript:alert(1)">javascript:alert(1)</a><!-- m --> [url=&#1;javascript&#58;alert(2):u1]x[/url:u1] &lt;img src=x onerror=alert(3)&gt;', 'u1'),
            \ErnestDefoe\Importer\Importers\Src::sanitizeHtml('<a href="javascript:alert(4)">y</a><img src=x onerror=alert(5)><script>alert(6)</script><svg onload=alert(7)>'),
        ];
        foreach ($hostile as $html) {
            $out = $f->render(\ErnestDefoe\Importer\Importers\Dst::content($html), null);
            $this->assertDoesNotMatchRegularExpression('#<(script|svg|iframe)\b|<[^>]*\son\w+=|href="\s*javascript#i', $out, $out);
        }
        // And the repair path, fed a member's hand-typed lookalike.
        $md = \ErnestDefoe\Importer\Importers\PostText::fixEntities(\ErnestDefoe\Importer\Importers\PostText::repairPhpbb('&lt;a class="postlink" href="javascript:alert(8)"&gt;go&lt;/a&gt; &lt;script&gt;x&lt;/script&gt;'));
        $out = $f->render($f->parse($md, null), null);
        $this->assertDoesNotMatchRegularExpression('#<script|href="\s*javascript#i', $out, $out);
    }

    #[Test]
    public function the_repair_command_fixes_posts_an_earlier_version_imported()
    {
        $this->runCommand(['command' => 'importer:run', '--source' => 'phpbb', '--driver' => 'sqlite', '--database' => $this->source, '--prefix' => 'phpbb_']);
        $db = $this->database();
        $f = $this->app()->getContainer()->make(Formatter::class);

        // What 0.3.6 stored for post 100: the markup as escaped text.
        $old = 'See &lt;!-- m --&gt;&lt;a class="postlink" href="https://www.ea.com/games/battlefield/battlefield-6?a=1&amp;b=2"&gt;https://www.ea.com/games/battlefield/battlefield-6?a=1&amp;b=2&lt;/a&gt;&lt;!-- m --&gt;. Tom &amp; Jerry &amp;lt;b&amp;gt; &lt;!-- s:) --&gt;&lt;img src="{SMILIES\_PATH}/icon\_e\_smile.gif" alt=":)" title="Smile" /&gt;&lt;!-- s:) --&gt;'."\n".'\[\*\]one\[/\*:m\]\[\*\]two\[/\*:m\]\[/list:u\]';
        $first = $db->table('posts')->orderBy('number')->value('id');
        $db->table('posts')->where('id', $first)->update(['content' => $f->parse($old, null)]);
        // As if the run predated the fix.
        $db->table('importer_runs')->update(['state' => '{}']);
        // A reply written on the forum after the import is never touched.
        $did = $db->table('posts')->where('id', $first)->value('discussion_id');
        $db->table('posts')->insert(['discussion_id' => $did, 'number' => 9, 'created_at' => '2099-01-01 00:00:00', 'user_id' => 1, 'type' => 'comment', 'content' => $f->parse('I typed &amp; on purpose', null), 'is_private' => 0]);
        $reply = $db->table('posts')->where('number', 9)->value('content');

        $dry = $this->runCommand(['command' => 'importer:repair-text', '--dry-run' => true]);
        $this->assertStringContainsString('Would repair 1 of 3', $dry);
        $this->assertSame($f->parse($old, null), $db->table('posts')->where('id', $first)->value('content'), 'Dry run saves nothing');

        $out = $this->runCommand(['command' => 'importer:repair-text']);
        $this->assertStringContainsString('Repaired 1 of 3', $out);

        $html = $f->render($db->table('posts')->where('id', $first)->value('content'), null);
        $this->assertStringContainsString('See <a href="https://www.ea.com/games/battlefield/battlefield-6?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('battlefield-6?a=1&amp;b=2</a>.', $html);
        $this->assertStringContainsString('Tom &amp; Jerry &amp;lt;b&amp;gt;', $html, 'A literal &lt; is decoded exactly once');
        $this->assertStringNotContainsString('&lt;!--', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertSame($reply, $db->table('posts')->where('number', 9)->value('content'));

        $again = $this->runCommand(['command' => 'importer:repair-text']);
        $this->assertStringContainsString('Repaired 0 of 3', $again, 'Idempotent');
    }
}
