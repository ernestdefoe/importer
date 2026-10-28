<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every source shares: a step lands whole or not at all, a failed run
 * picks up where it stopped, members get usernames Flarum accepts, and the
 * forum is tidy afterwards (no empty discussions, nothing "unread").
 */
class ImportRobustnessTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDatabase(['users' => [$this->normalUser()]]);
        $this->source = tempnam(sys_get_temp_dir(), 'robust').'.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->source);

        parent::tearDown();
    }

    private function phpbb(): void
    {
        $pdo = new PDO('sqlite:'.$this->source);
        $pdo->exec('CREATE TABLE phpbb_forums (forum_id INTEGER PRIMARY KEY, forum_name TEXT, forum_desc TEXT, forum_type INTEGER, left_id INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_users (user_id INTEGER PRIMARY KEY, username TEXT, user_email TEXT, user_password TEXT, user_regdate INTEGER, user_type INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_topics (topic_id INTEGER PRIMARY KEY, forum_id INTEGER, topic_title TEXT, topic_poster INTEGER, topic_time INTEGER, topic_type INTEGER, topic_visibility INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_posts (post_id INTEGER PRIMARY KEY, topic_id INTEGER, poster_id INTEGER, post_text TEXT, bbcode_uid TEXT, post_time INTEGER, post_visibility INTEGER)');
        $pdo->exec("INSERT INTO phpbb_forums VALUES (2, 'General', 'Talk', 1, 1)");
        $pdo->exec("INSERT INTO phpbb_users VALUES (5, 'José.p', 'jose@board.example', '', 1600000000, 0), (6, 'Al', 'al@board.example', '', 1600000100, 0), (7, 'jose_p', 'other@board.example', '', 1600000200, 0)");
        $pdo->exec("INSERT INTO phpbb_topics VALUES (10, 2, 'First topic', 5, 1600001000, 0, 1), (11, 2, 'Only unapproved posts', 6, 1600002000, 0, 1), (12, 2, 'Third', 6, 1600003000, 0, 1)");
        $pdo->exec("INSERT INTO phpbb_posts VALUES (100, 10, 5, 'Hello', '', 1600001000, 1), (101, 10, 6, 'A reply', '', 1600001100, 1), (110, 11, 6, 'Waiting', '', 1600002000, 0), (120, 12, 6, 'Third post', '', 1600003000, 1)");
    }

    private function post(string $path, array $json): array
    {
        $response = $this->send($this->request('POST', '/api/importer/'.$path, ['authenticatedAs' => 1, 'json' => $json]));
        $this->assertContains($response->getStatusCode(), [200, 422], (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function drive(array $run): array
    {
        for ($i = 0; $i < 60 && ($run['running'] ?? false); $i++) {
            $run = $this->post('step', ['runId' => $run['runId']]);
        }

        return $run;
    }

    #[Test]
    public function a_step_that_fails_half_way_writes_nothing_and_the_run_resumes()
    {
        $this->extension('flarum-tags', 'ernestdefoe-importer');
        $this->phpbb();
        $run = $this->post('start', ['source' => 'phpbb', 'config' => ['driver' => 'sqlite', 'database' => $this->source, 'prefix' => 'phpbb_']]);
        $db = $this->database();
        $schema = $db->getSchemaBuilder();

        // Through tags and members, to the topics phase.
        while ($run['running'] && $db->table('importer_map')->where('kind', 'user')->count() < 3) {
            $run = $this->post('step', ['runId' => $run['runId']]);
        }
        // The topics step writes a discussion, then cannot tag it.
        $schema->rename('discussion_tag', 'discussion_tag_away');
        $run = $this->drive($run);
        $this->assertTrue($run['failed'], json_encode($run));
        $this->assertSame(0, $db->table('discussions')->count(), 'The failed step left nothing behind');

        $schema->rename('discussion_tag_away', 'discussion_tag');
        $run = $this->drive($this->post('resume', ['runId' => $run['runId']]));

        $this->assertTrue($run['done'], json_encode($run));
        $this->assertSame(['First topic', 'Third'], $db->table('discussions')->orderBy('id')->pluck('title')->all(), 'Each once; not the topic with no approved post');
        $this->assertSame([2, 1], $db->table('discussions')->orderBy('id')->pluck('comment_count')->map(fn ($c) => (int) $c)->all());
        $this->assertSame(1, $run['summary']['empty_removed'] ?? null);
        $tag = $db->table('tags')->where('id', $db->table('importer_map')->where('kind', 'tag')->value('target_id'))->first();
        $this->assertSame(2, (int) $tag->discussion_count);
    }

    #[Test]
    public function a_run_that_did_not_fail_cannot_be_resumed()
    {
        $this->extension('flarum-tags', 'ernestdefoe-importer');
        $this->phpbb();
        $run = $this->drive($this->post('start', ['source' => 'phpbb', 'config' => ['driver' => 'sqlite', 'database' => $this->source, 'prefix' => 'phpbb_']]));
        $this->assertTrue($run['done']);

        $this->assertArrayHasKey('error', $this->post('resume', ['runId' => $run['runId']]));
        $response = $this->send($this->request('POST', '/api/importer/resume', ['authenticatedAs' => 2, 'json' => ['runId' => $run['runId']]]));
        $this->assertSame(403, $response->getStatusCode(), 'Admins only');
    }

    #[Test]
    public function members_get_usernames_flarum_accepts_and_keep_their_names_as_nicknames()
    {
        $this->extension('flarum-tags', 'flarum-nicknames', 'ernestdefoe-importer');
        $this->phpbb();
        $this->drive($this->post('start', ['source' => 'phpbb', 'config' => ['driver' => 'sqlite', 'database' => $this->source, 'prefix' => 'phpbb_']]));

        $users = $this->database()->table('users')->where('id', '>', 2)->orderBy('id')->get(['username', 'nickname', 'marked_all_as_read_at']);
        $this->assertSame(['Jose_p', 'Al_6', 'jose_p_2'], $users->pluck('username')->all(), 'ASCII, 3 or more characters, unique without case');
        $this->assertSame(['José.p', 'Al', 'jose_p'], $users->pluck('nickname')->all(), 'Their name as the old board showed it');
        foreach ($users as $u) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_-]{3,30}$/i', $u->username);
            $this->assertNotNull($u->marked_all_as_read_at, 'The imported forum is not all unread');
        }
    }

    #[Test]
    public function an_unconfirmed_account_holding_the_old_admins_email_is_not_made_an_admin()
    {
        $this->extension('flarum-tags', 'ernestdefoe-importer');
        $this->prepareDatabase([
            'users' => [$this->normalUser(), ['id' => 3, 'username' => 'squatter', 'email' => 'ada@board.example', 'is_email_confirmed' => 0, 'password' => ''], ['id' => 4, 'username' => 'bobby', 'email' => 'bob@board.example', 'is_email_confirmed' => 1, 'password' => '']],
        ]);
        $pdo = new PDO('sqlite:'.$this->source);
        $pdo->exec('CREATE TABLE contenttype (contenttypeid INTEGER PRIMARY KEY, class TEXT)');
        $pdo->exec('CREATE TABLE node (nodeid INTEGER PRIMARY KEY, contenttypeid INTEGER, parentid INTEGER, title TEXT, description TEXT, displayorder INTEGER, userid INTEGER, publishdate INTEGER, created INTEGER, approved INTEGER, sticky INTEGER)');
        $pdo->exec('CREATE TABLE text (nodeid INTEGER PRIMARY KEY, rawtext TEXT, htmlstate TEXT)');
        $pdo->exec('CREATE TABLE user (userid INTEGER PRIMARY KEY, username TEXT, email TEXT, joindate INTEGER, lastactivity INTEGER, lastvisit INTEGER, usergroupid INTEGER, membergroupids TEXT)');
        $pdo->exec("INSERT INTO contenttype VALUES (1, 'Channel'), (2, 'Text')");
        $pdo->exec("INSERT INTO node VALUES (1, 1, 0, 'Root', '', 0, 0, 0, 0, 1, 0)");
        $pdo->exec("INSERT INTO user VALUES (5, 'Ada', 'ada@board.example', 1600000000, 0, 0, 6, ''), (6, 'Bob', 'bob@board.example', 1600000000, 0, 0, 6, '')");

        $run = $this->drive($this->post('start', ['source' => 'vbulletin', 'config' => ['driver' => 'sqlite', 'database' => $this->source, 'prefix' => '']]));

        $this->assertTrue($run['done'], json_encode($run));
        $admins = $this->database()->table('group_user')->where('group_id', 1)->pluck('user_id')->map(fn ($u) => (int) $u)->all();
        $this->assertNotContains(3, $admins, 'Nobody confirmed that address: no admin');
        $this->assertContains(4, $admins, 'A confirmed account with the old admin\'s address is theirs');
    }
}
