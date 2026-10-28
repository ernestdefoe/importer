<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

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
}
