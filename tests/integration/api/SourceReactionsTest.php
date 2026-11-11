<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use ErnestDefoe\Importer\Importers\Ctx;
use ErnestDefoe\Importer\Importers\Dst;
use ErnestDefoe\Importer\Importers\Reactions;
use ErnestDefoe\Importer\Importers\Registry;
use Flarum\Testing\integration\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;

/**
 * phpBB post reactions, from either add-on, into fof/reactions. Ada (10), Bob
 * (11) and Cy (12) were imported; posts 100 and 101 became 1 and 2.
 */
class SourceReactionsTest extends TestCase
{
    private const PEOPLE = ['10' => 3, '11' => 4, '12' => 5];
    private const POSTS = ['100' => 1, '101' => 2];

    private string $file;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-reactions', 'ernestdefoe-importer');
        $this->prepareDatabase([
            'users' => array_map(fn ($id, $name) => ['id' => $id, 'username' => $name, 'email' => $name.'@forum.example', 'is_email_confirmed' => 1, 'password' => ''], [3, 4, 5], ['ada', 'bob', 'cy']),
            'discussions' => [['id' => 1, 'title' => 'Weather', 'slug' => 'weather', 'user_id' => 3, 'first_post_id' => 1, 'comment_count' => 2, 'created_at' => '2024-01-01 00:00:00']],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 3, 'type' => 'comment', 'content' => '<t>Rain</t>', 'created_at' => '2024-01-01 00:00:00'],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'user_id' => 4, 'type' => 'comment', 'content' => '<t>Sun</t>', 'created_at' => '2024-01-02 00:00:00'],
            ],
        ]);

        $this->file = tempnam(sys_get_temp_dir(), 'importer-reactions-').'.sqlite';
        $this->pdo = new PDO('sqlite:'.$this->file);
        $this->sql(
            'CREATE TABLE phpbb_config (config_name TEXT, config_value TEXT)',
            'CREATE TABLE phpbb_config_text (config_name TEXT, config_value TEXT)',
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    private function sql(string ...$statements): void
    {
        foreach ($statements as $s) {
            $this->pdo->exec($s);
        }
    }

    /** @return array{0:array<string,int>,1:int} the summary and the run */
    private function runPhases(?int $runId = null): array
    {
        $this->app();
        $db = $this->database();
        $cfg = ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => 'phpbb_'];
        if (! $runId) {
            $runId = (int) $db->table('importer_runs')->insertGetId(['source' => 'phpbb', 'config' => json_encode($cfg), 'state' => '{}', 'status' => 'done']);
            foreach (['user' => self::PEOPLE, 'post' => self::POSTS] as $kind => $map) {
                foreach ($map as $src => $target) {
                    $db->table('importer_map')->insert(['run_id' => $runId, 'kind' => $kind, 'source_id' => $src, 'target_id' => $target]);
                }
            }
        }
        Dst::reset();
        $ctx = new Ctx($runId, $cfg);
        $summary = [];
        foreach (Reactions::phases(Registry::get('phpbb'), $cfg) as $phase) {
            $cursor = null;
            for ($i = 0; $i < 100; $i++) {
                $res = ($phase->batch)($cursor, 2, $ctx);
                foreach ($res['summary'] as $k => $v) {
                    $summary[$k] = ($summary[$k] ?? 0) + $v;
                }
                $cursor = $res['cursor'];
                if ($res['done']) {
                    break;
                }
            }
        }

        return [$summary, $runId];
    }

    /** @return list<string> "post user shortname" for every reaction here */
    private function reactions(): array
    {
        return $this->database()->table('post_reactions')->join('reactions', 'reactions.id', '=', 'post_reactions.reaction_id')
            ->orderBy('post_id')->orderBy('user_id')->get(['post_id', 'user_id', 'identifier'])
            ->map(fn ($r) => $r->post_id.' '.$r->user_id.' '.$r->identifier)->all();
    }

    #[Test]
    public function steve_reactions_arrive_on_the_forum_s_own_emoji()
    {
        $this->sql(
            'CREATE TABLE phpbb_steve_reaction_types (reaction_type_id INTEGER PRIMARY KEY, reaction_file_name TEXT, reaction_type_title TEXT, reaction_type_enable INTEGER)',
            'CREATE TABLE phpbb_steve_reactions (reaction_id INTEGER PRIMARY KEY, reaction_user_id INTEGER, post_id INTEGER, pm_id INTEGER, reaction_type_id INTEGER, reaction_file_name TEXT, reaction_time INTEGER)',
            "INSERT INTO phpbb_steve_reaction_types VALUES (1, '1f44d.svg', 'Like', 1), (2, '2764.svg', 'Love', 0), (3, 'our-cat.gif', 'Cat', 1)",
            "INSERT INTO phpbb_steve_reactions VALUES
                (1, 10, 100, 0, 1, '1f44d.svg', 1700000000),
                (2, 11, 100, 0, 2, '2764.svg', 1700000100),
                (3, 12, 100, 0, 3, 'our-cat.gif', 1700000200),
                (4, 11, 101, 0, 1, '1f44d.svg', 1700000300),
                (5, 10, 0, 7, 1, '1f44d.svg', 1700000400),
                (6, 99, 100, 0, 1, '1f44d.svg', 1700000500),
                (7, 10, 101, 0, 9, '1f525.svg', 1700000600)",
        );
        // FoF Reactions comes with a thumbs up: it is used, not made again.
        $thumbs = (int) $this->database()->table('reactions')->where('identifier', 'thumbsup')->value('id');
        $this->assertGreaterThan(0, $thumbs);

        [$summary, $runId] = $this->runPhases();

        $this->assertSame(['1 3 thumbsup', '1 4 heart', '2 3 fire', '2 4 thumbsup'], $this->reactions(), json_encode($summary));
        $this->assertSame(4, $summary['reactions'] ?? 0);
        $this->assertSame(1, $summary['reactions_pictures'] ?? 0, 'The cat picture is no emoji');
        $this->assertSame(1, $summary['reactions_skipped'] ?? 0, 'Member 99 was not imported');
        $this->assertSame(1, $this->database()->table('reactions')->where('identifier', 'thumbsup')->count());
        $this->assertSame($thumbs, (int) $this->database()->table('post_reactions')->where('post_id', 1)->where('user_id', 3)->value('reaction_id'));
        $this->assertFalse((bool) $this->database()->table('reactions')->where('identifier', 'fire')->value('enabled'), 'A kind the old board deleted arrives switched off');

        // Again: nothing twice. A sync starts past what was read.
        $this->assertSame(0, $this->runPhases($runId)[0]['reactions'] ?? 0);
        $this->assertCount(4, $this->reactions());
        $this->assertSame(7, Reactions::syncFrom(Registry::get('phpbb'), [], $runId)['reactions-steve']);
    }

    #[Test]
    public function ferdcoz_reactions_use_its_catalog_or_its_five_defaults()
    {
        $this->sql(
            'CREATE TABLE phpbb_ferdcoz_reactions (post_id INTEGER, user_id INTEGER, reaction TEXT, reaction_time INTEGER, PRIMARY KEY (post_id, user_id))',
            "INSERT INTO phpbb_ferdcoz_reactions VALUES (100, 10, 'like', 1700000000), (100, 11, 'unicorn', 1700000100), (101, 12, 'face', 1700000200), (101, 10, 'love', 1700000300)",
        );

        // No catalog saved: the add-on's five. "unicorn" is none of them, and
        // "face", which reads like a codepoint, is not taken for one.
        $this->runPhases();
        $this->assertSame(['1 3 thumbsup', '2 3 heart'], $this->reactions());

        $this->database()->table('post_reactions')->delete();
        $this->sql("INSERT INTO phpbb_config_text VALUES ('ferdcoz_reactions_catalog', '".json_encode(['like' => ['emoji' => '👍', 'enabled' => true], 'unicorn' => ['emoji' => '🦄', 'label' => 'Magic', 'enabled' => true]])."')");
        [$summary] = $this->runPhases();
        $this->assertSame(['1 3 thumbsup', '1 4 unicorn_face'], $this->reactions(), json_encode($summary));
        $this->assertSame(2, $summary['reactions_pictures'] ?? 0, 'face and love are not in the saved catalog');
    }

    #[Test]
    public function bastien_reactions_keep_each_member_s_newest_emoji()
    {
        $this->sql(
            'CREATE TABLE phpbb_post_reactions (reaction_id INTEGER PRIMARY KEY, post_id INTEGER, topic_id INTEGER, user_id INTEGER, reaction_emoji TEXT, reaction_time INTEGER, reaction_notified INTEGER)',
            "INSERT INTO phpbb_post_reactions VALUES
                (1, 100, 1, 10, '👍', 1700000000, 1),
                (2, 100, 1, 10, '🦄', 1700000900, 1),
                (3, 100, 1, 11, '&#x1F525;', 1700000100, 1),
                (4, 101, 1, 12, '❤️', 1700000200, 1)",
        );

        [$summary, $runId] = $this->runPhases();

        // Ada reacted twice to one post: FoF Reactions keeps one, her newest.
        $this->assertSame(['1 3 unicorn_face', '1 4 fire', '2 5 heart'], $this->reactions(), json_encode($summary));
        $this->assertSame(3, $summary['reactions'] ?? 0);
        $this->assertSame(1, $summary['reactions_skipped'] ?? 0);
        $this->assertFalse((bool) $this->database()->table('reactions')->where('identifier', 'unicorn_face')->value('enabled'), 'A new kind arrives switched off: it shows on posts, not in the picker');
        $this->assertSame(4, Reactions::syncFrom(Registry::get('phpbb'), [], $runId)['reactions-bastien']);
    }

    #[Test]
    public function a_board_without_fof_reactions_is_told_once()
    {
        $this->extensions = ['ernestdefoe-importer'];
        $this->sql(
            'CREATE TABLE phpbb_ferdcoz_reactions (post_id INTEGER, user_id INTEGER, reaction TEXT, reaction_time INTEGER)',
            "INSERT INTO phpbb_ferdcoz_reactions VALUES (100, 10, 'like', 1700000000)",
        );

        $this->assertSame(['reactions_unavailable' => 1], $this->runPhases()[0]);
    }

    #[Test]
    public function an_emoji_is_found_by_character_or_twemoji_file()
    {
        $this->assertSame('1f44d', Reactions::code('👍'));
        $this->assertSame('2764-fe0f', Reactions::code('❤️'));
        $this->assertSame('1f469-200d-1f4bb', Reactions::code('1f469-200d-1f4bb.svg'));
        $this->assertSame('1f44d', Reactions::code('&#x1F44D;'), 'A board without utf8mb4 keeps it as an entity');
        $this->assertNull(Reactions::code('our-cat.gif'));
        $this->assertSame('heart', Reactions::shortname('2764-fe0f'));
        $this->assertSame('thumbsup', Reactions::shortname('1f44d-1f3fd'), 'A skin tone falls back to the emoji');
    }
}
