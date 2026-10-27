<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;

/**
 * Polls through a whole import, from the wizard's start call to the last
 * step: they land on the discussions the import made, and say so when FoF
 * Polls is not there to hold them. Each source's poll tables are covered in
 * {@see SourcePollsTest}.
 */
class PollImportTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const T = 1600000000;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDatabase(['users' => [$this->normalUser()]]);
        $this->source = tempnam(sys_get_temp_dir(), 'polls').'.sqlite';
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
        $pdo->exec('CREATE TABLE phpbb_topics (topic_id INTEGER PRIMARY KEY, forum_id INTEGER, topic_title TEXT, topic_poster INTEGER, topic_time INTEGER, topic_type INTEGER, topic_visibility INTEGER, topic_first_post_id INTEGER, poll_title TEXT, poll_start INTEGER, poll_length INTEGER, poll_max_options INTEGER, poll_vote_change INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_posts (post_id INTEGER PRIMARY KEY, topic_id INTEGER, poster_id INTEGER, post_text TEXT, bbcode_uid TEXT, post_time INTEGER, post_visibility INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_poll_options (poll_option_id INTEGER, topic_id INTEGER, poll_option_text TEXT, poll_option_total INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_poll_votes (topic_id INTEGER, poll_option_id INTEGER, vote_user_id INTEGER, vote_user_ip TEXT)');
        $pdo->exec("INSERT INTO phpbb_forums VALUES (2, 'General', 'Talk', 1, 1)");
        $pdo->exec("INSERT INTO phpbb_users VALUES (1, 'Anonymous', '', '', 0, 2), (5, 'Ada', 'ada@board.example', '', 1600000000, 0), (6, 'Bob', 'bob@board.example', '', 1600000100, 0)");
        $pdo->exec("INSERT INTO phpbb_topics VALUES
            (10, 2, 'No poll here', 5, 1600001000, 0, 1, 100, '', 0, 0, 1, 0),
            (11, 2, 'Pick some', 6, 1600002000, 0, 1, 110, 'Which ones?', 1600002000, 0, 2, 0),
            (12, 2, 'Removed', 6, 1600003000, 0, 2, 120, 'Gone?', 1600003000, 0, 1, 0)");
        $pdo->exec("INSERT INTO phpbb_posts VALUES (100, 10, 5, 'Hello', '', 1600001000, 1), (110, 11, 6, 'Vote', '', 1600002000, 1), (111, 11, 5, 'Voted', '', 1600002100, 1), (120, 12, 6, 'x', '', 1600003000, 1)");
        $pdo->exec("INSERT INTO phpbb_poll_options VALUES (1, 11, 'One', 2), (2, 11, 'Two', 1), (3, 11, 'Three', 1), (1, 12, 'Yes', 0), (2, 12, 'No', 0)");
        // Ada picked One and Two, Bob One, a guest (the Anonymous account) Three.
        $pdo->exec("INSERT INTO phpbb_poll_votes VALUES (11, 1, 5, ''), (11, 2, 5, ''), (11, 1, 6, ''), (11, 3, 1, '')");
    }

    private function import(string $source, string $prefix): array
    {
        $response = $this->send($this->request('POST', '/api/importer/start', [
            'authenticatedAs' => 1,
            'json' => ['source' => $source, 'config' => ['driver' => 'sqlite', 'database' => $this->source, 'prefix' => $prefix]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $run = json_decode((string) $response->getBody(), true);

        for ($i = 0; $i < 60 && ! $run['done'] && ! $run['failed']; $i++) {
            $response = $this->send($this->request('POST', '/api/importer/step', ['authenticatedAs' => 1, 'json' => ['runId' => $run['runId']]]));
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $run = json_decode((string) $response->getBody(), true);
        }
        $this->assertTrue($run['done'], json_encode($run));

        return $run;
    }

    #[Test]
    public function a_poll_lands_on_the_first_post_of_its_discussion()
    {
        $this->extension('flarum-tags', 'fof-polls', 'ernestdefoe-importer');
        $this->phpbb();

        $run = $this->import('phpbb', 'phpbb_');

        $db = $this->database();
        $discussion = $db->table('discussions')->where('title', 'Pick some')->first();
        $polls = $db->table('polls')->get();
        $this->assertCount(1, $polls, 'Not the topic without a poll, nor the removed one');
        $poll = $polls[0];
        $this->assertSame((int) $discussion->first_post_id, (int) $poll->post_id);
        $this->assertSame('Which ones?', $poll->question);
        $this->assertSame((int) $db->table('users')->where('email', 'bob@board.example')->value('id'), (int) $poll->user_id);
        $this->assertEquals(['public_poll' => false, 'allow_multiple_votes' => true, 'max_votes' => 2, 'hide_votes' => false, 'allow_change_vote' => false], json_decode($poll->settings, true));
        $this->assertNotNull($poll->published_at, 'Published, not a draft');

        $ada = (int) $db->table('users')->where('email', 'ada@board.example')->value('id');
        $bob = (int) $db->table('users')->where('email', 'bob@board.example')->value('id');
        $options = $db->table('poll_options')->where('poll_id', $poll->id)->orderBy('id')->get();
        $this->assertSame(['One', 'Two', 'Three'], $options->pluck('answer')->all());
        $this->assertSame([2, 1, 1], $options->pluck('vote_count')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(4, (int) $poll->vote_count);
        $votes = fn ($o) => $db->table('poll_votes')->where('option_id', $o->id)->orderBy('user_id')->pluck('user_id')->map(fn ($u) => $u === null ? null : (int) $u)->all();
        $this->assertEqualsCanonicalizing([$ada, $bob], $votes($options[0]));
        $this->assertSame([$ada], $votes($options[1]));
        $this->assertSame([null], $votes($options[2]), 'The guest vote counts, with no voter');

        $this->assertSame(1, $run['summary']['polls']);
        $this->assertSame(4, $run['summary']['poll_votes']);
        $this->assertSame(1, $run['summary']['polls_skipped'], 'The removed topic\'s poll');

        // fof/polls reads it back as its own.
        $response = $this->send($this->request('GET', '/api/posts/'.$discussion->first_post_id, ['authenticatedAs' => 1])->withQueryParams(['include' => 'polls,polls.options']));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame([(string) $poll->id], array_column($body['data']['relationships']['polls']['data'] ?? [], 'id'));
        $answers = array_column(array_column(array_filter($body['included'] ?? [], fn ($r) => $r['type'] === 'poll_options'), 'attributes'), 'answer');
        $this->assertSame(['One', 'Two', 'Three'], $answers);
    }

    #[Test]
    public function without_fof_polls_the_import_says_why_no_polls_came_across()
    {
        $this->extension('flarum-tags', 'ernestdefoe-importer');
        $this->phpbb();

        $run = $this->import('phpbb', 'phpbb_');

        $this->assertSame(1, $run['summary']['polls_unavailable'] ?? null);
        $this->assertSame(2, $this->database()->table('discussions')->count(), 'The rest imports as ever');
    }

    #[Test]
    public function a_vbulletin_5_poll_thread_is_a_discussion_with_its_poll()
    {
        $this->extension('flarum-tags', 'fof-polls', 'ernestdefoe-importer');
        $pdo = new PDO('sqlite:'.$this->source);
        $pdo->exec('CREATE TABLE contenttype (contenttypeid INTEGER PRIMARY KEY, class TEXT)');
        $pdo->exec('CREATE TABLE node (nodeid INTEGER PRIMARY KEY, contenttypeid INTEGER, parentid INTEGER, title TEXT, description TEXT, displayorder INTEGER, userid INTEGER, publishdate INTEGER, created INTEGER, approved INTEGER, sticky INTEGER)');
        $pdo->exec('CREATE TABLE text (nodeid INTEGER PRIMARY KEY, rawtext TEXT, htmlstate TEXT)');
        $pdo->exec('CREATE TABLE user (userid INTEGER PRIMARY KEY, username TEXT, email TEXT, joindate INTEGER, lastactivity INTEGER, lastvisit INTEGER, usergroupid INTEGER, membergroupids TEXT)');
        $pdo->exec('CREATE TABLE poll (nodeid INTEGER PRIMARY KEY, active INTEGER, timeout INTEGER, multiple INTEGER, public INTEGER, lastvote INTEGER)');
        $pdo->exec('CREATE TABLE polloption (polloptionid INTEGER PRIMARY KEY, nodeid INTEGER, title TEXT, votes INTEGER)');
        $pdo->exec('CREATE TABLE pollvote (pollvoteid INTEGER PRIMARY KEY, nodeid INTEGER, polloptionid INTEGER, userid INTEGER, votedate INTEGER)');
        $pdo->exec("INSERT INTO contenttype VALUES (1, 'Channel'), (2, 'Text'), (9, 'Poll')");
        $pdo->exec("INSERT INTO node VALUES
            (1, 1, 0, 'Root', '', 0, 0, 0, 0, 1, 0),
            (10, 1, 1, 'Forum', '', 1, 0, 0, 0, 1, 0),
            (100, 9, 10, 'Lunch?', '', 0, 5, 1600001000, 1600001000, 1, 0),
            (101, 2, 100, '', '', 0, 6, 1600001100, 1600001100, 1, 0)");
        $pdo->exec("INSERT INTO text VALUES (100, 'Where shall we go?', 'off'), (101, 'Pizza!', 'off')");
        $pdo->exec("INSERT INTO user VALUES (5, 'Ada', 'ada@board.example', 1600000000, 0, 0, 2, ''), (6, 'Bob', 'bob@board.example', 1600000100, 0, 0, 2, '')");
        $pdo->exec('INSERT INTO poll VALUES (100, 1, 0, 0, 1, 0)');
        $pdo->exec("INSERT INTO polloption VALUES (1, 100, 'Pizza', 1), (2, 100, 'Sushi', 0)");
        $pdo->exec('INSERT INTO pollvote VALUES (1, 100, 1, 6, 1600001100)');

        $this->import('vbulletin', '');

        $db = $this->database();
        $discussion = $db->table('discussions')->where('title', 'Lunch?')->first();
        $this->assertNotNull($discussion, 'A Poll starter node is a thread');
        $this->assertSame(2, (int) $discussion->comment_count, 'With its reply');
        $poll = $db->table('polls')->first();
        $this->assertSame((int) $discussion->first_post_id, (int) $poll->post_id);
        $this->assertSame('Lunch?', $poll->question);
        $this->assertSame([(int) $db->table('users')->where('email', 'bob@board.example')->value('id')], $db->table('poll_votes')->pluck('user_id')->map(fn ($u) => (int) $u)->all());
    }
}
