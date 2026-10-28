<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use ErnestDefoe\Importer\Importers\DiscourseImporter;
use ErnestDefoe\Importer\Importers\Dst;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;

/**
 * XenForo, Invision and Discourse boards arrive as they were: their forum
 * tree, staff-only forums, locked and unlisted topics, members who were
 * banned or never confirmed — and nothing that was only ever a pointer.
 */
class XfIpsDiscourseFixesTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private string $source;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-suspend', 'ernestdefoe-importer');
        $this->prepareDatabase(['users' => [$this->normalUser()]]);
        $this->source = tempnam(sys_get_temp_dir(), 'xid').'.sqlite';
        $this->pdo = new PDO('sqlite:'.$this->source);
    }

    protected function tearDown(): void
    {
        @unlink($this->source);

        parent::tearDown();
    }

    protected function allowedRepeatedQueries(): array
    {
        // Dst writes one tag / member / discussion / post per source record by
        // design (Dst::tag's slug check, Dst::user's address and name checks,
        // then the insert) — not what this test is about.
        return ['tags" where "slug" = ?', 'tags" ("name", "slug"', 'users" where "email" = ?', 'LOWER(username) = ?', 'users" ("username"', 'discussions" ("title"', 'posts" ("discussion_id", "number"'];
    }

    private function sql(string ...$statements): void
    {
        foreach ($statements as $s) {
            $this->pdo->exec($s);
        }
    }

    private function import(string $source, string $prefix = ''): array
    {
        // flarum/lock is not installed here: give the discussions its column.
        $this->app();
        $schema = $this->database()->getSchemaBuilder();
        if (! $schema->hasColumn('discussions', 'is_locked')) {
            $schema->table('discussions', fn ($t) => $t->boolean('is_locked')->default(0));
        }
        (new \ReflectionProperty(Dst::class, 'tables'))->setValue(null, []);

        $post = function (string $path, array $json) {
            $response = $this->send($this->request('POST', '/api/importer/'.$path, ['authenticatedAs' => 1, 'json' => $json]));
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

            return json_decode((string) $response->getBody(), true);
        };
        $run = $post('start', ['source' => $source, 'config' => ['driver' => 'sqlite', 'database' => $this->source, 'prefix' => $prefix]]);
        for ($i = 0; $i < 80 && ! $run['done'] && ! $run['failed']; $i++) {
            $run = $post('step', ['runId' => $run['runId']]);
        }
        $this->assertTrue($run['done'], json_encode($run));

        return $run;
    }

    /** name => [parent name, restricted] for the run's tags */
    private function tags(): array
    {
        $tags = $this->database()->table('tags')->whereIn('id', $this->database()->table('importer_map')->where('kind', 'tag')->pluck('target_id'))->get()->keyBy('id');
        $out = [];
        foreach ($tags as $t) {
            $out[$t->name] = [$t->parent_id ? $tags[$t->parent_id]->name ?? '?' : null, (bool) $t->is_restricted];
        }
        ksort($out);

        return $out;
    }

    /** email => [confirmed, suspended] */
    private function members(): array
    {
        $out = [];
        foreach ($this->database()->table('users')->where('id', '>', 2)->get() as $u) {
            $out[$u->email] = [(bool) $u->is_email_confirmed, $u->suspended_until ? substr((string) $u->suspended_until, 0, 4) : null];
        }
        ksort($out);

        return $out;
    }

    /** title => [locked, hidden, post count] */
    private function discussions(): array
    {
        $db = $this->database();
        $out = [];
        foreach ($db->table('discussions')->get() as $d) {
            $out[$d->title] = [(bool) $d->is_locked, $d->hidden_at !== null, $db->table('posts')->where('discussion_id', $d->id)->count()];
        }
        ksort($out);

        return $out;
    }

    #[Test]
    public function a_xenforo_board_keeps_its_tree_staff_forums_locks_and_bans()
    {
        $future = time() + 86400 * 30;
        $this->sql(
            'CREATE TABLE xf_node (node_id INTEGER PRIMARY KEY, parent_node_id INTEGER, node_type_id TEXT, title TEXT, description TEXT, display_order INTEGER)',
            'CREATE TABLE xf_permission_entry_content (content_type TEXT, content_id INTEGER, user_group_id INTEGER, user_id INTEGER, permission_group_id TEXT, permission_id TEXT, permission_value TEXT)',
            'CREATE TABLE xf_user (user_id INTEGER PRIMARY KEY, username TEXT, email TEXT, register_date INTEGER, user_state TEXT, is_banned INTEGER)',
            'CREATE TABLE xf_user_ban (user_id INTEGER, end_date INTEGER)',
            'CREATE TABLE xf_thread (thread_id INTEGER PRIMARY KEY, node_id INTEGER, title TEXT, user_id INTEGER, post_date INTEGER, sticky INTEGER, discussion_state TEXT, discussion_open INTEGER, discussion_type TEXT)',
            'CREATE TABLE xf_post (post_id INTEGER PRIMARY KEY, thread_id INTEGER, user_id INTEGER, message TEXT, post_date INTEGER, message_state TEXT, last_edit_date INTEGER)',
            // Main > General > Sub; a forum whose category has the higher id; an empty category.
            "INSERT INTO xf_node VALUES (1, 0, 'Category', 'Main', '', 1), (2, 1, 'Forum', 'General', '', 1), (3, 2, 'Forum', 'Sub', '', 1),
                (4, 0, 'Forum', 'Staff', '', 2), (5, 0, 'Category', 'Empty', '', 3), (6, 0, 'Forum', 'Members only', '', 4),
                (7, 8, 'Forum', 'Early child', '', 1), (8, 0, 'Category', 'Late', '', 5), (9, 4, 'Forum', 'Staff sub', '', 1)",
            "INSERT INTO xf_permission_entry_content VALUES ('node', 4, 1, 0, 'forum', 'view', 'reset'), ('node', 4, 2, 0, 'forum', 'viewContent', 'reset'),
                ('node', 6, 1, 0, 'forum', 'view', 'reset'), ('node', 6, 2, 0, 'forum', 'view', 'unset')",
            "INSERT INTO xf_user VALUES (5, 'Ada', 'ada@x.example', 1600000000, 'valid', 0), (6, 'Pending', 'pending@x.example', 1600000000, 'email_confirm', 0),
                (7, 'Banned', 'banned@x.example', 1600000000, 'valid', 1), (8, 'Temp', 'temp@x.example', 1600000000, 'valid', 1),
                (9, 'Spam', 'spam@x.example', 1600000000, 'rejected', 0), (10, 'Rejected', 'rejected@x.example', 1600000000, 'rejected', 0),
                (11, 'Waiting', 'waiting@x.example', 1600000000, 'moderated', 0)",
            "INSERT INTO xf_user_ban VALUES (7, 0), (8, $future)",
            "INSERT INTO xf_thread VALUES (10, 3, 'Open', 5, 1600001000, 0, 'visible', 1, ''), (11, 2, 'Locked', 5, 1600002000, 0, 'visible', 0, ''),
                (12, 2, 'Moved', 5, 1600003000, 0, 'visible', 1, 'redirect')",
            "INSERT INTO xf_post VALUES (100, 10, 5, 'Hello', 1600001000, 'visible', 0), (101, 10, 10, 'Reply', 1600001100, 'visible', 0), (110, 11, 5, 'Closed', 1600002000, 'visible', 0)",
        );

        $run = $this->import('xenforo');

        $this->assertSame([
            'Early child' => ['Late', false],
            'General' => ['Main', false],
            'Late' => [null, false],
            'Main' => [null, false],
            'Members only' => [null, false],
            'Staff' => [null, true],
            'Staff sub' => ['Staff', true],
            'Sub' => ['Main', false],
        ], $this->tags(), 'Categories that hold forums are parents; deeper forums fold into the root; staff forums restricted');

        $this->assertSame(['Locked' => [true, false, 1], 'Open' => [false, false, 2]], $this->discussions());
        $this->assertSame(2, $run['summary']['topics'], 'The redirect thread is not made at all');

        $this->assertSame([
            'ada@x.example' => [true, null],
            'banned@x.example' => [true, '2038'],
            'pending@x.example' => [false, null],
            'rejected@x.example' => [true, '2038'],
            'temp@x.example' => [true, date('Y', $future)],
            'waiting@x.example' => [false, null],
        ], $this->members(), 'The rejected sign-up with no posts is not brought');
    }

    #[Test]
    public function an_invision_board_keeps_archived_topics_its_tree_staff_forums_and_bans()
    {
        $future = time() + 86400 * 30;
        $this->sql(
            'CREATE TABLE forums_forums (id INTEGER PRIMARY KEY, parent_id INTEGER, name_seo TEXT, position INTEGER, redirect_on INTEGER, redirect_url TEXT, feature_color TEXT, club_id INTEGER)',
            'CREATE TABLE core_permission_index (app TEXT, perm_type TEXT, perm_type_id INTEGER, perm_view TEXT)',
            'CREATE TABLE core_sys_conf_settings (conf_key TEXT, conf_value TEXT)',
            'CREATE TABLE core_members (member_id INTEGER PRIMARY KEY, name TEXT, email TEXT, joined INTEGER, members_pass_hash TEXT, members_pass_salt TEXT, temp_ban INTEGER)',
            'CREATE TABLE core_validating (vid TEXT, member_id INTEGER, lost_pass INTEGER, new_reg INTEGER)',
            'CREATE TABLE forums_topics (tid INTEGER PRIMARY KEY, forum_id INTEGER, title TEXT, starter_id INTEGER, start_date INTEGER, pinned INTEGER, state TEXT, moved_to TEXT, approved INTEGER, topic_archive_status INTEGER)',
            'CREATE TABLE forums_posts (pid INTEGER PRIMARY KEY, topic_id INTEGER, author_id INTEGER, post TEXT, post_date INTEGER, queued INTEGER, pdelete_time INTEGER, edit_time INTEGER)',
            'CREATE TABLE forums_archive_posts (archive_id INTEGER PRIMARY KEY, archive_topic_id INTEGER, archive_author_id INTEGER, archive_content TEXT, archive_content_date INTEGER, archive_queued INTEGER)',
            "INSERT INTO forums_forums VALUES (1, -1, 'main', 1, 0, '', '', NULL), (2, 1, 'general', 1, 0, '', '', NULL), (3, 2, 'sub', 1, 0, '', '', NULL),
                (4, -1, 'staff', 2, 0, '', '', NULL), (5, -1, 'club', 3, 0, '', '', 9), (6, 7, 'early', 1, 0, '', '', NULL), (7, -1, 'late', 4, 0, '', '', NULL)",
            "INSERT INTO core_permission_index VALUES ('forums', 'forum', 1, '*'), ('forums', 'forum', 2, ',2,3,'), ('forums', 'forum', 3, ',3,'), ('forums', 'forum', 4, ',4,6,'), ('forums', 'forum', 5, '*')",
            "INSERT INTO core_sys_conf_settings VALUES ('guest_group', '2'), ('member_group', '3')",
            "INSERT INTO core_members VALUES (5, 'Ada', 'ada@i.example', 1600000000, '', '', 0), (6, 'Banned', 'banned@i.example', 1600000000, '', '', -1),
                (7, 'Temp', 'temp@i.example', 1600000000, '', '', $future), (8, 'Validating', 'validating@i.example', 1600000000, '', '', 0),
                (9, 'Reset', 'reset@i.example', 1600000000, '', '', 0)",
            "INSERT INTO core_validating VALUES ('a', 8, 0, 1), ('b', 9, 1, 0)",
            "INSERT INTO forums_topics VALUES (10, 2, 'Live', 5, 1600001000, 0, 'open', '', 1, 0), (11, 3, 'Archived &amp; old', 5, 1500000000, 0, 'open', '', 1, 2)",
            "INSERT INTO forums_posts VALUES (100, 10, 5, '<p>Hello</p>', 1600001000, 0, 0, 0), (101, 10, 6, '<p>Reply</p>', 1600001100, 0, 0, 0)",
            // Topic 11 lives in the archive; a stray archive row of topic 10 must not renumber it.
            "INSERT INTO forums_archive_posts VALUES (200, 11, 5, '<p>First archived</p>', 1500000000, 0), (201, 11, 6, '<p>Archived reply</p>', 1500000100, 0),
                (202, 11, 6, '<p>Hidden</p>', 1500000200, -1), (203, 10, 6, '<p>Stray</p>', 1600001200, 0)",
        );

        $this->import('invision');

        $this->assertSame([
            'Club' => [null, true],
            'Early' => ['Late', false],
            'General' => ['Main', false],
            'Late' => [null, false],
            'Main' => [null, false],
            'Staff' => [null, true],
            'Sub' => ['Main', false],
        ], $this->tags(), 'Root categories are parents; a forum members can see is not restricted');

        $this->assertSame(['Archived & old' => [false, false, 2], 'Live' => [false, false, 2]], $this->discussions());
        $db = $this->database();
        $archived = $db->table('discussions')->where('title', 'Archived & old')->first();
        $this->assertSame([1, 2], $db->table('posts')->where('discussion_id', $archived->id)->orderBy('number')->pluck('number')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(2, (int) $archived->comment_count, 'The archived discussion is finished like any other');
        $this->assertNotNull($archived->first_post_id);
        $this->assertSame(2, $db->table('importer_map')->where('kind', 'post')->whereIn('source_id', ['200', '201'])->count(), 'Its posts are mapped for the redirects');

        $this->assertSame([
            'ada@i.example' => [true, null],
            'banned@i.example' => [true, '2038'],
            'reset@i.example' => [true, null],
            'temp@i.example' => [true, date('Y', $future)],
            'validating@i.example' => [false, null],
        ], $this->members(), 'A password reset in core_validating is not an unconfirmed address');
    }

    #[Test]
    public function a_discourse_site_keeps_its_categories_locks_unlisted_topics_and_members()
    {
        $future = date('Y-m-d H:i:s', time() + 86400 * 30);
        $this->sql(
            'CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, description TEXT, color TEXT, position INTEGER, parent_category_id INTEGER, read_restricted INTEGER, topic_id INTEGER)',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, name TEXT, email TEXT, created_at TEXT, staged INTEGER, active INTEGER, suspended_till TEXT, password_hash TEXT, salt TEXT, password_algorithm TEXT)',
            'CREATE TABLE topics (id INTEGER PRIMARY KEY, title TEXT, user_id INTEGER, category_id INTEGER, created_at TEXT, pinned_at TEXT, deleted_at TEXT, archetype TEXT, closed INTEGER, archived INTEGER, visible INTEGER)',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, topic_id INTEGER, user_id INTEGER, cooked TEXT, created_at TEXT, deleted_at TEXT, post_type INTEGER, hidden INTEGER)',
            "INSERT INTO categories VALUES (1, 'General', 'general', '', '0088CC', 1, NULL, 0, 50), (2, 'Staff', 'staff', '', '', 2, NULL, 1, NULL),
                (3, 'Child', 'child', '', '', 1, 1, 0, NULL), (4, 'Early', 'early', '', '', 1, 5, 0, NULL), (5, 'Late', 'late', '', '', 3, NULL, 0, NULL)",
            "INSERT INTO users VALUES (5, 'ada', 'Ada', 'ada@d.example', '2020-01-01', 0, 1, NULL, 'h', 's', 'pbkdf2'),
                (6, 'staged', 'Staged', 'staged@d.example', '2020-01-01', 1, 0, NULL, 'h', 's', 'pbkdf2'),
                (7, 'inactive', 'Inactive', 'inactive@d.example', '2020-01-01', 0, 0, NULL, NULL, NULL, NULL),
                (8, 'suspended', 'Suspended', 'suspended@d.example', '2020-01-01', 0, 1, '$future', NULL, NULL, NULL),
                (9, 'served', 'Served', 'served@d.example', '2020-01-01', 0, 1, '2021-01-01 00:00:00', NULL, NULL, NULL)",
            "INSERT INTO topics VALUES (50, 'About the General category', 5, 1, '2020-01-02', NULL, NULL, 'regular', 0, 0, 1),
                (51, 'Open', 5, 3, '2020-01-03', NULL, NULL, 'regular', 0, 0, 1), (52, 'Closed', 5, 1, '2020-01-04', NULL, NULL, 'regular', 1, 0, 1),
                (53, 'Archived', 5, 1, '2020-01-05', NULL, NULL, 'regular', 0, 1, 1), (54, 'Unlisted', 5, 1, '2020-01-06', NULL, NULL, 'regular', 0, 0, 0)",
            "INSERT INTO posts VALUES (500, 50, 5, '<p>About</p>', '2020-01-02', NULL, 1, 0), (510, 51, 5, '<p>Hi</p>', '2020-01-03', NULL, 1, 0),
                (511, 51, 7, '<p>Flagged</p>', '2020-01-03 01:00', NULL, 1, 1), (512, 51, 8, '<p>Reply</p>', '2020-01-03 02:00', NULL, 1, 0),
                (520, 52, 5, '<p>c</p>', '2020-01-04', NULL, 1, 0), (530, 53, 5, '<p>a</p>', '2020-01-05', NULL, 1, 0), (540, 54, 5, '<p>u</p>', '2020-01-06', NULL, 1, 0)",
        );

        $this->import('discourse');

        $this->assertSame(4, DiscourseImporter::test(['driver' => 'sqlite', 'database' => $this->source])['counts']['users'], 'Staged users are not members');

        $this->assertSame([
            'Child' => ['General', false],
            'Early' => ['Late', false],
            'General' => [null, false],
            'Late' => [null, false],
            'Staff' => [null, true],
        ], $this->tags());
        $db = $this->database();
        $slugs = $db->table('importer_map')->where('kind', 'tag-slug')->pluck('target_id', 'source_id')->map(fn ($v) => (int) $v)->all();
        $tag = fn (int $category) => (int) $db->table('importer_map')->where('kind', 'tag')->where('source_id', (string) $category)->value('target_id');
        $this->assertSame($tag(3), $slugs['general/child'] ?? null, '/c/general/child');
        $this->assertSame($tag(3), $slugs['child'] ?? null);
        $this->assertSame($tag(1), $slugs['general'] ?? null);

        $this->assertSame([
            'Archived' => [true, false, 1],
            'Closed' => [true, false, 1],
            'Open' => [false, false, 2],
            'Unlisted' => [false, true, 1],
        ], $this->discussions(), 'Not the category\'s "About" topic; not the post hidden by flags');

        $this->assertSame([
            'ada@d.example' => [true, null],
            'inactive@d.example' => [false, null],
            'served@d.example' => [true, null],
            'staged@d.example' => [false, null],
            'suspended@d.example' => [true, substr($future, 0, 4)],
        ], $this->members());
        $staged = $db->table('users')->where('email', 'staged@d.example')->value('id');
        $this->assertFalse($db->table('importer_passwords')->where('user_id', $staged)->exists(), 'A staged user never had a password');
        $this->assertTrue($db->table('importer_passwords')->where('user_id', $db->table('users')->where('email', 'ada@d.example')->value('id'))->exists());
    }
}
