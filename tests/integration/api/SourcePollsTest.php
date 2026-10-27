<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use ErnestDefoe\Importer\Importers\Ctx;
use ErnestDefoe\Importer\Importers\Dst;
use ErnestDefoe\Importer\Importers\Polls;
use ErnestDefoe\Importer\Importers\Registry;
use Flarum\Testing\integration\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every source's polls, read from its own tables, into fof/polls. Each fixture
 * tells the same story in its board's terms:
 *
 *  - topic 50 (imported as discussion 1) asks "Best colour?": Red, Green,
 *    Blue in that order; Ada voted Red, Bob Green, and an account the old
 *    board has since deleted (99) Red — 2 / 1 / 0;
 *  - topic 51 was not imported, so its poll ("Left behind?") is skipped.
 *
 * Settings differ per board and are checked against what each fixture set.
 */
class SourcePollsTest extends TestCase
{
    private const T = 1600000000;

    /** Flarum user id for each source user id. */
    private const PEOPLE = ['10' => 3, '11' => 4, '12' => 5];

    private string $file;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-polls', 'ernestdefoe-importer');
        $this->prepareDatabase([
            'users' => array_map(fn ($id, $name) => ['id' => $id, 'username' => $name, 'email' => $name.'@forum.example', 'is_email_confirmed' => 1, 'password' => ''], [3, 4, 5], ['ada', 'bob', 'cy']),
            'discussions' => [['id' => 1, 'title' => 'Colours', 'slug' => 'colours', 'created_at' => $this->date(0), 'user_id' => 3, 'first_post_id' => 1, 'comment_count' => 2]],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => $this->date(0), 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Vote!</p></t>'],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'created_at' => $this->date(10), 'user_id' => 4, 'type' => 'comment', 'content' => '<t><p>Done</p></t>'],
            ],
        ]);

        $this->file = tempnam(sys_get_temp_dir(), 'importer-polls-').'.sqlite';
        $this->pdo = new PDO('sqlite:'.$this->file);
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

    private function date(int $offset): string
    {
        return gmdate('Y-m-d H:i:s', self::T + $offset);
    }

    public static function sources(): array
    {
        return [
            'phpBB' => ['phpbb', 'phpbb_'],
            'XenForo' => ['xenforo', ''],
            'vBulletin 3/4' => ['vbulletin', ''],
            'vBulletin 5' => ['vbulletin5', ''],
            'MyBB' => ['mybb', 'mybb_'],
            'SMF' => ['smf', 'smf_'],
            'Invision' => ['invision', ''],
            'Discourse' => ['discourse', ''],
            'Convoro 2' => ['convoro', ''],
            'Convoro 1' => ['convoro1', ''],
            'Web Wiz' => ['webwiz', 'tbl'],
        ];
    }

    /**
     * Only the polls phase of a source, as an import that mapped the members,
     * topic 50 and its first post would run it — in batches of one, so every
     * boundary gets crossed.
     *
     * @return array<string,int> the summary
     */
    private function runPolls(string $source, array $cfg, ?int $runId = null): array
    {
        $this->app();
        $db = $this->database();
        if (! $runId) {
            $runId = (int) $db->table('importer_runs')->insertGetId(['source' => $source, 'config' => json_encode($cfg), 'state' => '{}', 'status' => 'done']);
            $map = [['user', '10', 3], ['user', '11', 4], ['user', '12', 5], ['topic', '50', 1], ['post', '500', 1]];
            foreach ($map as [$kind, $src, $target]) {
                $db->table('importer_map')->insert(['run_id' => $runId, 'kind' => $kind, 'source_id' => $src, 'target_id' => $target]);
            }
        }
        Dst::reset();
        $ctx = new Ctx($runId, $cfg);
        $summary = [];
        foreach (Polls::phases(Registry::get($source), $cfg) as $phase) {
            $cursor = null;
            for ($i = 0; $i < 100; $i++) {
                $res = ($phase->batch)($cursor, 1, $ctx);
                foreach ($res['summary'] as $k => $v) {
                    $summary[$k] = ($summary[$k] ?? 0) + $v;
                }
                $cursor = $res['cursor'];
                if ($res['done']) {
                    break;
                }
            }
        }

        return $summary;
    }

    /** Every poll, as [post, question, settings, end, vote count, [[answer, count, sorted voter ids (null = no voter)], …]]. */
    private function polls(): array
    {
        $db = $this->database();
        $out = [];
        foreach ($db->table('polls')->orderBy('id')->get() as $poll) {
            $options = [];
            foreach ($db->table('poll_options')->where('poll_id', $poll->id)->orderBy('id')->get() as $o) {
                $voters = $db->table('poll_votes')->where('option_id', $o->id)->pluck('user_id')->map(fn ($u) => $u === null ? null : (int) $u)->all();
                sort($voters);
                $options[] = [$o->answer, (int) $o->vote_count, $voters];
            }
            $out[] = [(int) $poll->post_id, $poll->question, json_decode($poll->settings, true), $poll->end_date ? substr((string) $poll->end_date, 0, 19) : null, (int) $poll->vote_count, $options];
        }

        return $out;
    }

    #[Test]
    #[DataProvider('sources')]
    public function a_source_s_polls_arrive_with_their_votes(string $source, string $prefix)
    {
        $want = $this->{'fixture'.ucfirst($source)}();
        $source = $source === 'convoro1' ? 'convoro' : $source;
        $cfg = ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => $prefix];

        $summary = $this->runPolls($source, $cfg);

        $named = $want['named'] ?? true;
        $polls = $this->polls();
        $this->assertCount(1, $polls, json_encode($polls));
        [$post, $question, $settings, $end, $count, $options] = $polls[0];
        $this->assertSame(1, $post, 'On the first post of the discussion its topic became');
        $this->assertSame('Best colour?', $question);
        $this->assertSame([
            ['Red', 2, $named ? [null, 3] : [null, null]],
            ['Green', 1, [$named ? 4 : null]],
            ['Blue', 0, []],
        ], $options, 'Answers in order, every vote a row; the deleted voter counts without a name');
        $this->assertSame(3, $count);
        $expected = $want['settings'] + ['hide_votes' => false];
        ksort($expected);
        ksort($settings);
        $this->assertSame($expected, $settings);
        $this->assertSame($want['ends'], $end);

        $this->assertSame(1, $summary['polls'] ?? 0);
        $this->assertSame(3, $summary['poll_votes'] ?? 0);
        $this->assertSame(1, $summary['polls_skipped'] ?? 0, 'The poll of a topic that was not imported');

        // Again: nothing new, nothing doubled.
        $this->runPolls($source, $cfg, (int) $this->database()->table('importer_runs')->value('id'));
        $this->assertSame($polls, $this->polls());
    }

    #[Test]
    public function each_question_of_an_invision_poll_is_a_poll_of_its_own()
    {
        $this->sql(
            'CREATE TABLE core_polls (pid INTEGER PRIMARY KEY, choices TEXT, poll_question TEXT, start_date INTEGER, starter_id INTEGER, poll_view_voters INTEGER, poll_closed INTEGER, poll_close_date INTEGER)',
            'CREATE TABLE forums_topics (tid INTEGER PRIMARY KEY, poll_state INTEGER)',
            'CREATE TABLE core_voters (vid INTEGER PRIMARY KEY, poll INTEGER, member_id INTEGER, member_choices TEXT, vote_date INTEGER)',
            'INSERT INTO forums_topics VALUES (50, 7)',
        );
        $choices = json_encode([
            1 => ['question' => 'Tea or coffee?', 'multi' => 0, 'choice' => [1 => 'Tea', 2 => 'Coffee'], 'votes' => [1 => 1, 2 => 1]],
            2 => ['question' => 'Which biscuits?', 'multi' => 1, 'choice' => [1 => 'Ginger', 2 => 'Shortbread', 3 => 'Digestive'], 'votes' => [1 => 1, 2 => 2, 3 => 0]],
        ]);
        $this->pdo->prepare('INSERT INTO core_polls VALUES (7, ?, ?, ?, 10, 0, 0, 0)')->execute([$choices, 'Elevenses', self::T]);
        $this->sql(
            "INSERT INTO core_voters VALUES (1, 7, 10, '{\"1\":[1],\"2\":[1,2]}', ".self::T.')',
            "INSERT INTO core_voters VALUES (2, 7, 11, '{\"1\":[\"2\"],\"2\":[\"2\"]}', ".self::T.')',
        );

        $this->runPolls('invision', ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => '']);

        $polls = $this->polls();
        $this->assertSame(['Tea or coffee?', 'Which biscuits?'], array_column($polls, 1));
        $this->assertSame([1, 1], array_column($polls, 0));
        $this->assertSame([['Tea', 1, [3]], ['Coffee', 1, [4]]], $polls[0][5]);
        $this->assertSame([['Ginger', 1, [3]], ['Shortbread', 2, [3, 4]], ['Digestive', 0, []]], $polls[1][5]);
        $this->assertFalse($polls[0][2]['allow_multiple_votes']);
        $this->assertTrue($polls[1][2]['allow_multiple_votes']);

        $this->runPolls('invision', ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => ''], (int) $this->database()->table('importer_runs')->value('id'));
        $this->assertCount(2, $this->polls(), 'Nothing doubled');
    }

    /* ── One fixture per source ─────────────────────────────────────────── */

    private function fixturePhpbb(): array
    {
        $this->sql(
            'CREATE TABLE phpbb_topics (topic_id INTEGER PRIMARY KEY, topic_poster INTEGER, topic_first_post_id INTEGER, poll_title TEXT, poll_start INTEGER, poll_length INTEGER, poll_max_options INTEGER, poll_vote_change INTEGER)',
            'CREATE TABLE phpbb_posts (post_id INTEGER PRIMARY KEY, bbcode_uid TEXT)',
            'CREATE TABLE phpbb_poll_options (poll_option_id INTEGER, topic_id INTEGER, poll_option_text TEXT, poll_option_total INTEGER)',
            'CREATE TABLE phpbb_poll_votes (topic_id INTEGER, poll_option_id INTEGER, vote_user_id INTEGER, vote_user_ip TEXT)',
            "INSERT INTO phpbb_topics VALUES (49, 10, 490, '', 0, 0, 1, 0), (50, 10, 500, '[b:abc]Best[/b:abc] colour?', ".self::T.', 604800, 1, 1), (51, 11, 510, '."'Left behind?', ".self::T.', 0, 1, 0)',
            "INSERT INTO phpbb_posts VALUES (500, 'abc'), (510, '')",
            "INSERT INTO phpbb_poll_options VALUES (1, 50, 'Red', 2), (2, 50, '[i:abc]Green[/i:abc]', 1), (3, 50, 'Blue', 0), (1, 51, 'Yes', 0), (2, 51, 'No', 0)",
            "INSERT INTO phpbb_poll_votes VALUES (50, 1, 10, ''), (50, 2, 11, ''), (50, 1, 99, '')",
        );

        return ['settings' => ['public_poll' => false, 'allow_multiple_votes' => false, 'max_votes' => 0, 'allow_change_vote' => true], 'ends' => $this->date(604800)];
    }

    private function fixtureXenforo(): array
    {
        $this->sql(
            'CREATE TABLE xf_poll (poll_id INTEGER PRIMARY KEY, content_type TEXT, content_id INTEGER, question TEXT, public_votes INTEGER, max_votes INTEGER, close_date INTEGER, change_vote INTEGER)',
            'CREATE TABLE xf_poll_response (poll_response_id INTEGER PRIMARY KEY, poll_id INTEGER, response TEXT, response_vote_count INTEGER)',
            'CREATE TABLE xf_poll_vote (user_id INTEGER, poll_response_id INTEGER, poll_id INTEGER, vote_date INTEGER)',
            'CREATE TABLE xf_thread (thread_id INTEGER PRIMARY KEY, user_id INTEGER, post_date INTEGER)',
            "INSERT INTO xf_poll VALUES (7, 'thread', 50, 'Best colour?', 1, 2, 0, 1), (8, 'thread', 51, 'Left behind?', 0, 1, 0, 0)",
            "INSERT INTO xf_poll_response VALUES (70, 7, 'Red', 2), (71, 7, 'Green', 1), (72, 7, 'Blue', 0), (80, 8, 'Yes', 0), (81, 8, 'No', 0)",
            'INSERT INTO xf_poll_vote VALUES (10, 70, 7, '.self::T.'), (11, 71, 7, '.self::T.'), (99, 70, 7, '.self::T.')',
            'INSERT INTO xf_thread VALUES (50, 10, '.self::T.'), (51, 11, '.self::T.')',
        );

        return ['settings' => ['public_poll' => true, 'allow_multiple_votes' => true, 'max_votes' => 2, 'allow_change_vote' => true], 'ends' => null];
    }

    private function fixtureVbulletin(): array
    {
        $this->sql(
            'CREATE TABLE poll (pollid INTEGER PRIMARY KEY, question TEXT, dateline INTEGER, options TEXT, votes TEXT, active INTEGER, timeout INTEGER, multiple INTEGER, public INTEGER, lastvote INTEGER)',
            'CREATE TABLE pollvote (pollvoteid INTEGER PRIMARY KEY, pollid INTEGER, userid INTEGER, votedate INTEGER, voteoption INTEGER)',
            'CREATE TABLE thread (threadid INTEGER PRIMARY KEY, pollid INTEGER, postuserid INTEGER, open INTEGER)',
            "INSERT INTO poll VALUES (7, 'Best colour?', ".self::T.", 'Red|||Green|||Blue', '2|||1|||0', 0, 0, 0, 1, ".(self::T + 500)."), (8, 'Left behind?', ".self::T.", 'Yes|||No', '0|||0', 1, 0, 0, 0, 0)",
            'INSERT INTO pollvote VALUES (1, 7, 10, '.self::T.', 1), (2, 7, 11, '.self::T.', 2), (3, 7, 99, '.self::T.', 1)',
            // 52 is a moved-thread redirect: its pollid is the thread it points to, not a poll.
            'INSERT INTO thread VALUES (50, 7, 10, 1), (51, 8, 11, 1), (52, 7, 10, 10)',
        );

        return ['settings' => ['public_poll' => true, 'allow_multiple_votes' => false, 'max_votes' => 0, 'allow_change_vote' => false], 'ends' => $this->date(500)];
    }

    private function fixtureVbulletin5(): array
    {
        $this->sql(
            'CREATE TABLE node (nodeid INTEGER PRIMARY KEY, title TEXT, userid INTEGER, created INTEGER)',
            'CREATE TABLE poll (nodeid INTEGER PRIMARY KEY, active INTEGER, timeout INTEGER, multiple INTEGER, public INTEGER, lastvote INTEGER)',
            'CREATE TABLE polloption (polloptionid INTEGER PRIMARY KEY, nodeid INTEGER, title TEXT, votes INTEGER)',
            'CREATE TABLE pollvote (pollvoteid INTEGER PRIMARY KEY, nodeid INTEGER, polloptionid INTEGER, userid INTEGER, votedate INTEGER)',
            "INSERT INTO node VALUES (50, 'Best colour?', 10, ".self::T."), (51, 'Left behind?', 11, ".self::T.')',
            'INSERT INTO poll VALUES (50, 1, '.(self::T + 86400).', 1, 0, 0), (51, 1, 0, 0, 0, 0)',
            "INSERT INTO polloption VALUES (5, 50, 'Red', 2), (6, 50, 'Green', 1), (7, 50, 'Blue', 0), (8, 51, 'Yes', 0), (9, 51, 'No', 0)",
            'INSERT INTO pollvote VALUES (1, 50, 5, 10, '.self::T.'), (2, 50, 6, 11, '.self::T.'), (3, 50, 5, 99, '.self::T.')',
        );

        return ['settings' => ['public_poll' => false, 'allow_multiple_votes' => true, 'max_votes' => 0, 'allow_change_vote' => false], 'ends' => $this->date(86400)];
    }

    private function fixtureMybb(): array
    {
        $this->sql(
            'CREATE TABLE mybb_polls (pid INTEGER PRIMARY KEY, tid INTEGER, question TEXT, dateline INTEGER, options TEXT, votes TEXT, timeout INTEGER, closed INTEGER, multiple INTEGER, public INTEGER, maxoptions INTEGER)',
            'CREATE TABLE mybb_pollvotes (vid INTEGER PRIMARY KEY, pid INTEGER, uid INTEGER, voteoption INTEGER, dateline INTEGER)',
            'CREATE TABLE mybb_threads (tid INTEGER PRIMARY KEY, uid INTEGER)',
            "INSERT INTO mybb_polls VALUES (7, 50, 'Best colour?', ".self::T.", 'Red||~|~||Green||~|~||Blue', '2||~|~||1||~|~||0', 3, 0, 1, 1, 2), (8, 51, 'Left behind?', ".self::T.", 'Yes||~|~||No', '0||~|~||0', 0, 0, 0, 0, 0)",
            'INSERT INTO mybb_pollvotes VALUES (1, 7, 10, 1, '.self::T.'), (2, 7, 11, 2, '.self::T.'), (3, 7, 99, 1, '.self::T.')',
            'INSERT INTO mybb_threads VALUES (50, 10), (51, 11)',
        );

        return ['settings' => ['public_poll' => true, 'allow_multiple_votes' => true, 'max_votes' => 2, 'allow_change_vote' => false], 'ends' => $this->date(3 * 86400)];
    }

    private function fixtureSmf(): array
    {
        $this->sql(
            'CREATE TABLE smf_polls (id_poll INTEGER PRIMARY KEY, question TEXT, voting_locked INTEGER, max_votes INTEGER, expire_time INTEGER, hide_results INTEGER, change_vote INTEGER, id_member INTEGER)',
            'CREATE TABLE smf_poll_choices (id_poll INTEGER, id_choice INTEGER, label TEXT, votes INTEGER)',
            'CREATE TABLE smf_log_polls (id_poll INTEGER, id_member INTEGER, id_choice INTEGER)',
            'CREATE TABLE smf_topics (id_topic INTEGER PRIMARY KEY, id_poll INTEGER, id_first_msg INTEGER)',
            'CREATE TABLE smf_messages (id_msg INTEGER PRIMARY KEY, poster_time INTEGER)',
            "INSERT INTO smf_polls VALUES (7, 'Best colour?', 0, 1, ".(self::T + 86400).", 2, 1, 10), (8, 'Left behind?', 0, 1, 0, 0, 0, 11)",
            "INSERT INTO smf_poll_choices VALUES (7, 0, 'Red', 2), (7, 1, 'Green', 1), (7, 2, 'Blue', 0), (8, 0, 'Yes', 0), (8, 1, 'No', 0)",
            // The deleted member's vote went with them; only the total remembers it.
            'INSERT INTO smf_log_polls VALUES (7, 10, 0), (7, 11, 1)',
            'INSERT INTO smf_topics VALUES (50, 7, 500), (51, 8, 510)',
            'INSERT INTO smf_messages VALUES (500, '.self::T.'), (510, '.self::T.')',
        );

        return ['settings' => ['public_poll' => false, 'allow_multiple_votes' => false, 'max_votes' => 0, 'allow_change_vote' => true, 'hide_votes' => true], 'ends' => $this->date(86400)];
    }

    private function fixtureInvision(): array
    {
        $this->sql(
            'CREATE TABLE core_polls (pid INTEGER PRIMARY KEY, choices TEXT, poll_question TEXT, start_date INTEGER, starter_id INTEGER, poll_view_voters INTEGER, poll_closed INTEGER, poll_close_date INTEGER)',
            'CREATE TABLE forums_topics (tid INTEGER PRIMARY KEY, poll_state INTEGER)',
            'CREATE TABLE core_voters (vid INTEGER PRIMARY KEY, poll INTEGER, member_id INTEGER, member_choices TEXT, vote_date INTEGER)',
            'INSERT INTO forums_topics VALUES (50, 7), (51, 8)',
            "INSERT INTO core_voters VALUES (1, 7, 10, '{\"1\":[1]}', ".self::T."), (2, 7, 11, '{\"1\":[2]}', ".self::T."), (3, 7, 99, '{\"1\":[1]}', ".self::T.')',
        );
        $insert = $this->pdo->prepare('INSERT INTO core_polls VALUES (?, ?, ?, ?, ?, 1, 0, -1)');
        $insert->execute([7, json_encode([1 => ['question' => 'Best colour?', 'multi' => 0, 'choice' => [1 => 'Red', 2 => 'Green', 3 => 'Blue'], 'votes' => [1 => 2, 2 => 1, 3 => 0]]]), 'Colours', self::T, 10]);
        // IPS 3 kept the choices serialized.
        $insert->execute([8, serialize([1 => ['question' => 'Left behind?', 'multi' => 0, 'choice' => [1 => 'Yes', 2 => 'No'], 'votes' => [1 => 0, 2 => 0]]]), 'Left', self::T, 11]);

        return ['settings' => ['public_poll' => true, 'allow_multiple_votes' => false, 'max_votes' => 0, 'allow_change_vote' => false], 'ends' => null];
    }

    private function fixtureDiscourse(): array
    {
        $this->sql(
            'CREATE TABLE polls (id INTEGER PRIMARY KEY, post_id INTEGER, name TEXT, type INTEGER, status INTEGER, results INTEGER, visibility INTEGER, max INTEGER, close_at TEXT, title TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE poll_options (id INTEGER PRIMARY KEY, poll_id INTEGER, html TEXT, anonymous_votes INTEGER)',
            'CREATE TABLE poll_votes (poll_id INTEGER, poll_option_id INTEGER, user_id INTEGER, created_at TEXT)',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, topic_id INTEGER, user_id INTEGER)',
            'CREATE TABLE topics (id INTEGER PRIMARY KEY, title TEXT)',
            "INSERT INTO polls VALUES (7, 500, 'poll', 0, 0, 2, 1, NULL, '".$this->date(86400)."', NULL, '".$this->date(0)."', '".$this->date(0)."'), (8, 510, 'poll', 0, 0, 0, 0, NULL, NULL, 'Left behind?', '".$this->date(0)."', '".$this->date(0)."')",
            // The deleted voter's vote survives only as an anonymous count.
            "INSERT INTO poll_options VALUES (70, 7, 'Red', 1), (71, 7, '<strong>Green</strong>', 0), (72, 7, 'Blue', NULL), (80, 8, 'Yes', 0), (81, 8, 'No', 0)",
            "INSERT INTO poll_votes VALUES (7, 70, 10, '".$this->date(5)."'), (7, 71, 11, '".$this->date(5)."')",
            'INSERT INTO posts VALUES (500, 50, 10), (510, 51, 11)',
            "INSERT INTO topics VALUES (50, 'Best colour?'), (51, 'Left behind')",
        );

        return ['settings' => ['public_poll' => true, 'allow_multiple_votes' => false, 'max_votes' => 0, 'allow_change_vote' => true, 'hide_votes' => true], 'ends' => $this->date(86400)];
    }

    private function fixtureConvoro(): array
    {
        $this->sql(
            'CREATE TABLE polls (id INTEGER PRIMARY KEY, topic_id INTEGER, question TEXT, is_multiple INTEGER, max_choices INTEGER, is_public INTEGER, hide_results INTEGER, closes_at TEXT, closed_at TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE poll_options (id INTEGER PRIMARY KEY, poll_id INTEGER, label TEXT, position INTEGER)',
            'CREATE TABLE poll_votes (id INTEGER PRIMARY KEY, poll_id INTEGER, option_id INTEGER, user_id INTEGER, created_at TEXT)',
            'CREATE TABLE topics (id INTEGER PRIMARY KEY, user_id INTEGER)',
            "INSERT INTO polls VALUES (7, 50, 'Best colour?', 0, 0, 1, 0, '".$this->date(86400)."', '".$this->date(50)."', '".$this->date(0)."', '".$this->date(0)."'), (8, 51, 'Left behind?', 0, 0, 0, 0, NULL, NULL, '".$this->date(0)."', '".$this->date(0)."')",
            // Ids out of order: the position is what orders them.
            "INSERT INTO poll_options VALUES (3, 7, 'Red', 0), (1, 7, 'Green', 1), (2, 7, 'Blue', 2), (4, 8, 'Yes', 0), (5, 8, 'No', 1)",
            "INSERT INTO poll_votes VALUES (1, 7, 3, 10, '".$this->date(5)."'), (2, 7, 1, 11, '".$this->date(5)."'), (3, 7, 3, 99, '".$this->date(5)."')",
            'INSERT INTO topics VALUES (50, 10), (51, 11)',
        );

        return ['settings' => ['public_poll' => true, 'allow_multiple_votes' => false, 'max_votes' => 0, 'allow_change_vote' => true], 'ends' => $this->date(50)];
    }

    private function fixtureConvoro1(): array
    {
        $this->sql(
            'CREATE TABLE polls (id INTEGER PRIMARY KEY, topic_id INTEGER, question TEXT, max_choices INTEGER, closes_at TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE poll_options (id INTEGER PRIMARY KEY, poll_id INTEGER, text TEXT, position INTEGER)',
            'CREATE TABLE poll_votes (id INTEGER PRIMARY KEY, poll_id INTEGER, option_id INTEGER, user_id INTEGER, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE topics (id INTEGER PRIMARY KEY, user_id INTEGER)',
            "INSERT INTO polls VALUES (7, 50, 'Best colour?', 2, NULL, '".$this->date(0)."', '".$this->date(0)."'), (8, 51, 'Left behind?', 1, NULL, '".$this->date(0)."', '".$this->date(0)."')",
            "INSERT INTO poll_options VALUES (1, 7, 'Red', 0), (2, 7, 'Green', 1), (3, 7, 'Blue', 2), (4, 8, 'Yes', 0), (5, 8, 'No', 1)",
            "INSERT INTO poll_votes VALUES (1, 7, 1, 10, '".$this->date(5)."', NULL), (2, 7, 2, 11, '".$this->date(5)."', NULL), (3, 7, 1, 99, '".$this->date(5)."', NULL)",
            'INSERT INTO topics VALUES (50, 10), (51, 11)',
        );

        return ['settings' => ['public_poll' => false, 'allow_multiple_votes' => true, 'max_votes' => 2, 'allow_change_vote' => true], 'ends' => null];
    }

    private function fixtureWebwiz(): array
    {
        $this->sql(
            'CREATE TABLE tblPoll (Poll_ID INTEGER PRIMARY KEY, Poll_question TEXT, Multiple_votes INTEGER, Reply INTEGER)',
            'CREATE TABLE tblPollChoice (Choice_ID INTEGER PRIMARY KEY, Poll_ID INTEGER, Choice TEXT, Votes INTEGER)',
            'CREATE TABLE tblTopic (Topic_ID INTEGER PRIMARY KEY, Poll_ID INTEGER, Author_ID INTEGER, Start_date TEXT)',
            "INSERT INTO tblPoll VALUES (7, 'Best colour?', 0, 0), (8, 'Left behind?', 0, 0)",
            "INSERT INTO tblPollChoice VALUES (1, 7, 'Red', 2), (2, 7, 'Green', 1), (3, 7, 'Blue', 0), (4, 8, 'Yes', 0), (5, 8, 'No', 0)",
            "INSERT INTO tblTopic VALUES (49, 0, 10, '".$this->date(0)."'), (50, 7, 10, '".$this->date(0)."'), (51, 8, 11, '".$this->date(0)."')",
        );

        // Web Wiz never kept who voted.
        return ['settings' => ['public_poll' => false, 'allow_multiple_votes' => false, 'max_votes' => 0, 'allow_change_vote' => false], 'ends' => null, 'named' => false];
    }
}
