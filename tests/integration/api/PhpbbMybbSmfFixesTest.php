<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use ErnestDefoe\Importer\Importers\Dst;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Illuminate\Database\Schema\Blueprint;
use PDO;
use PHPUnit\Framework\Attributes\Test;

/**
 * phpBB, MyBB and SMF boards as they really are: moved-topic stubs, locked
 * topics, inactive and banned members, staff forums, sub-forums, categories,
 * the SMF recycle bin, an SMF 2.0 schema, and names stored HTML-escaped.
 */
class PhpbbMybbSmfFixesTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-suspend', 'flarum-nicknames', 'ernestdefoe-importer');
        $this->prepareDatabase(['users' => [$this->normalUser()]]);
        $this->source = tempnam(sys_get_temp_dir(), 'board').'.sqlite';
    }

    protected function allowedRepeatedQueries(): array
    {
        // Dst::user() and Dst::tag() write one row per source record by design
        // (the address, name and slug checks are what keep them unique).
        return ['LOWER(username)', 'importer_passwords', 'where "email" = ?', 'where `email` = ?', 'where "slug" = ?', 'where `slug` = ?', 'tags" ("name", "slug"', 'tags` (`name`, `slug`'];
    }

    protected function tearDown(): void
    {
        @unlink($this->source);

        parent::tearDown();
    }

    private function import(string $source, string $prefix): array
    {
        // flarum/lock + flarum/sticky are not installed here: their columns are enough.
        $this->database()->getSchemaBuilder()->table('discussions', function (Blueprint $t) {
            $t->boolean('is_locked')->default(false);
            $t->boolean('is_sticky')->default(false);
        });
        // Dst caches which columns exist for the whole process.
        (new \ReflectionClass(Dst::class))->setStaticPropertyValue('tables', []);

        $post = fn (string $path, array $json) => json_decode((string) $this->send($this->request('POST', "/api/importer/$path", ['authenticatedAs' => 1, 'json' => $json]))->getBody(), true);
        $run = $post('start', ['source' => $source, 'config' => ['driver' => 'sqlite', 'database' => $this->source, 'prefix' => $prefix]]);
        for ($i = 0; $i < 60 && ! $run['done'] && ! $run['failed']; $i++) {
            $run = $post('step', ['runId' => $run['runId']]);
        }
        $this->assertTrue($run['done'], json_encode($run));
        $this->assertFalse($run['failed'], json_encode($run));

        return $run;
    }

    private function tag(string $name): ?object
    {
        return $this->database()->table('tags')->where('name', $name)->first();
    }

    private function user(string $email): object
    {
        return $this->database()->table('users')->where('email', $email)->first();
    }

    private function discussion(string $title): ?object
    {
        return $this->database()->table('discussions')->where('title', $title)->first();
    }

    private function assertBoard(array $run, string $category, string $parentOfChild, string $grandchild): void
    {
        // Sub-forums keep their parent; a third level goes under the top one.
        $top = $this->tag($category);
        $this->assertNotNull($top, 'The category is the parent tag');
        $this->assertNull($top->parent_id);
        $this->assertSame((int) $top->id, (int) $this->tag($parentOfChild)->parent_id);
        $this->assertSame((int) $top->id, (int) $this->tag($grandchild)->parent_id, 'Flarum has two levels');

        $this->assertSame(1, (int) $this->tag('Staff')->is_restricted, 'Neither guests nor members could read it');
        $this->assertSame(0, (int) $top->is_restricted);
        $this->assertSame(0, (int) $this->tag($grandchild)->is_restricted);

        $this->assertSame(0, $run['summary']['empty_removed'] ?? 0, 'Moved-topic stubs are not imported at all');
    }

    private function assertMembers(): void
    {
        $this->assertSame(1, (int) $this->user('ada@board.example')->is_email_confirmed);
        $this->assertNull($this->user('ada@board.example')->suspended_until);
        $this->assertSame(0, (int) $this->user('pending@board.example')->is_email_confirmed, 'Never activated');
        $this->assertSame(2038, (int) substr((string) $this->user('banned@board.example')->suspended_until, 0, 4), 'Banned for ever');
        $this->assertSame(2032, (int) substr((string) $this->user('temp@board.example')->suspended_until, 0, 4), 'Banned until the ban ends');
    }

    #[Test]
    public function a_phpbb_board_keeps_its_structure_locks_and_bans()
    {
        $pdo = new PDO('sqlite:'.$this->source);
        $pdo->exec('CREATE TABLE phpbb_forums (forum_id INTEGER PRIMARY KEY, parent_id INTEGER, forum_name TEXT, forum_desc TEXT, forum_type INTEGER, left_id INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_groups (group_id INTEGER PRIMARY KEY, group_name TEXT, group_type INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_acl_options (auth_option_id INTEGER PRIMARY KEY, auth_option TEXT)');
        $pdo->exec('CREATE TABLE phpbb_acl_roles_data (role_id INTEGER, auth_option_id INTEGER, auth_setting INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_acl_groups (group_id INTEGER, forum_id INTEGER, auth_option_id INTEGER, auth_role_id INTEGER, auth_setting INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_users (user_id INTEGER PRIMARY KEY, username TEXT, user_email TEXT, user_password TEXT, user_regdate INTEGER, user_type INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_banlist (ban_id INTEGER PRIMARY KEY, ban_userid INTEGER, ban_end INTEGER, ban_exclude INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_topics (topic_id INTEGER PRIMARY KEY, forum_id INTEGER, topic_title TEXT, topic_poster INTEGER, topic_time INTEGER, topic_type INTEGER, topic_visibility INTEGER, topic_status INTEGER, topic_moved_id INTEGER)');
        $pdo->exec('CREATE TABLE phpbb_posts (post_id INTEGER PRIMARY KEY, topic_id INTEGER, poster_id INTEGER, post_text TEXT, bbcode_uid TEXT, post_time INTEGER, post_visibility INTEGER)');
        // 7 sits in category 10, which has a higher id than it.
        $pdo->exec("INSERT INTO phpbb_forums VALUES (1, 0, 'Cat &amp; Co', '', 0, 1), (2, 1, 'Chat', '', 1, 2), (3, 2, 'Sub', '', 1, 3), (4, 0, 'Staff', '', 1, 5), (5, 0, 'Lonely', '', 0, 7), (7, 10, 'Late child', '', 1, 9), (10, 0, 'Later', '', 0, 8)");
        $pdo->exec("INSERT INTO phpbb_groups VALUES (1, 'GUESTS', 3), (2, 'REGISTERED', 3), (5, 'ADMINISTRATORS', 3)");
        $pdo->exec("INSERT INTO phpbb_acl_options VALUES (10, 'f_list'), (11, 'f_read')");
        $pdo->exec('INSERT INTO phpbb_acl_roles_data VALUES (15, 10, 1), (15, 11, 1), (16, 10, 1)');
        // Guests read Chat through a role, members read Sub directly; Staff is admins only; Later can only be listed.
        $pdo->exec('INSERT INTO phpbb_acl_groups VALUES (1, 2, 0, 15, 0), (2, 3, 11, 0, 1), (2, 1, 0, 15, 0), (5, 4, 0, 15, 0), (2, 7, 0, 15, 0), (2, 10, 0, 16, 0)');
        $pdo->exec("INSERT INTO phpbb_users VALUES (5, 'Ada', 'ada@board.example', '', 1600000000, 0), (6, 'Pending', 'pending@board.example', '', 1600000000, 1), (7, 'Banned', 'banned@board.example', '', 1600000000, 0), (8, 'Temp', 'temp@board.example', '', 1600000000, 0), (9, 'Tom &amp; Jerry', 'tom@board.example', '', 1600000000, 0)");
        $pdo->exec('INSERT INTO phpbb_banlist VALUES (1, 7, 0, 0), (2, 8, 1956528000, 0), (3, 5, 0, 1), (4, 0, 0, 0)');
        $pdo->exec("INSERT INTO phpbb_topics VALUES (10, 2, 'Fish &amp; chips', 5, 1600001000, 0, 1, 1, 0), (11, 3, 'Fish &amp; chips', 5, 1600001000, 0, 1, 2, 10), (12, 3, 'Pinned', 5, 1600002000, 1, 1, 0, 0)");
        $pdo->exec("INSERT INTO phpbb_posts VALUES (100, 10, 5, 'Hello', '', 1600001000, 1), (101, 12, 5, 'Read me', '', 1600002000, 1)");

        $run = $this->import('phpbb', 'phpbb_');

        $this->assertBoard($run, 'Cat & Co', 'Chat', 'Sub');
        $this->assertNull($this->tag('Lonely'), 'A category with no forum in it');
        $this->assertSame((int) $this->tag('Later')->id, (int) $this->tag('Late child')->parent_id, 'A parent with a higher id');
        $this->assertSame(1, (int) $this->tag('Later')->is_restricted, 'f_list alone is not f_read');
        $this->assertMembers();
        $this->assertSame('Tom & Jerry', $this->user('tom@board.example')->nickname);

        $this->assertSame(1, $this->database()->table('discussions')->where('title', 'Fish & chips')->count(), 'The shadow left by a move is not a second copy');
        $this->assertSame(1, (int) $this->discussion('Fish & chips')->is_locked);
        $this->assertSame([0, 1], [(int) $this->discussion('Pinned')->is_locked, (int) $this->discussion('Pinned')->is_sticky]);
    }

    #[Test]
    public function a_mybb_board_keeps_its_structure_locks_and_bans()
    {
        $pdo = new PDO('sqlite:'.$this->source);
        $pdo->exec('CREATE TABLE mybb_forums (fid INTEGER PRIMARY KEY, name TEXT, description TEXT, type TEXT, pid INTEGER, parentlist TEXT, disporder INTEGER, linkto TEXT)');
        $pdo->exec('CREATE TABLE mybb_forumpermissions (pid INTEGER PRIMARY KEY, fid INTEGER, gid INTEGER, canview INTEGER)');
        $pdo->exec('CREATE TABLE mybb_users (uid INTEGER PRIMARY KEY, username TEXT, email TEXT, password TEXT, salt TEXT, regdate INTEGER, usergroup INTEGER)');
        $pdo->exec('CREATE TABLE mybb_banned (uid INTEGER, lifted INTEGER)');
        $pdo->exec('CREATE TABLE mybb_threads (tid INTEGER PRIMARY KEY, fid INTEGER, subject TEXT, uid INTEGER, dateline INTEGER, sticky INTEGER, visible INTEGER, closed TEXT)');
        $pdo->exec('CREATE TABLE mybb_posts (pid INTEGER PRIMARY KEY, tid INTEGER, uid INTEGER, message TEXT, dateline INTEGER, visible INTEGER)');
        $pdo->exec("INSERT INTO mybb_forums VALUES (1, 'Main', '', 'c', 0, '1', 1, ''), (2, 'Chat', '', 'f', 1, '1,2', 1, ''), (3, 'Sub', '', 'f', 2, '1,2,3', 1, ''), (4, 'Staff', '', 'f', 1, '1,4', 2, ''), (5, 'Elsewhere', '', 'f', 1, '1,5', 3, 'https://example.com'), (6, 'Empty', '', 'c', 0, '6', 2, ''), (7, 'Staff room', '', 'f', 4, '1,4,7', 1, '')");
        // Sub is closed to guests only; Staff room has no rows and inherits Staff's.
        $pdo->exec('INSERT INTO mybb_forumpermissions VALUES (1, 4, 1, 0), (2, 4, 2, 0), (3, 3, 1, 0)');
        $pdo->exec("INSERT INTO mybb_users VALUES (5, 'Ada', 'ada@board.example', 'x', 's', 1600000000, 2), (6, 'Pending', 'pending@board.example', 'x', 's', 1600000000, 5), (7, 'Banned', 'banned@board.example', 'x', 's', 1600000000, 7), (8, 'Temp', 'temp@board.example', 'x', 's', 1600000000, 7), (9, 'Rowless', 'rowless@board.example', 'x', 's', 1600000000, 7)");
        $pdo->exec('INSERT INTO mybb_banned VALUES (7, 0), (8, 1956528000)');
        $pdo->exec("INSERT INTO mybb_threads VALUES (10, 2, 'Closed', 5, 1600001000, 0, 1, '1'), (11, 3, 'Closed', 5, 1600001000, 0, 1, 'moved|10'), (12, 3, 'Pinned', 5, 1600002000, 1, 1, '')");
        $pdo->exec("INSERT INTO mybb_posts VALUES (100, 10, 5, 'Hello', 1600001000, 1), (101, 12, 5, 'Read me', 1600002000, 1)");

        $run = $this->import('mybb', 'mybb_');

        $this->assertBoard($run, 'Main', 'Chat', 'Sub');
        $this->assertSame(1, (int) $this->tag('Staff room')->is_restricted, 'Inherited from its parent');
        $this->assertNull($this->tag('Empty'));
        $this->assertNull($this->tag('Elsewhere'), 'A link forum');
        $this->assertMembers();
        $this->assertSame(2038, (int) substr((string) $this->user('rowless@board.example')->suspended_until, 0, 4), 'In the Banned group with no ban row');

        $this->assertSame(1, $this->database()->table('discussions')->where('title', 'Closed')->count(), 'Not the redirect');
        $this->assertSame(1, (int) $this->discussion('Closed')->is_locked);
        $this->assertSame([0, 1], [(int) $this->discussion('Pinned')->is_locked, (int) $this->discussion('Pinned')->is_sticky]);
    }

    private function smf(bool $v21): void
    {
        $pdo = new PDO('sqlite:'.$this->source);
        $pdo->exec('CREATE TABLE smf_boards (id_board INTEGER PRIMARY KEY, id_cat INTEGER, id_parent INTEGER, name TEXT, description TEXT, board_order INTEGER, redirect TEXT, member_groups TEXT)');
        $pdo->exec('CREATE TABLE smf_settings (variable TEXT PRIMARY KEY, value TEXT)');
        $pdo->exec('CREATE TABLE smf_topics (id_topic INTEGER PRIMARY KEY, id_board INTEGER, id_first_msg INTEGER, id_member_started INTEGER, is_sticky INTEGER, locked INTEGER, approved INTEGER'.($v21 ? ', id_redirect_topic INTEGER' : '').')');
        $pdo->exec('CREATE TABLE smf_messages (id_msg INTEGER PRIMARY KEY, id_topic INTEGER, id_board INTEGER, id_member INTEGER, subject TEXT, body TEXT, poster_time INTEGER, approved INTEGER)');
        $pdo->exec('CREATE TABLE smf_members (id_member INTEGER PRIMARY KEY, member_name TEXT, real_name TEXT, email_address TEXT, passwd TEXT, date_registered INTEGER, is_activated INTEGER)');
        $pdo->exec('CREATE TABLE smf_ban_groups (id_ban_group INTEGER PRIMARY KEY, expire_time INTEGER)');
        $pdo->exec('CREATE TABLE smf_ban_items (id_ban_item INTEGER PRIMARY KEY, id_ban_group INTEGER, id_member INTEGER)');
        $pdo->exec("INSERT INTO smf_boards VALUES (1, 1, 0, 'News &amp; Views', '', 1, '', '-1,0'), (2, 1, 1, 'Child', '', 2, '', '-1,0'), (3, 1, 2, 'Grandchild', '', 3, '', '0'), (4, 1, 0, 'Staff', '', 4, '', '1,2'), (5, 1, 0, 'Recycle Bin', '', 5, '', '-1,0')");
        $pdo->exec("INSERT INTO smf_settings VALUES ('recycle_enable', '1'), ('recycle_board', '5')");
        $redirect = $v21 ? ', 0' : '';
        $pdo->exec("INSERT INTO smf_topics VALUES (10, 1, 100, 5, 0, 1, 1$redirect), (11, 5, 101, 5, 0, 0, 1$redirect), (12, 3, 102, 5, 1, 0, 1$redirect)".($v21 ? ', (13, 2, 103, 5, 0, 0, 1, 10)' : ''));
        $pdo->exec("INSERT INTO smf_messages VALUES (100, 10, 1, 5, 'Fish &amp; chips', 'Hello', 1600001000, 1), (101, 11, 5, 5, 'Binned', 'Gone', 1600001500, 1), (102, 12, 3, 5, 'Pinned', 'Read me', 1600002000, 1), (103, 13, 2, 5, 'MOVED: Fish', 'Moved', 1600003000, 1)");
        $pdo->exec("INSERT INTO smf_members VALUES (5, 'ada', 'Ada', 'ada@board.example', 'x', 1600000000, 1), (6, 'pending', 'Pending', 'pending@board.example', 'x', 1600000000, 3), (7, 'banned', 'Banned', 'banned@board.example', 'x', 1600000000, 11), (8, 'temp', 'Temp', 'temp@board.example', 'x', 1600000000, 1), (9, 'never', 'Never', 'never@board.example', 'x', 1600000000, 0)");
        $pdo->exec('INSERT INTO smf_ban_groups VALUES (1, 1956528000)');
        $pdo->exec('INSERT INTO smf_ban_items VALUES (1, 1, 8)');
    }

    private function assertSmf(array $run): void
    {
        $this->assertBoard($run, 'News & Views', 'Child', 'Grandchild');
        $this->assertNull($this->tag('Recycle Bin'));
        $this->assertNull($this->discussion('Binned'), 'What was deleted into the recycle bin');
        $this->assertNull($this->discussion('MOVED: Fish'));
        $this->assertMembers();
        $this->assertSame(0, (int) $this->user('never@board.example')->is_email_confirmed);

        $this->assertSame(1, (int) $this->discussion('Fish & chips')->is_locked);
        $this->assertSame([0, 1], [(int) $this->discussion('Pinned')->is_locked, (int) $this->discussion('Pinned')->is_sticky]);
    }

    #[Test]
    public function an_smf_2_0_board_imports_its_topics()
    {
        $this->smf(false);
        $this->assertSmf($this->import('smf', 'smf_'));
    }

    #[Test]
    public function an_smf_2_1_board_skips_its_redirect_topics()
    {
        $this->smf(true);
        $this->assertSmf($this->import('smf', 'smf_'));
    }
}
