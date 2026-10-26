<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use ErnestDefoe\Importer\Importers\Bbcode;
use ErnestDefoe\Importer\Importers\Ctx;
use ErnestDefoe\Importer\Importers\Dst;
use ErnestDefoe\Importer\Importers\Src;
use Flarum\Formatter\Formatter;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * What a post points at on the old board — a member, a quoted post, a topic —
 * points at the same thing here once it is imported, with flarum/mentions:
 * `@"Ada"#3`, a quote opening `@"Ada"#p70`, a link to /d/7. Looked up once per
 * batch, never once per post.
 */
class MentionsAndLinksTest extends TestCase
{
    public static function editors(): array
    {
        return ['Markdown' => [['flarum-markdown']], 'Scribe' => [['ernestdefoe-scribe']], 'neither' => [[]]];
    }

    #[Test]
    #[DataProvider('editors')]
    public function mentions_quotes_and_topic_links_point_at_what_was_imported(array $editor)
    {
        $this->extension('ernestdefoe-importer', 'flarum-mentions', ...$editor);
        $this->prepareDatabase([
            'users' => [
                ['id' => 3, 'username' => 'ada', 'email' => 'ada@forum.example', 'is_email_confirmed' => 1, 'password' => ''],
                ['id' => 4, 'username' => 'bob', 'email' => 'bob@forum.example', 'is_email_confirmed' => 1, 'password' => ''],
            ],
            'discussions' => [['id' => 7, 'title' => 'Imported', 'slug' => 'imported', 'created_at' => '2020-01-01 00:00:00', 'user_id' => 3, 'first_post_id' => 70, 'comment_count' => 1]],
            'posts' => [['id' => 70, 'discussion_id' => 7, 'number' => 1, 'created_at' => '2020-01-01 00:00:00', 'user_id' => 3, 'type' => 'comment', 'content' => '<t>Hi</t>']],
            'importer_map' => [
                ['run_id' => 1, 'kind' => 'user', 'source_id' => '10', 'target_id' => 3],
                ['run_id' => 1, 'kind' => 'post', 'source_id' => '12', 'target_id' => 70],
                ['run_id' => 1, 'kind' => 'topic', 'source_id' => '5', 'target_id' => 7],
            ],
        ]);
        $this->app();
        Dst::reset();

        $bodies = [];
        for ($i = 0; $i < 20; $i++) {
            $bodies[] = Bbcode::toHtml("[QUOTE=\"Ada, post: 12, member: 10\"]quoted[/QUOTE]\n[USER=10]@Ada[/USER] and [USER=99]@Gone[/USER], see [thread=5]the old topic[/thread] and [thread=6]a missing one[/thread]. Mail me@example.com #general");
        }
        $bodies[] = Src::markdown('Thanks @bob and @nobody', ['mentions' => true]);

        $db = $this->database();
        $db->enableQueryLog();
        Dst::prepare(new Ctx(1, []), $bodies);
        $this->assertLessThanOrEqual(2, count($db->getQueryLog()), 'One lookup for the whole batch: '.json_encode($db->getQueryLog()));

        $xml = Dst::content($bodies[0]);
        $this->assertStringContainsString('<USERMENTION displayname="ada" id="3">', $xml);
        $this->assertStringContainsString('<POSTMENTION', $xml);
        $this->assertStringContainsString('id="70"', $xml);
        $this->assertStringContainsString('/d/7', $xml, 'A link to an imported topic goes to its discussion here');
        $render = fn (string $x) => html_entity_decode(strip_tags($this->app()->getContainer()->make(Formatter::class)->render($x, null)));
        $this->assertStringContainsString('@Gone', $render($xml), 'A member who was not imported stays a name');
        $this->assertStringNotContainsString('USERMENTION displayname="Gone"', $xml);
        $this->assertStringContainsString('a missing one', $xml, 'A link to a topic that was not imported keeps its words');
        $this->assertStringNotContainsString('TAGMENTION', $xml, '#general typed as text is not a tag mention');

        // Rendered through the API, as a reader sees it: a mention, not "@[deleted]".
        $id = Dst::postContent(7, 2, 4, $xml, \Illuminate\Support\Carbon::now());
        $this->assertSame(1, $db->table('post_mentions_user')->where('post_id', $id)->where('mentions_user_id', 3)->count());
        $this->assertSame(1, $db->table('post_mentions_post')->where('post_id', $id)->where('mentions_post_id', 70)->count());
        $html = json_decode((string) $this->send($this->request('GET', '/api/posts/'.$id))->getBody(), true)['data']['attributes']['contentHtml'] ?? '';
        $this->assertStringNotContainsString('[deleted]', $html);
        $this->assertMatchesRegularExpression('#class="UserMention[^"]*"[^>]*>@ada<#', $html);
        $this->assertStringContainsString('PostMention', $html);

        // A post quoting one imported earlier in the same batch.
        Dst::ref('post', '13', 99);
        $db->table('posts')->insert(['id' => 99, 'discussion_id' => 7, 'number' => 9, 'created_at' => '2020-01-01 00:00:00', 'user_id' => 3, 'type' => 'comment', 'content' => '<t>Earlier</t>']);
        $this->assertStringContainsString('id="99"', Dst::content(Bbcode::toHtml('[QUOTE="Ada, post: 13, member: 10"]x[/QUOTE]')));

        $md = Dst::content(end($bodies));
        $this->assertStringContainsString('<USERMENTION displayname="bob" id="4">', $md, 'A NodeBB/Vanilla @name is matched by username');
        $this->assertStringContainsString('@nobody', $render($md));
        $this->assertStringNotContainsString('USERMENTION displayname="nobody"', $md);
    }
}
