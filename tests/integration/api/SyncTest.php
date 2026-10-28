<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;

/**
 * Keeping an imported board in sync with the old one: import, let the old
 * board gain members, topics, replies and edits, sync — and only those arrive,
 * once, beside whatever was written on Flarum in the meantime.
 */
class SyncTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private string $source;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-importer');
        $this->prepareDatabase(['users' => [$this->normalUser()]]);

        $this->source = tempnam(sys_get_temp_dir(), 'importer-sync-').'.sqlite';
        $this->pdo = new PDO('sqlite:'.$this->source);
    }

    protected function tearDown(): void
    {
        @unlink($this->source);

        parent::tearDown();
    }

    private function phpbb(): void
    {
        $this->pdo->exec('CREATE TABLE phpbb_forums (forum_id INTEGER PRIMARY KEY, forum_name TEXT, forum_desc TEXT, forum_type INTEGER, left_id INTEGER)');
        $this->pdo->exec('CREATE TABLE phpbb_users (user_id INTEGER PRIMARY KEY, username TEXT, user_email TEXT, user_password TEXT, user_regdate INTEGER, user_type INTEGER)');
        $this->pdo->exec('CREATE TABLE phpbb_topics (topic_id INTEGER PRIMARY KEY, forum_id INTEGER, topic_title TEXT, topic_poster INTEGER, topic_time INTEGER, topic_type INTEGER, topic_visibility INTEGER)');
        $this->pdo->exec('CREATE TABLE phpbb_posts (post_id INTEGER PRIMARY KEY, topic_id INTEGER, poster_id INTEGER, post_text TEXT, bbcode_uid TEXT, post_time INTEGER, post_visibility INTEGER, post_edit_time INTEGER DEFAULT 0)');
        $this->pdo->exec("INSERT INTO phpbb_forums VALUES (2, 'General', 'Talk', 1, 1)");
        $this->pdo->exec("INSERT INTO phpbb_users VALUES (1, 'Anonymous', '', '', 0, 2), (5, 'Ada', 'ada@board.example', '', 1600000000, 0), (6, 'Bob', 'bob@board.example', '', 1600000100, 0)");
        $this->pdo->exec("INSERT INTO phpbb_topics VALUES (10, 2, 'First topic', 5, 1600001000, 0, 1), (11, 2, 'Second topic', 6, 1600002000, 0, 1)");
        $this->pdo->exec("INSERT INTO phpbb_posts (post_id, topic_id, poster_id, post_text, bbcode_uid, post_time, post_visibility) VALUES
            (100, 10, 5, 'Hello', '', 1600001000, 1), (101, 10, 6, 'A reply', '', 1600001100, 1),
            (102, 11, 6, 'Second', '', 1600002000, 1), (103, 10, 6, 'Held for approval', '', 1600001200, 0)");
    }

    /** What the old phpBB board gained after the import. */
    private function phpbbGrows(): void
    {
        $now = time();
        $this->pdo->exec("INSERT INTO phpbb_users VALUES (7, 'Cy', 'cy@board.example', '', $now, 0)");
        $this->pdo->exec("INSERT INTO phpbb_topics VALUES (12, 2, 'New topic', 7, $now, 0, 1)");
        $this->pdo->exec("INSERT INTO phpbb_posts (post_id, topic_id, poster_id, post_text, bbcode_uid, post_time, post_visibility) VALUES
            (104, 10, 7, 'Late reply in an old topic', '', $now, 1), (105, 12, 7, 'Opening the new topic', '', $now, 1), (106, 12, 5, 'Answer', '', $now + 1, 1)");
        // An edit to a post that was imported, and the approval of one that was not.
        $this->pdo->exec("UPDATE phpbb_posts SET post_text = 'Hello, edited', post_edit_time = $now WHERE post_id = 100");
        $this->pdo->exec('UPDATE phpbb_posts SET post_visibility = 1 WHERE post_id = 103');
    }

    private function mybb(): void
    {
        $this->pdo->exec('CREATE TABLE mybb_forums (fid INTEGER PRIMARY KEY, name TEXT, description TEXT, type TEXT, linkto TEXT, disporder INTEGER)');
        $this->pdo->exec('CREATE TABLE mybb_users (uid INTEGER PRIMARY KEY, username TEXT, email TEXT, regdate INTEGER)');
        $this->pdo->exec('CREATE TABLE mybb_threads (tid INTEGER PRIMARY KEY, fid INTEGER, subject TEXT, uid INTEGER, dateline INTEGER, sticky INTEGER, visible INTEGER)');
        $this->pdo->exec('CREATE TABLE mybb_posts (pid INTEGER PRIMARY KEY, tid INTEGER, uid INTEGER, message TEXT, dateline INTEGER, visible INTEGER, edittime INTEGER DEFAULT 0)');
        $this->pdo->exec("INSERT INTO mybb_forums VALUES (1, 'Lounge', '', 'f', '', 1)");
        $this->pdo->exec("INSERT INTO mybb_users VALUES (1, 'Ann', 'ann@mybb.example', 1600000000), (2, 'Ben', 'ben@mybb.example', 1600000001)");
        $this->pdo->exec("INSERT INTO mybb_threads VALUES (1, 1, 'Hi all', 1, 1600001000, 0, 1)");
        $this->pdo->exec("INSERT INTO mybb_posts (pid, tid, uid, message, dateline, visible) VALUES (1, 1, 1, 'Hi [b]all[/b]', 1600001000, 1), (2, 1, 2, 'Hey', 1600001100, 1)");
    }

    private function drive(array $body): array
    {
        $response = $this->send($this->request('POST', '/api/importer/start', ['authenticatedAs' => 1, 'json' => $body]));
        $run = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        for ($i = 0; $i < 60 && ! $run['done'] && ! $run['failed']; $i++) {
            $response = $this->send($this->request('POST', '/api/importer/step', ['authenticatedAs' => 1, 'json' => ['runId' => $run['runId']]]));
            $run = json_decode((string) $response->getBody(), true);
        }
        $this->assertTrue($run['done'], json_encode($run));

        return $run;
    }

    private function import(string $source, string $prefix): array
    {
        return $this->drive(['source' => $source, 'config' => ['driver' => 'sqlite', 'database' => $this->source, 'prefix' => $prefix]]);
    }

    private function sync(array $config = []): array
    {
        return $this->drive(['sync' => true, 'config' => $config]);
    }

    /** @return array<string,list<string>> discussion title => its comment texts, in order */
    private function forum(): array
    {
        $db = $this->database();
        $out = [];
        foreach ($db->table('discussions')->orderBy('id')->get() as $d) {
            $out[$d->title] = $db->table('posts')->where('discussion_id', $d->id)->where('type', 'comment')->orderBy('number')
                ->pluck('content')->map(fn ($c) => trim(strip_tags((string) $c)))->all();
        }

        return $out;
    }

    /** A reply written on Flarum while the two forums ran side by side. */
    private function replyHere(string $title, string $text): int
    {
        $db = $this->database();
        $d = $db->table('discussions')->where('title', $title)->first();

        return (int) $db->table('posts')->insertGetId([
            'discussion_id' => $d->id, 'number' => (int) $db->table('posts')->where('discussion_id', $d->id)->max('number') + 1,
            'created_at' => '2026-01-01 00:00:00', 'user_id' => 2, 'type' => 'comment', 'content' => '<t>'.$text.'</t>', 'is_private' => 0,
        ]);
    }

    #[Test]
    public function a_sync_brings_across_only_what_is_new_and_only_once()
    {
        $this->phpbb();
        $this->import('phpbb', 'phpbb_');
        $local = $this->replyHere('First topic', 'Written on Flarum');
        $this->phpbbGrows();

        $run = $this->sync();
        $this->assertTrue($run['sync']);
        $this->assertEquals(['topics' => 1, 'posts' => 4, 'users' => 1, 'edited' => 1], array_intersect_key($run['summary'], array_flip(['topics', 'posts', 'users', 'edited'])), json_encode($run['summary']));

        $expected = [
            'First topic' => ['Hello, edited', 'A reply', 'Written on Flarum', 'Held for approval', 'Late reply in an old topic'],
            'Second topic' => ['Second'],
            'New topic' => ['Opening the new topic', 'Answer'],
        ];
        $this->assertSame($expected, $this->forum());

        // Again, with nothing new on the old board: nothing changes.
        $again = $this->sync();
        $this->assertSame(0, (int) ($again['summary']['posts'] ?? 0));
        $this->assertSame(0, (int) ($again['summary']['topics'] ?? 0));
        $this->assertSame($expected, $this->forum());

        $db = $this->database();
        $this->assertSame('<t>Written on Flarum</t>', $db->table('posts')->where('id', $local)->value('content'), 'Content written here is never touched');

        // The counters nothing fires events for.
        $first = $db->table('discussions')->where('title', 'First topic')->first();
        $this->assertSame([5, 5], [(int) $first->comment_count, (int) $first->last_post_number]);
        $this->assertSame($db->table('posts')->where('discussion_id', $first->id)->orderByDesc('number')->value('id'), (int) $first->last_post_id);
        $cy = $db->table('users')->where('email', 'cy@board.example')->first();
        $this->assertSame([1, 2], [(int) $cy->discussion_count, (int) $cy->comment_count]);
        $ada = $db->table('users')->where('email', 'ada@board.example')->first();
        $this->assertSame([1, 2], [(int) $ada->discussion_count, (int) $ada->comment_count]);
        $tag = $db->table('tags')->where('name', 'General')->orderByDesc('id')->first(); // not the install's own General
        $this->assertSame(3, (int) $tag->discussion_count);
        $this->assertSame((int) $db->table('discussions')->where('title', 'New topic')->value('id'), (int) $tag->last_posted_discussion_id);
    }

    #[Test]
    public function an_edit_made_here_wins_over_one_made_on_the_old_board()
    {
        $this->phpbb();
        $this->import('phpbb', 'phpbb_');
        $db = $this->database();
        $post = $db->table('posts')->where('content', 'like', '%Hello%')->value('id');
        $db->table('posts')->where('id', $post)->update(['content' => '<t>Fixed here</t>', 'edited_at' => '2026-01-01 00:00:00']);
        $this->phpbbGrows();

        $run = $this->sync();

        $this->assertEquals(['edited' => 0, 'kept' => 1], array_intersect_key($run['summary'], array_flip(['edited', 'kept'])));
        $this->assertSame('<t>Fixed here</t>', $db->table('posts')->where('id', $post)->value('content'));
    }

    #[Test]
    public function an_import_from_before_posts_were_mapped_is_synced_without_duplicates()
    {
        $this->phpbb();
        $import = $this->import('phpbb', 'phpbb_');
        // What 0.3.7 left behind: no post map, no connection settings.
        $db = $this->database();
        $db->table('importer_map')->where('kind', 'post')->delete();
        $db->table('importer_runs')->where('id', $import['runId'])->update(['config' => null]);
        $this->phpbbGrows();

        $refused = $this->send($this->request('POST', '/api/importer/start', ['authenticatedAs' => 1, 'json' => ['sync' => true]]));
        $this->assertSame(422, $refused->getStatusCode(), 'Asks for the connection again');

        $this->sync(['driver' => 'sqlite', 'database' => $this->source, 'prefix' => 'phpbb_']);
        $this->sync();

        $this->assertSame([
            'First topic' => ['Hello, edited', 'A reply', 'Held for approval', 'Late reply in an old topic'],
            'Second topic' => ['Second'],
            'New topic' => ['Opening the new topic', 'Answer'],
        ], $this->forum());
    }

    #[Test]
    public function a_sync_waits_for_a_running_import()
    {
        $this->phpbb();
        $this->import('phpbb', 'phpbb_');
        $this->database()->table('importer_runs')->insert(['source' => 'phpbb', 'state' => '{}', 'status' => 'running']);

        $response = $this->send($this->request('POST', '/api/importer/start', ['authenticatedAs' => 1, 'json' => ['sync' => true]]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('busy', (string) $response->getBody());
    }

    #[Test]
    public function a_member_cannot_sync()
    {
        $this->phpbb();
        $this->import('phpbb', 'phpbb_');

        $response = $this->send($this->request('POST', '/api/importer/start', ['authenticatedAs' => 2, 'json' => ['sync' => true]]));

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function mybb_syncs_new_replies_and_edits()
    {
        $this->mybb();
        $this->import('mybb', 'mybb_');
        $now = time();
        $this->pdo->exec("INSERT INTO mybb_users VALUES (3, 'Cal', 'cal@mybb.example', $now)");
        $this->pdo->exec("INSERT INTO mybb_threads VALUES (2, 1, 'Fresh', 3, $now, 0, 1)");
        $this->pdo->exec("INSERT INTO mybb_posts (pid, tid, uid, message, dateline, visible) VALUES (3, 1, 3, 'Me too', $now, 1), (4, 2, 3, 'Brand new', $now, 1), (5, 2, 1, 'Hidden', $now, 0)");
        $this->pdo->exec("UPDATE mybb_posts SET message = 'Hey there', edittime = $now WHERE pid = 2");

        $this->sync();
        $this->sync();

        // No Markdown on this forum: the bold is written as it can be shown, never as literal **.
        $this->assertSame(['Hi all' => ['Hi all', 'Hey there', 'Me too'], 'Fresh' => ['Brand new']], $this->forum());
        $this->assertSame(1, (int) $this->database()->table('users')->where('email', 'cal@mybb.example')->value('discussion_count'));
    }

    #[Test]
    public function a_sync_brings_groups_to_every_member_once_and_a_new_post_s_attachments_once()
    {
        $this->phpbb();
        $dir = sys_get_temp_dir().'/importer-sync-files-'.bin2hex(random_bytes(4));
        mkdir($dir.'/files', 0777, true);
        file_put_contents($dir.'/files/7_cy', "%PDF-1.4\n% minutes\n");
        $this->drive(['source' => 'phpbb', 'config' => ['driver' => 'sqlite', 'database' => $this->source, 'prefix' => 'phpbb_', 'files_path' => $dir]]);

        // An import from before groups came across; then the board gains a member and an attachment.
        $this->phpbbGrows();
        $this->pdo->exec('CREATE TABLE phpbb_groups (group_id INTEGER PRIMARY KEY, group_type INTEGER, group_name TEXT, group_colour TEXT)');
        $this->pdo->exec('CREATE TABLE phpbb_user_group (group_id INTEGER, user_id INTEGER, user_pending INTEGER)');
        $this->pdo->exec('CREATE TABLE phpbb_attachments (attach_id INTEGER PRIMARY KEY, post_msg_id INTEGER, in_message INTEGER, is_orphan INTEGER, physical_filename TEXT, real_filename TEXT, poster_id INTEGER, filetime INTEGER)');
        $this->pdo->exec("INSERT INTO phpbb_groups VALUES (5, 3, 'ADMINISTRATORS', ''), (7, 0, 'Storm Chasers', '3366FF')");
        $this->pdo->exec('INSERT INTO phpbb_user_group VALUES (5, 5, 0), (7, 6, 0), (7, 7, 0)');
        $this->pdo->exec("INSERT INTO phpbb_attachments VALUES (1, 105, 0, 0, '7_cy', 'minutes.pdf', 7, 1)");

        $run = $this->sync();
        $this->sync();

        $db = $this->database();
        $ids = $db->table('users')->whereIn('email', ['ada@board.example', 'bob@board.example', 'cy@board.example'])->pluck('id', 'email')->map(fn ($id) => (int) $id)->all();
        $ids = ['Ada' => $ids['ada@board.example'], 'Bob' => $ids['bob@board.example'], 'Cy' => $ids['cy@board.example']];
        $chasers = (int) $db->table('groups')->where('name_singular', 'Storm Chasers')->value('id');
        $this->assertSame(1, $db->table('groups')->where('name_singular', 'Storm Chasers')->count(), json_encode($run));
        $this->assertSame([[$ids['Ada'], 1], [$ids['Bob'], $chasers], [$ids['Cy'], $chasers]], $db->table('group_user')->whereIn('user_id', array_values($ids))
            ->orderBy('user_id')->get()->map(fn ($r) => [(int) $r->user_id, (int) $r->group_id])->all(), 'Old members and new, once');
        $this->assertSame(1, $db->table('importer_files')->count(), 'The attachment is copied once');
        $answer = (string) $db->table('posts')->where('content', 'like', '%Opening the new topic%')->value('content');
        $this->assertStringContainsString((string) $db->table('importer_files')->value('url'), $answer);
        exec('rm -rf '.escapeshellarg($dir));
    }
}
