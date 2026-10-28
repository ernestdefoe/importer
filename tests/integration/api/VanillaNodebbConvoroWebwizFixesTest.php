<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;

/**
 * Convoro 1/2, Web Wiz, Vanilla and NodeBB: what the old board hid stays
 * hidden, who it banned stays banned, its private forums stay private, and its
 * old addresses can be redirected.
 */
class VanillaNodebbConvoroWebwizFixesTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const REDIS_DB = 14;

    private string $source;

    private ?\Redis $redis = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDatabase(['users' => [$this->normalUser()]]);
        $this->source = tempnam(sys_get_temp_dir(), 'vncw').'.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->source);
        $this->redis?->flushDB();

        parent::tearDown();
    }

    /**
     * Dst::user() checks each member's address and username before writing
     * them: one member, one insert and its checks, the job rather than an N+1.
     */
    protected function allowedRepeatedQueries(): array
    {
        return ['users" where "email" = ?', 'users` where `email` = ?', 'where LOWER(username) = ?'];
    }

    private function post(string $path, array $json): array
    {
        $response = $this->send($this->request('POST', '/api/importer/'.$path, ['authenticatedAs' => 1, 'json' => $json]));
        $this->assertContains($response->getStatusCode(), [200, 422], (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function import(string $source, array $config): array
    {
        $run = $this->post('start', ['source' => $source, 'config' => $config]);
        for ($i = 0; $i < 80 && ($run['running'] ?? false); $i++) {
            $run = $this->post('step', ['runId' => $run['runId']]);
        }
        $this->assertTrue($run['done'] ?? false, json_encode($run));

        return $run;
    }

    private function sqlite(array $sql): array
    {
        $pdo = new PDO('sqlite:'.$this->source);
        foreach ($sql as $q) {
            $pdo->exec($q);
        }

        return ['driver' => 'sqlite', 'database' => $this->source];
    }

    private function titles(): array
    {
        return $this->database()->table('discussions')->orderBy('id')->pluck('title')->all();
    }

    /** source id => target id of one kind of the import's map. */
    private function map(string $kind): array
    {
        return $this->database()->table('importer_map')->where('kind', $kind)->pluck('target_id', 'source_id')->map(fn ($v) => (int) $v)->all();
    }

    private function user(string $email): ?object
    {
        return $this->database()->table('users')->where('email', $email)->first();
    }

    private function restricted(string $name): int
    {
        return (int) $this->database()->table('tags')->where('name', $name)->value('is_restricted');
    }

    /** The texts of a discussion's posts, in order. */
    private function texts(string $title): array
    {
        $db = $this->database();

        return $db->table('posts')->where('discussion_id', $db->table('discussions')->where('title', $title)->value('id'))
            ->orderBy('number')->pluck('content')->map(fn ($c) => trim(strip_tags((string) $c)))->all();
    }

    /* ── Convoro ──────────────────────────────────────────────────────── */

    #[Test]
    public function convoro_2_keeps_what_it_hid_hidden_its_bans_and_its_private_forums()
    {
        $this->extension('flarum-tags', 'flarum-suspend', 'ernestdefoe-importer');
        $cfg = $this->sqlite([
            'CREATE TABLE groups (id INTEGER PRIMARY KEY, name TEXT, is_default INTEGER, is_guest INTEGER)',
            'CREATE TABLE forums (id INTEGER PRIMARY KEY, parent_id INTEGER, title TEXT, slug TEXT, description TEXT, position INTEGER)',
            'CREATE TABLE forum_groups (id INTEGER PRIMARY KEY, forum_id INTEGER, group_id INTEGER)',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, password TEXT, created_at TEXT, is_banned INTEGER, ban_expires_at TEXT, email_verified_at TEXT, deleted_at TEXT)',
            'CREATE TABLE topics (id INTEGER PRIMARY KEY, forum_id INTEGER, user_id INTEGER, title TEXT, is_pinned INTEGER, is_locked INTEGER, is_hidden INTEGER, created_at TEXT, deleted_at TEXT)',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, topic_id INTEGER, user_id INTEGER, content_html TEXT, is_hidden INTEGER, created_at TEXT, deleted_at TEXT)',
            "INSERT INTO groups VALUES (1, 'Administrators', 0, 0), (2, 'Moderators', 0, 0), (3, 'Members', 1, 0), (4, 'Guests', 0, 1)",
            "INSERT INTO forums VALUES (1, NULL, 'General', 'general', '', 1), (2, NULL, 'Staff', 'staff', '', 2), (3, 2, 'Staff notes', 'staff-notes', '', 3), (4, NULL, 'Members lounge', 'lounge', '', 4)",
            'INSERT INTO forum_groups VALUES (1, 2, 1), (2, 2, 2), (3, 4, 3)',
            "INSERT INTO users VALUES (5, 'Ada', 'ada@c2.example', '', '2026-01-01 10:00:00', 0, NULL, '2026-01-01 10:00:00', NULL),"
                ." (6, 'Bob', 'bob@c2.example', '', '2026-01-01 10:00:00', 1, NULL, '2026-01-01 10:00:00', NULL),"
                ." (7, 'Cy', 'cy@c2.example', '', '2026-01-01 10:00:00', 1, '2099-01-01 00:00:00', '2026-01-01 10:00:00', NULL),"
                ." (8, 'Dee', 'dee@c2.example', '', '2026-01-01 10:00:00', 0, NULL, NULL, NULL),"
                ." (9, 'Gone', 'gone@c2.example', '', '2026-01-01 10:00:00', 0, NULL, '2026-01-01 10:00:00', '2026-02-01 10:00:00')",
            "INSERT INTO topics VALUES (10, 1, 5, 'Live', 0, 0, 0, '2026-03-01 10:00:00', NULL), (11, 1, 5, 'Deleted topic', 0, 0, 0, '2026-03-02 10:00:00', '2026-03-03 10:00:00'),"
                ." (12, 1, 5, 'Held topic', 0, 0, 1, '2026-03-04 10:00:00', NULL), (13, 2, 5, 'Staff topic', 0, 0, 0, '2026-03-05 10:00:00', NULL)",
            "INSERT INTO posts VALUES (100, 10, 5, '<p>Hello</p>', 0, '2026-03-01 10:00:00', NULL), (101, 10, 6, '<p>Deleted reply</p>', 0, '2026-03-01 11:00:00', '2026-03-01 12:00:00'),"
                ." (102, 10, 6, '<p>Held reply</p>', 1, '2026-03-01 11:30:00', NULL), (103, 10, 6, '<p>Visible reply</p>', 0, '2026-03-01 13:00:00', NULL),"
                ." (110, 11, 5, '<p>In a deleted topic</p>', 0, '2026-03-02 10:00:00', NULL), (120, 12, 5, '<p>In a held topic</p>', 0, '2026-03-04 10:00:00', NULL),"
                ." (130, 13, 5, '<p>Staff only</p>', 0, '2026-03-05 10:00:00', NULL)",
        ]);

        $this->import('convoro', $cfg + ['prefix' => '']);

        $this->assertSame(['Live', 'Staff topic'], $this->titles(), 'Deleted and held topics stay behind');
        $this->assertSame(['Hello', 'Visible reply'], $this->texts('Live'), 'Deleted and held replies stay behind');

        $this->assertSame(0, $this->restricted('General'));
        $this->assertSame(1, $this->restricted('Staff'), 'Only staff groups could read it');
        $this->assertSame(1, $this->restricted('Staff notes'), 'A sub-forum inherits its parent\'s restriction');
        $this->assertSame(0, $this->restricted('Members lounge'), 'Restricted to the Members group: every member could read it');

        $tags = $this->map('tag');
        $this->assertSame($tags['1'], $this->map('tag-slug')['general'] ?? null, 'The old /forum/general address can be redirected');

        $this->assertNull($this->user('ada@c2.example')->suspended_until);
        $this->assertSame('2038', substr((string) $this->user('bob@c2.example')->suspended_until, 0, 4), 'A ban with no end is for good');
        $this->assertSame('2099', substr((string) $this->user('cy@c2.example')->suspended_until, 0, 4), 'A ban ends when it ended there');
        $this->assertSame(1, (int) $this->user('ada@c2.example')->is_email_confirmed);
        $this->assertSame(0, (int) $this->user('dee@c2.example')->is_email_confirmed, 'Never verified there');
        $this->assertNull($this->user('gone@c2.example'), 'A deleted member is not restored, on an uploaded (SQLite) dump too');
    }

    #[Test]
    public function convoro_1_keeps_hidden_posts_bans_and_private_social_groups_behind()
    {
        $this->extension('flarum-tags', 'flarum-suspend', 'ernestdefoe-importer');
        $cfg = $this->sqlite([
            'CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, description TEXT, color TEXT, position INTEGER, parent_id INTEGER)',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, password TEXT, created_at TEXT, email_verified_at TEXT, banned_at TEXT)',
            'CREATE TABLE social_groups (id INTEGER PRIMARY KEY, name TEXT, is_private INTEGER)',
            'CREATE TABLE topics (id INTEGER PRIMARY KEY, title TEXT, user_id INTEGER, category_id INTEGER, group_id INTEGER, is_pinned INTEGER, is_locked INTEGER, hidden INTEGER, created_at TEXT)',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, topic_id INTEGER, user_id INTEGER, body_html TEXT, hidden INTEGER, created_at TEXT)',
            "INSERT INTO categories VALUES (1, 'General', 'general', '', '#5b5bd6', 0, NULL)",
            "INSERT INTO users VALUES (5, 'Ada', 'ada@c1.example', '', '2026-01-01 10:00:00', NULL, NULL), (6, 'Bob', 'bob@c1.example', '', '2026-01-01 10:00:00', NULL, '2026-02-01 10:00:00')",
            "INSERT INTO social_groups VALUES (1, 'Open club', 0), (2, 'Secret club', 1)",
            "INSERT INTO topics VALUES (10, 'Live', 5, 1, NULL, 0, 0, 0, '2026-03-01 10:00:00'), (11, 'Held', 5, 1, NULL, 0, 0, 1, '2026-03-02 10:00:00'),"
                ." (12, 'Open club topic', 5, NULL, 1, 0, 0, 0, '2026-03-03 10:00:00'), (13, 'Secret club topic', 5, NULL, 2, 0, 0, 0, '2026-03-04 10:00:00')",
            "INSERT INTO posts VALUES (100, 10, 5, '<p>Hello</p>', 0, '2026-03-01 10:00:00'), (101, 10, 6, '<p>Held reply</p>', 1, '2026-03-01 11:00:00'),"
                ." (110, 11, 5, '<p>Held topic body</p>', 0, '2026-03-02 10:00:00'), (120, 12, 5, '<p>Open</p>', 0, '2026-03-03 10:00:00'), (130, 13, 5, '<p>Secret</p>', 0, '2026-03-04 10:00:00')",
        ]);

        $this->import('convoro', $cfg + ['prefix' => '']);

        $this->assertSame(['Live', 'Open club topic'], $this->titles(), 'Held topics and a private group\'s topics stay behind');
        $this->assertSame(['Hello'], $this->texts('Live'));
        $this->assertSame('2038', substr((string) $this->user('bob@c1.example')->suspended_until, 0, 4));
        $this->assertSame(1, (int) $this->user('ada@c1.example')->is_email_confirmed, 'Convoro 1 never verified anybody: null means nothing there');
        $this->assertSame($this->map('tag')['1'], $this->map('tag-slug')['general'] ?? null);
    }

    /* ── Web Wiz ──────────────────────────────────────────────────────── */

    #[Test]
    public function web_wiz_keeps_locks_moved_topics_and_hides_what_it_held_even_without_date_columns()
    {
        $this->extension('flarum-tags', 'ernestdefoe-importer');
        // flarum/lock is not installed here: its column is enough for the importer.
        $this->database()->getSchemaBuilder()->table('discussions', fn ($t) => $t->boolean('is_locked')->default(false));
        // No Join_date, Start_date or Message_date: an install that names them some other way.
        $cfg = $this->sqlite([
            'CREATE TABLE tblForum (Forum_ID INTEGER PRIMARY KEY, Forum_name TEXT)',
            'CREATE TABLE tblAuthor (Author_ID INTEGER PRIMARY KEY, Username TEXT, Author_email TEXT)',
            'CREATE TABLE tblTopic (Topic_ID INTEGER PRIMARY KEY, Forum_ID INTEGER, Subject TEXT, Author_ID INTEGER, Locked INTEGER, Priority INTEGER, Moved_ID INTEGER, Hide INTEGER)',
            'CREATE TABLE tblThread (Thread_ID INTEGER PRIMARY KEY, Topic_ID INTEGER, Author_ID INTEGER, Message TEXT, Hide INTEGER)',
            "INSERT INTO tblForum VALUES (1, 'General'), (2, 'Old home')",
            "INSERT INTO tblAuthor VALUES (5, 'Ada', 'ada@ww.example')",
            "INSERT INTO tblTopic VALUES (10, 1, 'Locked one', 5, 1, 0, 0, 0), (11, 1, 'Moved here', 5, 0, 0, 2, 0), (12, 1, 'Held topic', 5, 0, 0, 0, 1)",
            "INSERT INTO tblThread VALUES (100, 10, 5, 'Hello', 0), (101, 10, 5, 'Held reply', 1), (110, 11, 5, 'Moved body', 0), (120, 12, 5, 'Held body', 0)",
        ]);

        $this->import('webwiz', $cfg + ['prefix' => 'tbl']);

        $this->assertNotNull($this->user('ada@ww.example'), 'A member is not dropped for want of a join date');
        $this->assertSame(['Locked one', 'Moved here'], $this->titles(), 'A moved topic is the topic itself; a held one stays behind');
        $this->assertSame(['Hello'], $this->texts('Locked one'), 'A held reply stays behind');
        $this->assertSame(['Moved body'], $this->texts('Moved here'));
        $locked = $this->database()->table('discussions')->orderBy('id')->pluck('is_locked')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([1, 0], $locked);
    }

    /* ── Vanilla ──────────────────────────────────────────────────────── */

    #[Test]
    public function vanilla_finishes_reply_less_discussions_keeps_bans_private_categories_and_maps_comments()
    {
        $this->extension('flarum-tags', 'flarum-suspend', 'ernestdefoe-importer');
        $cfg = $this->sqlite([
            'CREATE TABLE GDN_User (UserID INTEGER PRIMARY KEY, Name TEXT, Email TEXT, Password TEXT, DateInserted TEXT, Deleted INTEGER, Banned INTEGER, Confirmed INTEGER)',
            'CREATE TABLE GDN_Role (RoleID INTEGER PRIMARY KEY, Name TEXT, Type TEXT)',
            'CREATE TABLE GDN_Permission (PermissionID INTEGER PRIMARY KEY, RoleID INTEGER, JunctionTable TEXT, JunctionColumn TEXT, JunctionID INTEGER, "Vanilla.Discussions.View" INTEGER)',
            'CREATE TABLE GDN_Category (CategoryID INTEGER PRIMARY KEY, Name TEXT, UrlCode TEXT, Description TEXT, Sort INTEGER, PermissionCategoryID INTEGER)',
            'CREATE TABLE GDN_Discussion (DiscussionID INTEGER PRIMARY KEY, CategoryID INTEGER, Name TEXT, Body TEXT, Format TEXT, InsertUserID INTEGER, DateInserted TEXT, Announce INTEGER, Closed INTEGER)',
            'CREATE TABLE GDN_Comment (CommentID INTEGER PRIMARY KEY, DiscussionID INTEGER, Body TEXT, Format TEXT, InsertUserID INTEGER, DateInserted TEXT)',
            "INSERT INTO GDN_Role VALUES (2, 'Guest', 'guest'), (8, 'Member', 'member'), (16, 'Administrator', 'administrator'), (32, 'Moderator', 'moderator')",
            "INSERT INTO GDN_Category VALUES (-1, 'Root', 'root', '', 0, -1), (1, 'General', 'general-talk', '', 1, -1), (2, 'Staff', 'staff-room', '', 2, 2), (3, 'Staff archive', 'staff-archive', '', 3, 2), (4, 'Open custom', 'open-custom', '', 4, 4)",
            "INSERT INTO GDN_Permission VALUES (1, 32, 'Category', 'PermissionCategoryID', 2, 1), (2, 8, 'Category', 'PermissionCategoryID', 2, 0), (3, 8, 'Category', 'PermissionCategoryID', 4, 1)",
            "INSERT INTO GDN_User VALUES (5, 'Ada', 'ada@va.example', '', '2010-01-01 00:00:00', 0, 0, 1), (6, 'Bob', 'bob@va.example', '', '2010-01-01 00:00:00', 0, 1, 1),"
                ." (7, 'Cy', 'cy@va.example', '', '2010-01-01 00:00:00', 0, 0, 0), (8, 'Gone', 'gone@va.example', '', '2010-01-01 00:00:00', 1, 0, 1),"
                ." (9, 'Spam', 'spam@va.example', '', '2010-01-01 00:00:00', 1, 0, 1)",
            "INSERT INTO GDN_Discussion VALUES (1, 1, 'Has replies', 'First', 'Html', 5, '2012-01-01 00:00:00', 0, 0), (2, 1, 'No replies', 'Lonely', 'Html', 5, '2012-01-03 00:00:00', 0, 0)",
            "INSERT INTO GDN_Comment VALUES (50, 1, 'A reply', 'Html', 8, '2012-01-02 00:00:00')",
        ]);

        $this->import('vanilla', $cfg + ['prefix' => 'GDN_']);
        $db = $this->database();

        $lonely = $db->table('discussions')->where('title', 'No replies')->first();
        $this->assertSame(1, (int) $lonely->comment_count, 'A discussion with no comments is finished too');
        $this->assertNotNull($lonely->first_post_id);
        $this->assertSame((int) $lonely->first_post_id, (int) $lonely->last_post_id);
        $replied = $db->table('discussions')->where('title', 'Has replies')->first();
        $this->assertSame(2, (int) $replied->comment_count);

        $this->assertSame((int) $db->table('posts')->where('discussion_id', $replied->id)->where('number', 2)->value('id'), $this->map('post')['50'] ?? null, 'A comment\'s old address can be redirected');
        $this->assertSame($this->map('tag')['1'], $this->map('tag-slug')['general-talk'] ?? null, '/categories/general-talk');

        $this->assertSame(0, $this->restricted('General'));
        $this->assertSame(1, $this->restricted('Staff'), 'Custom permissions: moderators only');
        $this->assertSame(1, $this->restricted('Staff archive'), 'Takes its permissions from Staff');
        $this->assertSame(0, $this->restricted('Open custom'), 'Custom permissions members can view');

        $this->assertNull($this->user('ada@va.example')->suspended_until);
        $this->assertSame('2038', substr((string) $this->user('bob@va.example')->suspended_until, 0, 4), 'Banned there');
        $this->assertSame(0, (int) $this->user('cy@va.example')->is_email_confirmed, 'Never confirmed there');
        $this->assertSame(1, (int) $this->user('ada@va.example')->is_email_confirmed);
        $this->assertNotNull($gone = $this->user('gone@va.example'), 'A deleted member who wrote something keeps their posts\' authorship');
        $this->assertSame('2038', substr((string) $gone->suspended_until, 0, 4), 'and cannot sign in');
        $this->assertNull($this->user('spam@va.example'), 'A deleted member with no posts stays behind');
    }
}
