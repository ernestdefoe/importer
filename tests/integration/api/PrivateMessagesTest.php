<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use ErnestDefoe\Importer\Tests\integration\MessagesFixture;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;

/**
 * A phpBB board's private messages through the wizard, end to end: the import,
 * the sync that brings new ones, blind copies, read state, and what happens to
 * group conversations with and without Group Messages.
 */
class PrivateMessagesTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use MessagesFixture;

    private const T = 1600000000;

    private string $file;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDatabase(['users' => [$this->normalUser()]]);

        $this->file = tempnam(sys_get_temp_dir(), 'importer-pm-').'.sqlite';
        $this->pdo = new PDO('sqlite:'.$this->file);
        $t = self::T;
        foreach ([
            'CREATE TABLE phpbb_forums (forum_id INTEGER PRIMARY KEY, forum_name TEXT, forum_desc TEXT, forum_type INTEGER, left_id INTEGER)',
            'CREATE TABLE phpbb_users (user_id INTEGER PRIMARY KEY, username TEXT, user_email TEXT, user_password TEXT, user_regdate INTEGER, user_type INTEGER)',
            'CREATE TABLE phpbb_topics (topic_id INTEGER PRIMARY KEY, forum_id INTEGER, topic_title TEXT, topic_poster INTEGER, topic_time INTEGER, topic_type INTEGER, topic_visibility INTEGER)',
            'CREATE TABLE phpbb_posts (post_id INTEGER PRIMARY KEY, topic_id INTEGER, poster_id INTEGER, post_text TEXT, bbcode_uid TEXT, post_time INTEGER, post_visibility INTEGER, post_edit_time INTEGER DEFAULT 0)',
            'CREATE TABLE phpbb_privmsgs (msg_id INTEGER PRIMARY KEY, root_level INTEGER, author_id INTEGER, message_time INTEGER, message_subject TEXT, message_text TEXT, bbcode_uid TEXT, to_address TEXT, bcc_address TEXT)',
            'CREATE TABLE phpbb_privmsgs_to (msg_id INTEGER, user_id INTEGER, author_id INTEGER, pm_deleted INTEGER, pm_new INTEGER, pm_unread INTEGER, folder_id INTEGER)',
            "INSERT INTO phpbb_users VALUES (10, 'Ada', 'ada@board.example', '', $t, 0), (11, 'Bob', 'bob@board.example', '', $t, 0), (12, 'Cy', 'cy@board.example', '', $t, 0)",
            "INSERT INTO phpbb_privmsgs VALUES (1, 0, 10, $t, 'Hi', 'Hello [b:x1]Bob[/b:x1]', 'x1', 'u_11', ''), (2, 1, 11, $t + 100, 'Re: Hi', 'Hi Ada', '', 'u_10', ''),
                (3, 0, 10, $t + 200, 'Plans', 'Group hello', '', 'u_11:u_12', ''),
                (4, 0, 10, $t + 300, 'Psst', 'Bob sees this, Cy is blind-copied', '', 'u_11', 'u_12')",
            'INSERT INTO phpbb_privmsgs_to VALUES (1, 10, 10, 0, 0, 0, -1), (1, 11, 10, 0, 0, 0, 0), (2, 11, 11, 0, 0, 0, -1), (2, 10, 11, 0, 1, 1, 0),
                (3, 10, 10, 0, 0, 0, -1), (3, 11, 10, 0, 0, 0, 0), (3, 12, 10, 0, 0, 0, 0), (4, 10, 10, 0, 0, 0, -1), (4, 11, 10, 0, 0, 0, 0), (4, 12, 10, 0, 0, 0, 0)',
        ] as $sql) {
            $this->pdo->exec($sql);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    private function drive(array $body): array
    {
        $response = $this->send($this->request('POST', '/api/importer/start', ['authenticatedAs' => 1, 'json' => $body]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $run = json_decode((string) $response->getBody(), true);
        for ($i = 0; $i < 60 && ! $run['done'] && ! $run['failed']; $i++) {
            $run = json_decode((string) $this->send($this->request('POST', '/api/importer/step', ['authenticatedAs' => 1, 'json' => ['runId' => $run['runId']]]))->getBody(), true);
        }
        $this->assertTrue($run['done'], json_encode($run));

        return $run;
    }

    private function import(array $extra = []): array
    {
        return $this->drive(['source' => 'phpbb', 'config' => ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => 'phpbb_'] + $extra]);
    }

    private function sync(): array
    {
        return $this->drive(['sync' => true]);
    }

    private function user(string $name): int
    {
        return (int) $this->database()->table('users')->where('email', strtolower($name).'@board.example')->value('id');
    }

    #[Test]
    public function conversations_arrive_with_their_people_and_nobody_else()
    {
        $this->extension('flarum-markdown', 'flarum-messages', 'ernestdefoe-group-messages', 'ernestdefoe-importer');
        $run = $this->import();
        [$ada, $bob, $cy] = [$this->user('Ada'), $this->user('Bob'), $this->user('Cy')];

        $this->assertSame(['Hello Bob', 'Hi Ada', 'Bob sees this, Cy is blind-copied'], $this->texts('direct', [$ada, $bob]));
        $this->assertSame(['Group hello'], $this->texts('group', [$ada, $bob, $cy]));
        // Cy's blind copy is hers alone, with Ada: Bob never learns she had it.
        $this->assertSame(['Bob sees this, Cy is blind-copied'], $this->texts('direct', [$ada, $cy]));
        // Four messages, and Cy's copy of the fourth.
        $this->assertSame(['messages' => 5, 'conversations' => 3], array_intersect_key($run['summary'], ['messages' => 0, 'conversations' => 0]));
        // The key where the test forum has no catalogue, the sentence where it has.
        $this->assertMatchesRegularExpression('/api\.pm\.summary|5 private messages/', $run['lastStatus']);

        // Formatted like a post.
        $db = $this->database();
        $this->assertStringContainsString('<STRONG>', (string) $db->table('dialog_messages')->orderBy('id')->value('content'));

        // Ada never read Bob's reply on the old board; everything else is read.
        $direct = $db->table('dialogs')->where('type', 'direct')->orderBy('id')->first();
        $first = (int) $db->table('dialog_messages')->where('dialog_id', $direct->id)->where('number', 1)->value('id');
        $this->assertSame($first, (int) $db->table('dialog_user')->where('dialog_id', $direct->id)->where('user_id', $ada)->value('last_read_message_id'));
        $this->assertSame((int) $direct->last_message_id, (int) $db->table('dialog_user')->where('dialog_id', $direct->id)->where('user_id', $bob)->value('last_read_message_id'));
        $this->assertSame($this->at(self::T + 300), substr((string) $direct->last_message_at, 0, 19));

        // Visible to its members through the API, to nobody else.
        $asCy = json_decode((string) $this->send($this->request('GET', '/api/dialogs', ['authenticatedAs' => $cy]))->getBody(), true);
        $this->assertCount(2, $asCy['data']);
        $asMember = json_decode((string) $this->send($this->request('GET', '/api/dialogs', ['authenticatedAs' => 2]))->getBody(), true);
        $this->assertCount(0, $asMember['data']);
    }

    #[Test]
    public function a_sync_brings_new_messages_once_and_an_older_import_gets_all_of_them()
    {
        $this->extension('flarum-markdown', 'flarum-messages', 'ernestdefoe-group-messages', 'ernestdefoe-importer');
        $this->import();
        $db = $this->database();
        // What an import from before 0.5.0 left: no messages at all.
        foreach (['dialog_messages', 'dialog_user', 'group_dialogs', 'dialogs'] as $table) {
            $db->table($table)->delete(); // SQLite here does not cascade
        }
        $db->table('importer_map')->whereIn('kind', ['pm', 'pm-dialog', 'pm-cursor', 'pm-unread'])->delete();

        $t = self::T + 1000;
        $this->pdo->exec("INSERT INTO phpbb_privmsgs VALUES (5, 1, 10, $t, 'Re: Hi', 'Still there?', '', 'u_11', '')");
        $this->pdo->exec('INSERT INTO phpbb_privmsgs_to VALUES (5, 10, 10, 0, 0, 0, -1), (5, 11, 10, 0, 1, 1, 0)');

        $run = $this->sync();
        $ada = $this->user('Ada');
        $bob = $this->user('Bob');
        $this->assertSame(6, $run['summary']['messages']);
        $this->assertSame(['Hello Bob', 'Hi Ada', 'Bob sees this, Cy is blind-copied', 'Still there?'], $this->texts('direct', [$ada, $bob]));

        // Bob, writing on Flarum meanwhile, then one more on the old board.
        $dialog = (int) $db->table('dialogs')->where('type', 'direct')->orderBy('id')->value('id');
        $db->table('dialog_messages')->insert(['dialog_id' => $dialog, 'user_id' => $bob, 'content' => '<t>Written here</t>', 'number' => 5, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->pdo->exec("INSERT INTO phpbb_privmsgs VALUES (6, 1, 11, $t + 100, 'Re: Hi', 'Yes', '', 'u_10', '')");
        $this->pdo->exec('INSERT INTO phpbb_privmsgs_to VALUES (6, 11, 11, 0, 0, 0, -1), (6, 10, 11, 0, 0, 0, 0)');

        $again = $this->sync();
        $this->assertSame(1, $again['summary']['messages']);
        $this->assertSame(0, $again['summary']['conversations']);
        // Dated before what was written here, so it reads before it.
        $this->assertSame(['Hello Bob', 'Hi Ada', 'Bob sees this, Cy is blind-copied', 'Still there?', 'Yes', 'Written here'], $this->texts('direct', [$ada, $bob]));
        $this->assertSame('Written here', trim(strip_tags((string) $db->table('dialog_messages')->where('id', $db->table('dialogs')->where('id', $dialog)->value('last_message_id'))->value('content'))));

        $this->sync();
        $this->assertSame(8, $db->table('dialog_messages')->count(), 'Nothing doubled');
        $this->assertSame(3, $db->table('dialogs')->count());
    }

    #[Test]
    public function without_group_messages_a_group_conversation_is_skipped_and_counted_never_split()
    {
        $this->extension('flarum-markdown', 'flarum-messages', 'ernestdefoe-importer');
        $run = $this->import();

        $db = $this->database();
        $this->assertSame(0, $db->table('dialogs')->where('type', '!=', 'direct')->count());
        $this->assertSame(['Hello Bob', 'Hi Ada', 'Bob sees this, Cy is blind-copied'], $this->texts('direct', [$this->user('Ada'), $this->user('Bob')]));
        $this->assertNull($this->texts('direct', [$this->user('Bob'), $this->user('Cy')]), 'Never split into pairs');
        $this->assertSame(1, $run['summary']['pm_groups_skipped']);
        $this->assertMatchesRegularExpression('/api\.pm\.groups_skipped|1 group conversations skipped/', $run['lastStatus']);

        // Synced again: the same conversation is not counted twice.
        $this->assertArrayNotHasKey('pm_groups_skipped', $this->sync()['summary']);
    }

    #[Test]
    public function group_conversations_skipped_earlier_arrive_once_group_messages_is_on()
    {
        $this->extension('flarum-markdown', 'flarum-messages', 'ernestdefoe-group-messages', 'ernestdefoe-importer');
        $this->import();
        $db = $this->database();
        // As if the import ran before Group Messages was installed.
        $group = $db->table('dialogs')->where('type', 'group')->pluck('id');
        foreach (['dialog_messages', 'dialog_user', 'group_dialogs'] as $table) {
            $db->table($table)->whereIn('dialog_id', $group)->delete(); // SQLite here does not cascade
        }
        $db->table('dialogs')->whereIn('id', $group)->delete();
        $db->table('importer_map')->where('kind', 'pm-dialog')->where('source_id', 'like', 'g:%')->delete();
        $db->table('importer_map')->where('kind', 'pm')->where('source_id', '3')->delete();
        $runId = (int) $db->table('importer_runs')->value('id');
        $db->table('importer_map')->insert(['run_id' => $runId, 'kind' => 'pm-skip', 'source_id' => 'g:x', 'target_id' => 0]);

        $run = $this->sync();

        $this->assertSame(1, $run['summary']['messages']);
        $this->assertSame(['Group hello'], $this->texts('group', [$this->user('Ada'), $this->user('Bob'), $this->user('Cy')]));
        $this->assertSame(0, $db->table('importer_map')->where('kind', 'pm-skip')->count());
        $this->assertSame(5, $db->table('dialog_messages')->count());
    }

    #[Test]
    public function messages_can_be_left_behind_or_are_explained_when_messages_is_off()
    {
        $this->extension('flarum-markdown', 'flarum-messages', 'ernestdefoe-importer');
        $run = $this->import(['private_messages' => '0']);
        $this->assertSame(0, $this->database()->table('dialogs')->count());
        $this->assertArrayNotHasKey('messages', $run['summary']);
    }

    #[Test]
    public function without_messages_the_phase_says_why()
    {
        $this->extension('flarum-markdown', 'ernestdefoe-importer');
        $run = $this->import();
        $this->assertSame(1, $run['summary']['pms_unavailable']);
        $this->assertMatchesRegularExpression('/api\.pm\.unavailable|Messages is not enabled/', $run['lastStatus']);
    }

    #[Test]
    public function the_connection_test_counts_messages()
    {
        $this->extension('flarum-markdown', 'flarum-messages', 'ernestdefoe-importer');
        $response = $this->send($this->request('POST', '/api/importer/test', [
            'authenticatedAs' => 1,
            'json' => ['source' => 'phpbb', 'config' => ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => 'phpbb_']],
        ]));
        $this->assertSame(4, json_decode((string) $response->getBody(), true)['counts']['messages'] ?? null, (string) $response->getBody());
    }
}
