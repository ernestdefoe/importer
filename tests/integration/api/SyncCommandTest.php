<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use Flarum\Testing\integration\ConsoleTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;

/** `importer:run --sync` from the command line, and its dry run. */
class SyncCommandTest extends ConsoleTestCase
{
    private string $source;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-importer');

        $this->source = tempnam(sys_get_temp_dir(), 'importer-sync-').'.sqlite';
        $this->pdo = new PDO('sqlite:'.$this->source);
        $this->pdo->exec('CREATE TABLE phpbb_forums (forum_id INTEGER PRIMARY KEY, forum_name TEXT, forum_desc TEXT, forum_type INTEGER, left_id INTEGER)');
        $this->pdo->exec('CREATE TABLE phpbb_users (user_id INTEGER PRIMARY KEY, username TEXT, user_email TEXT, user_password TEXT, user_regdate INTEGER, user_type INTEGER)');
        $this->pdo->exec('CREATE TABLE phpbb_topics (topic_id INTEGER PRIMARY KEY, forum_id INTEGER, topic_title TEXT, topic_poster INTEGER, topic_time INTEGER, topic_type INTEGER, topic_visibility INTEGER)');
        $this->pdo->exec('CREATE TABLE phpbb_posts (post_id INTEGER PRIMARY KEY, topic_id INTEGER, poster_id INTEGER, post_text TEXT, bbcode_uid TEXT, post_time INTEGER, post_visibility INTEGER)');
        $this->pdo->exec("INSERT INTO phpbb_forums VALUES (2, 'General', '', 1, 1)");
        $this->pdo->exec("INSERT INTO phpbb_users VALUES (5, 'Ada', 'ada@board.example', '', 1600000000, 0)");
        $this->pdo->exec("INSERT INTO phpbb_topics VALUES (10, 2, 'First topic', 5, 1600001000, 0, 1)");
        $this->pdo->exec("INSERT INTO phpbb_posts VALUES (100, 10, 5, 'Hello', '', 1600001000, 1)");
    }

    protected function tearDown(): void
    {
        @unlink($this->source);

        parent::tearDown();
    }

    #[Test]
    public function a_dry_run_counts_and_saves_nothing_then_the_sync_imports()
    {
        $this->runCommand(['command' => 'importer:run', '--source' => 'phpbb', '--driver' => 'sqlite', '--database' => $this->source, '--prefix' => 'phpbb_']);
        $this->pdo->exec("INSERT INTO phpbb_posts VALUES (101, 10, 5, 'Again', '', 1600001100, 1)");
        $db = $this->database();
        $before = [$db->table('posts')->count(), $db->table('importer_runs')->count(), $db->table('importer_map')->count()];

        $out = $this->runCommand(['command' => 'importer:run', '--sync' => true, '--dry-run' => true]);

        $this->assertStringContainsString('nothing was saved', $out);
        $this->assertSame($before, [$db->table('posts')->count(), $db->table('importer_runs')->count(), $db->table('importer_map')->count()]);

        $this->runCommand(['command' => 'importer:run', '--sync' => true]);
        $this->runCommand(['command' => 'importer:run', '--sync' => true]);

        $this->assertSame($before[0] + 1, $db->table('posts')->count());
    }
}
