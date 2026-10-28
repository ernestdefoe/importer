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
}
