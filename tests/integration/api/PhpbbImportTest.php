<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Laminas\Diactoros\Uri;
use PDO;
use PHPUnit\Framework\Attributes\Test;

/**
 * A whole import, end to end, from a small phpBB board held in a SQLite file:
 * the wizard's start and step calls, what lands in the forum, and the old
 * addresses that are redirected to it afterwards.
 */
class PhpbbImportTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-importer');

        $this->prepareDatabase(['users' => [$this->normalUser()]]);

        $this->source = tempnam(sys_get_temp_dir(), 'phpbb').'.sqlite';
        $pdo = new PDO('sqlite:'.$this->source);
        $pdo->exec('CREATE TABLE phpbb_forums (forum_id INTEGER PRIMARY KEY, forum_name TEXT, forum_desc TEXT, forum_type INTEGER, left_id INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_users (user_id INTEGER PRIMARY KEY, username TEXT, user_email TEXT, user_password TEXT, user_regdate INTEGER, user_type INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_topics (topic_id INTEGER PRIMARY KEY, forum_id INTEGER, topic_title TEXT, topic_poster INTEGER, topic_time INTEGER, topic_type INTEGER, topic_visibility INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_posts (post_id INTEGER PRIMARY KEY, topic_id INTEGER, poster_id INTEGER, post_text TEXT, bbcode_uid TEXT, post_time INTEGER, post_visibility INTEGER)');
        $pdo->exec("INSERT INTO phpbb_forums VALUES (2, 'General', 'Talk', 1, 1), (3, 'A category', '', 0, 2)");
        $pdo->exec("INSERT INTO phpbb_users VALUES (1, 'Anonymous', '', '', 0, 2), (2, 'Googlebot', 'bot@board.example', '', 0, 2), (5, 'Ada', 'ada@board.example', '', 1600000000, 0), (6, 'Bob', 'bob@board.example', '', 1600000100, 0)");
        $pdo->exec("INSERT INTO phpbb_topics VALUES (10, 2, 'First topic', 5, 1600001000, 0, 1), (11, 2, 'Removed topic', 6, 1600002000, 0, 2)");
        $pdo->exec("INSERT INTO phpbb_posts VALUES (100, 10, 5, 'Hello [b:abc]world[/b:abc]', 'abc', 1600001000, 1), (101, 10, 6, 'A reply', '', 1600001100, 1), (102, 10, 6, 'Held for approval', '', 1600001200, 0), (103, 11, 6, 'In the removed topic', '', 1600002000, 1)");
    }

    protected function tearDown(): void
    {
        @unlink($this->source);

        parent::tearDown();
    }

    private function import(): array
    {
        $response = $this->send($this->request('POST', '/api/importer/start', [
            'authenticatedAs' => 1,
            'json' => ['source' => 'phpbb', 'config' => ['driver' => 'sqlite', 'database' => $this->source, 'prefix' => 'phpbb_']],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $run = json_decode((string) $response->getBody(), true);

        for ($i = 0; $i < 50 && ! $run['done'] && ! $run['failed']; $i++) {
            $response = $this->send($this->request('POST', '/api/importer/step', ['authenticatedAs' => 1, 'json' => ['runId' => $run['runId']]]));
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $run = json_decode((string) $response->getBody(), true);
        }

        return $run;
    }

    #[Test]
    public function a_board_is_imported_without_its_hidden_content()
    {
        $tagsBefore = $this->database()->table('tags')->max('id') ?? 0;

        $run = $this->import();

        $this->assertTrue($run['done'], json_encode($run));
        $this->assertFalse($run['failed']);

        $db = $this->database();
        $this->assertEqualsCanonicalizing(['ada@board.example', 'bob@board.example'], $db->table('users')->where('id', '>', 2)->pluck('email')->all(), 'Not the anonymous guest or the bots');
        $this->assertSame(['General'], $db->table('tags')->where('id', '>', $tagsBefore)->pluck('name')->all(), 'A category is not a forum');
        $this->assertSame(['First topic'], $db->table('discussions')->pluck('title')->all(), 'Not the removed topic');

        $discussion = $db->table('discussions')->first();
        $posts = $db->table('posts')->where('discussion_id', $discussion->id)->orderBy('number')->pluck('content')->all();
        $this->assertCount(2, $posts, 'Not the post held for approval');
        $this->assertStringContainsString('world', $posts[0]);
        $this->assertStringNotContainsString(':abc', $posts[0], 'The bbcode uid is not part of the text');

        // The counts nothing fires events for during an import.
        $tag = $db->table('tags')->where('id', '>', $tagsBefore)->first();
        $this->assertSame(1, (int) $tag->discussion_count);
        $this->assertSame((int) $discussion->id, (int) $tag->last_posted_discussion_id);
        $ada = $db->table('users')->where('email', 'ada@board.example')->first();
        $this->assertSame([1, 1], [(int) $ada->discussion_count, (int) $ada->comment_count]);
    }

    #[Test]
    public function the_old_topic_address_is_redirected_to_its_discussion()
    {
        $run = $this->import();
        $old = fn () => $this->send($this->request('GET', '/viewtopic.php')->withUri(new Uri('http://localhost/viewtopic.php?f=2&t=10'))->withQueryParams(['f' => '2', 't' => '10']));
        $this->assertSame(404, $old()->getStatusCode(), 'Off until the wizard is finished');

        // What the wizard's last step saves.
        $settings = $this->app()->getContainer()->make(SettingsRepositoryInterface::class);
        $settings->set('ernestdefoe-importer.redirect_run', (string) $run['runId']);
        $settings->set('ernestdefoe-importer.redirect_source', 'phpbb');

        $id = $this->database()->table('discussions')->value('id');
        $response = $old();

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame("http://localhost/d/$id", $response->getHeaderLine('Location'));
    }

    #[Test]
    public function an_address_with_no_imported_target_stays_a_404()
    {
        $run = $this->import();
        $settings = $this->app()->getContainer()->make(SettingsRepositoryInterface::class);
        $settings->set('ernestdefoe-importer.redirect_run', (string) $run['runId']);
        $settings->set('ernestdefoe-importer.redirect_source', 'phpbb');

        // Topic 11 was removed on the old board, so it was never imported.
        $response = $this->send($this->request('GET', '/viewtopic.php')->withUri(new Uri('http://localhost/viewtopic.php?t=11'))->withQueryParams(['t' => '11']));

        $this->assertSame(404, $response->getStatusCode());
    }
}
