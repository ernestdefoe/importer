<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use ErnestDefoe\Importer\Importers\Dst;
use ErnestDefoe\Importer\Importers\Vbulletin5Importer;
use ErnestDefoe\Importer\Importers\VbulletinImporter;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Illuminate\Support\Carbon;
use PDO;
use PHPUnit\Framework\Attributes\Test;

/**
 * vBulletin 3/4 and 5/6 boards in SQLite files, holding what used to come
 * across wrong: moved-thread stubs, closed threads, staff, banned and
 * unconfirmed members, escaped titles, staff-only forums, a forum tree,
 * soft-deleted posts, comments on replies and Gallery/Video threads.
 */
class VbulletinFixesTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private string $source;

    private int $lift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-lock', 'flarum-suspend', 'flarum-nicknames', 'ernestdefoe-importer');
        $this->prepareDatabase(['users' => [$this->normalUser()]]);
        $this->source = tempnam(sys_get_temp_dir(), 'vbfix').'.sqlite';
        $this->lift = Carbon::now()->addDays(30)->startOfSecond()->getTimestamp();
    }

    protected function allowedRepeatedQueries(): array
    {
        // The importer writes one tag and one member per source record by
        // design (Dst::tag and Dst::user reuse what is already there).
        return ['tags" ("name", "slug"', 'tags` (`name`, `slug`', 'tags" where "slug" = ?', 'tags` where `slug` = ?', 'users" where "email" = ?', 'users` where `email` = ?', 'LOWER(username) = ?', 'importer_passwords'];
    }

    protected function tearDown(): void
    {
        @unlink($this->source);

        parent::tearDown();
    }

    private function import(string $source): void
    {

        $response = $this->send($this->request('POST', '/api/importer/start', [
            'authenticatedAs' => 1,
            'json' => ['source' => $source, 'config' => ['driver' => 'sqlite', 'database' => $this->source, 'prefix' => '']],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $run = json_decode((string) $response->getBody(), true);
        for ($i = 0; $i < 60 && ! $run['done'] && ! $run['failed']; $i++) {
            $response = $this->send($this->request('POST', '/api/importer/step', ['authenticatedAs' => 1, 'json' => ['runId' => $run['runId']]]));
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $run = json_decode((string) $response->getBody(), true);
        }
        $this->assertTrue($run['done'], json_encode($run));
    }

    private function vb4(): void
    {
        $pdo = new PDO('sqlite:'.$this->source);
        $pdo->exec('CREATE TABLE user (userid INTEGER PRIMARY KEY, username TEXT, email TEXT, joindate INTEGER, password TEXT, salt TEXT, usergroupid INTEGER, membergroupids TEXT)');
        $pdo->exec('CREATE TABLE userban (userid INTEGER PRIMARY KEY, usergroupid INTEGER, liftdate INTEGER)');
        $pdo->exec('CREATE TABLE forum (forumid INTEGER PRIMARY KEY, title TEXT, description TEXT, displayorder INTEGER, parentid INTEGER, parentlist TEXT, options INTEGER)');
        $pdo->exec('CREATE TABLE forumpermission (forumpermissionid INTEGER PRIMARY KEY, forumid INTEGER, usergroupid INTEGER, forumpermissions INTEGER)');
        $pdo->exec('CREATE TABLE usergroup (usergroupid INTEGER PRIMARY KEY, forumpermissions INTEGER)');
        $pdo->exec('CREATE TABLE thread (threadid INTEGER PRIMARY KEY, title TEXT, forumid INTEGER, postuserid INTEGER, dateline INTEGER, sticky INTEGER, visible INTEGER, open INTEGER, pollid INTEGER)');
        $pdo->exec('CREATE TABLE post (postid INTEGER PRIMARY KEY, threadid INTEGER, userid INTEGER, pagetext TEXT, dateline INTEGER, visible INTEGER)');

        $pdo->exec("INSERT INTO user VALUES
            (1, 'Q&amp;A Admin', 'admin@board.example', 1600000000, 'x', 's', 2, '6'),
            (2, 'Moddy', 'mod@board.example', 1600000000, 'x', 's', 7, ''),
            (3, 'Banned', 'banned@board.example', 1600000000, 'x', 's', 8, ''),
            (4, 'Tempban', 'temp@board.example', 1600000000, 'x', 's', 8, ''),
            (5, 'Pending', 'pending@board.example', 1600000000, 'x', 's', 3, ''),
            (6, 'Plain', 'plain@board.example', 1600000000, 'x', 's', 2, '')");
        $pdo->exec("INSERT INTO userban VALUES (4, 2, {$this->lift})");
        $pdo->exec("INSERT INTO forum VALUES
            (1, 'Q&amp;A', '', 1, -1, '1,-1', 1),
            (2, 'Help', '', 1, 1, '2,1,-1', 7),
            (3, 'Deep', '', 1, 2, '3,2,1,-1', 7),
            (4, 'Empty category', '', 2, -1, '4,-1', 1),
            (5, 'Staff', '', 3, -1, '5,-1', 7),
            (6, 'Staff archive', '', 1, 5, '6,5,-1', 7),
            (7, 'Members only', '', 4, -1, '7,-1', 7)");
        $pdo->exec('INSERT INTO forumpermission VALUES (1, 5, 1, 0), (2, 5, 2, 0), (3, 7, 1, 0)');
        $pdo->exec('INSERT INTO usergroup VALUES (1, 1), (2, 1)');
        $pdo->exec("INSERT INTO thread VALUES
            (10, 'Tom &amp; Jerry', 2, 1, 1600001000, 0, 1, 1, 0),
            (11, 'Closed', 3, 6, 1600002000, 0, 1, 0, 0),
            (12, 'Moved', 2, 6, 1600003000, 0, 1, 10, 0)");
        $pdo->exec("INSERT INTO post VALUES (100, 10, 1, 'Hello', 1600001000, 1), (101, 10, 6, 'Reply', 1600001100, 1), (110, 11, 6, 'Shut', 1600002000, 1)");
    }

    private function vb5(): void
    {
        $pdo = new PDO('sqlite:'.$this->source);
        $pdo->exec('CREATE TABLE contenttype (contenttypeid INTEGER PRIMARY KEY, class TEXT)');
        $pdo->exec('CREATE TABLE node (nodeid INTEGER PRIMARY KEY, contenttypeid INTEGER, parentid INTEGER, starter INTEGER, title TEXT, description TEXT, displayorder INTEGER, userid INTEGER, publishdate INTEGER, created INTEGER, approved INTEGER, showpublished INTEGER, deleteuserid INTEGER, open INTEGER, sticky INTEGER)');
        $pdo->exec('CREATE TABLE text (nodeid INTEGER PRIMARY KEY, rawtext TEXT, htmlstate TEXT)');
        $pdo->exec('CREATE TABLE user (userid INTEGER PRIMARY KEY, username TEXT, email TEXT, joindate INTEGER, lastactivity INTEGER, lastvisit INTEGER, usergroupid INTEGER, membergroupids TEXT)');
        $pdo->exec('CREATE TABLE userban (userid INTEGER PRIMARY KEY, usergroupid INTEGER, liftdate INTEGER)');
        $pdo->exec('CREATE TABLE routenew (routeid INTEGER PRIMARY KEY, prefix TEXT, class TEXT, contentid INTEGER)');
        $pdo->exec('CREATE TABLE permission (permissionid INTEGER PRIMARY KEY, nodeid INTEGER, groupid INTEGER, forumpermissions INTEGER)');

        $pdo->exec("INSERT INTO contenttype VALUES (1, 'Channel'), (2, 'Text'), (3, 'Gallery'), (4, 'Video')");
        $pdo->exec("INSERT INTO node VALUES
            (1, 1, 0, 0, 'Root', '', 0, 0, 0, 0, 1, 1, 0, 1, 0),
            (2, 1, 1, 0, 'Forum', '', 0, 0, 0, 0, 1, 1, 0, 1, 0),
            (10, 1, 2, 0, 'News &amp; Views', '', 1, 0, 0, 0, 1, 1, 0, 1, 0),
            (11, 1, 2, 0, 'Staff', '', 2, 0, 0, 0, 1, 1, 0, 1, 0),
            (100, 2, 10, 100, 'Fish &amp; Chips', '', 0, 5, 1600001000, 1600001000, 1, 1, 0, 0, 0),
            (101, 2, 100, 100, '', '', 0, 6, 1600001100, 1600001100, 1, 1, 0, 1, 0),
            (102, 2, 101, 100, '', '', 0, 5, 1600001200, 1600001200, 1, 1, 0, 1, 0),
            (103, 2, 100, 100, '', '', 0, 6, 1600001300, 1600001300, 1, 0, 0, 1, 0),
            (104, 2, 100, 100, '', '', 0, 6, 1600001400, 1600001400, 1, 1, 5, 1, 0),
            (110, 2, 10, 110, 'Deleted thread', '', 0, 6, 1600002000, 1600002000, 1, 0, 5, 1, 0),
            (111, 2, 110, 110, '', '', 0, 5, 1600002100, 1600002100, 1, 0, 0, 1, 0),
            (120, 3, 10, 120, 'My photos', '', 0, 5, 1600003000, 1600003000, 1, 1, 0, 1, 0),
            (121, 2, 120, 120, '', '', 0, 6, 1600003100, 1600003100, 1, 1, 0, 1, 0),
            (130, 4, 11, 130, 'Staff video', '', 0, 5, 1600004000, 1600004000, 1, 1, 0, 1, 0)");
        $pdo->exec("INSERT INTO text VALUES (100, 'Starter', 'off'), (101, 'Reply', 'off'), (102, 'A comment on the reply', 'off'),
            (103, 'Unpublished', 'off'), (104, 'Soft-deleted', 'off'), (110, 'Gone', 'off'), (111, 'Gone too', 'off'),
            (120, 'Look at these', 'off'), (121, 'Nice', 'off'), (130, 'Watch', 'off')");
        $pdo->exec("INSERT INTO user VALUES
            (5, 'Ada &amp; Co', 'ada@board.example', 1600000000, 0, 0, 2, ''),
            (6, 'Bob', 'bob@board.example', 1600000000, 0, 0, 8, ''),
            (7, 'Cy', 'cy@board.example', 1600000000, 0, 0, 4, ''),
            (8, 'Di', 'di@board.example', 1600000000, 0, 0, 8, '')");
        $pdo->exec("INSERT INTO userban VALUES (8, 2, {$this->lift})");
        $pdo->exec("INSERT INTO routenew VALUES (1, '', 'vB5_Route_Channel', 1), (2, 'forum', 'vB5_Route_Channel', 2),
            (3, 'forum/news-views', 'vB5_Route_Channel', 10), (4, 'forum/staff', 'vB5_Route_Channel', 11)");
        $pdo->exec('INSERT INTO permission VALUES (1, 1, 1, 1), (2, 1, 2, 1), (3, 11, 1, 0), (4, 11, 2, 0)');
    }

    private function user(string $email): object
    {
        return $this->database()->table('users')->where('email', $email)->first();
    }

    private function inGroup(string $email, int $group): bool
    {
        return $this->database()->table('group_user')->where('user_id', $this->user($email)->id)->where('group_id', $group)->exists();
    }

    #[Test]
    public function vbulletin_4_threads_members_and_forums_come_across_as_on_the_board()
    {
        $this->vb4();
        $this->app();
        $this->assertSame(2, VbulletinImporter::test(['driver' => 'sqlite', 'database' => $this->source, 'prefix' => ''])['counts']['topics'], 'A moved-thread stub is not a topic');
        $this->import('vbulletin');
        $db = $this->database();

        $discussions = $db->table('discussions')->get()->keyBy('title');
        $this->assertEqualsCanonicalizing(['Tom & Jerry', 'Closed'], $discussions->keys()->all());
        $this->assertSame([0, 1], [(int) $discussions['Tom & Jerry']->is_locked, (int) $discussions['Closed']->is_locked], 'A closed thread is locked');

        $this->assertTrue($this->inGroup('admin@board.example', 1), 'Secondary group 6 is an admin');
        $this->assertTrue($this->inGroup('mod@board.example', 4), 'Group 7 is a moderator');
        $this->assertFalse($this->inGroup('plain@board.example', 4));
        $this->assertSame('Q&A Admin', $this->user('admin@board.example')->nickname);

        $this->assertSame(2038, Carbon::parse($this->user('banned@board.example')->suspended_until)->year, 'Banned for good');
        $this->assertSame($this->lift, Carbon::parse($this->user('temp@board.example')->suspended_until)->getTimestamp(), 'Banned until the lift date');
        $this->assertNull($this->user('plain@board.example')->suspended_until);
        $this->assertSame([0, 1], [(int) $this->user('pending@board.example')->is_email_confirmed, (int) $this->user('plain@board.example')->is_email_confirmed]);

        $tags = $db->table('tags')->whereIn('id', $db->table('importer_map')->where('kind', 'tag')->pluck('target_id'))->get()->keyBy('name');
        $this->assertEqualsCanonicalizing(['Q&A', 'Help', 'Deep (Help)', 'Staff', 'Staff archive', 'Members only'], $tags->keys()->all(), 'No tag for an empty category');
        $this->assertNull($tags['Q&A']->parent_id);
        $this->assertSame((int) $tags['Q&A']->id, (int) $tags['Help']->parent_id);
        $this->assertSame((int) $tags['Q&A']->id, (int) $tags['Deep (Help)']->parent_id);
        $this->assertSame((int) $tags['Staff']->id, (int) $tags['Staff archive']->parent_id);
        $restricted = $tags->filter(fn ($t) => (bool) $t->is_restricted)->keys()->all();
        $this->assertEqualsCanonicalizing(['Staff', 'Staff archive'], $restricted, 'Staff forums, and what inherits from them; not a members-only one');
    }

    #[Test]
    public function vbulletin_5_threads_replies_comments_and_members_come_across_as_on_the_board()
    {
        $this->vb5();
        $this->app();
        $counts = Vbulletin5Importer::test(['driver' => 'sqlite', 'database' => $this->source, 'prefix' => ''])['counts'];
        $this->assertSame([3, 6], [$counts['topics'], $counts['posts']], 'Only what is imported is counted');
        $this->import('vbulletin');
        $db = $this->database();

        $discussions = $db->table('discussions')->get()->keyBy('title');
        $this->assertEqualsCanonicalizing(['Fish & Chips', 'My photos', 'Staff video'], $discussions->keys()->all(), 'Gallery and Video threads, not a deleted one');
        $fish = $discussions['Fish & Chips'];
        $this->assertSame(1, (int) $fish->is_locked);
        $bodies = $db->table('posts')->where('discussion_id', $fish->id)->orderBy('number')->pluck('content')->implode(' | ');
        $this->assertStringContainsString('A comment on the reply', $bodies);
        $this->assertStringNotContainsString('Unpublished', $bodies);
        $this->assertStringNotContainsString('Soft-deleted', $bodies);
        $this->assertSame([3, 3], [(int) $fish->comment_count, (int) $fish->last_post_number]);
        $this->assertSame(2, (int) $discussions['My photos']->comment_count);

        $posts = $db->table('importer_map')->where('kind', 'post')->pluck('target_id', 'source_id')->all();
        $this->assertEqualsCanonicalizing(['100', '101', '102', '120', '121', '130'], array_map('strval', array_keys($posts)), 'Every post, starters too, for old links');
        $this->assertSame(1, (int) $db->table('posts')->where('id', $posts['100'])->value('number'));
        $this->assertSame((int) $fish->id, (int) $db->table('posts')->where('id', $posts['102'])->value('discussion_id'));

        $tags = $db->table('tags')->whereIn('id', $db->table('importer_map')->where('kind', 'tag')->pluck('target_id'))->get()->keyBy('name');
        $this->assertEqualsCanonicalizing(['News & Views', 'Staff'], $tags->keys()->all());
        $this->assertSame([0, 1], [(int) $tags['News & Views']->is_restricted, (int) $tags['Staff']->is_restricted]);
        $slugs = $db->table('importer_map')->where('kind', 'tag-slug')->pluck('target_id', 'source_id')->all();
        $this->assertSame(['forum/news-views' => (int) $tags['News & Views']->id, 'forum/staff' => (int) $tags['Staff']->id], array_map('intval', $slugs));

        $this->assertSame('Ada & Co', $this->user('ada@board.example')->nickname);
        $this->assertSame(2038, Carbon::parse($this->user('bob@board.example')->suspended_until)->year);
        $this->assertSame($this->lift, Carbon::parse($this->user('di@board.example')->suspended_until)->getTimestamp());
        $this->assertSame([0, 1], [(int) $this->user('cy@board.example')->is_email_confirmed, (int) $this->user('ada@board.example')->is_email_confirmed]);
    }
}
