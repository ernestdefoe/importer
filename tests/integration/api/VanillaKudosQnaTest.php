<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use ErnestDefoe\Importer\Importers\Ctx;
use ErnestDefoe\Importer\Importers\Dst;
use ErnestDefoe\Importer\Importers\Registry;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;

/**
 * A Vanilla board with the Kudos, QnA and view-count plugins (pull request #5,
 * hgy29): Kryptos (5) asked, JoZ3 (6) answered and Kryptos accepted it, Cyrus (7)
 * answered too. Kudos: on the question, a like from JoZ3 and a dislike from Cyrus;
 * on the accepted answer, likes from Kryptos, Cyrus and a member who was never
 * imported; on Cyrus's answer, a dislike from Kryptos.
 */
class VanillaKudosQnaTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private string $source;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-likes', 'fof-reactions', 'fof-best-answer', 'fof-discussion-views', 'ernestdefoe-importer');
        $this->prepareDatabase(['users' => [$this->normalUser()]]);

        $this->source = tempnam(sys_get_temp_dir(), 'vanilla-kudos').'.sqlite';
        $this->pdo = new PDO('sqlite:'.$this->source);
        foreach ([
            'CREATE TABLE GDN_User (UserID INTEGER PRIMARY KEY, Name TEXT, Email TEXT, Password TEXT, DateInserted TEXT, Deleted INTEGER)',
            'CREATE TABLE GDN_Category (CategoryID INTEGER PRIMARY KEY, Name TEXT, UrlCode TEXT, Description TEXT, Sort INTEGER)',
            'CREATE TABLE GDN_Discussion (DiscussionID INTEGER PRIMARY KEY, CategoryID INTEGER, Name TEXT, Body TEXT, Format TEXT, InsertUserID INTEGER, DateInserted TEXT, Announce INTEGER, Closed INTEGER, CountViews INTEGER, QnA TEXT)',
            'CREATE TABLE GDN_Comment (CommentID INTEGER PRIMARY KEY, DiscussionID INTEGER, Body TEXT, Format TEXT, InsertUserID INTEGER, DateInserted TEXT, QnA TEXT, AcceptedUserID INTEGER, DateAccepted TEXT)',
            'CREATE TABLE GDN_Kudos (CommentID INTEGER, DiscussionID INTEGER, UserID INTEGER, Action INTEGER, DateUpdated TEXT)',
            "INSERT INTO GDN_User VALUES (5, 'Kryptos', 'kryptos@board.example', '', '2010-01-01 00:00:00', 0), (6, 'JoZ3', 'joz3@board.example', '', '2010-01-02 00:00:00', 0), (7, 'Cyrus', 'cyrus@board.example', '', '2010-01-03 00:00:00', 0)",
            "INSERT INTO GDN_Category VALUES (1, 'General', 'general', 'Talk', 1)",
            "INSERT INTO GDN_Discussion VALUES
                (1, 1, 'Why does it rain?', 'First line\nSecond line', 'Html', 5, '2012-01-01 00:00:00', 0, 0, 42, 'Accepted'),
                (2, 1, 'Just chatting', '<p>One</p>\n<p>Two</p>', 'Wysiwyg', 6, '2012-02-01 00:00:00', 0, 0, 3, NULL)",
            "INSERT INTO GDN_Comment VALUES
                (10, 1, 'Clouds.', 'Html', 6, '2012-01-02 00:00:00', 'Accepted', 5, '2012-01-05 10:00:00'),
                (11, 1, 'Water.', 'Html', 7, '2012-01-03 00:00:00', NULL, NULL, NULL)",
            "INSERT INTO GDN_Kudos VALUES
                (NULL, 1, 6, 1, '2012-01-06 00:00:00'), (NULL, 1, 7, 2, '2012-01-06 00:00:00'),
                (10, NULL, 5, 1, '2012-01-06 00:00:00'), (10, NULL, 7, 1, '2012-01-06 00:00:00'), (10, NULL, 99, 1, '2012-01-06 00:00:00'),
                (11, NULL, 5, 2, '2012-01-06 00:00:00')",
        ] as $sql) {
            $this->pdo->exec($sql);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->source);

        parent::tearDown();
    }

    /** An import writes each post it brings across: one insert per post is the job, not an N+1. */
    protected function allowedRepeatedQueries(): array
    {
        return ['posts" ("discussion_id", "number"', 'posts` (`discussion_id`, `number`'];
    }

    private function cfg(): array
    {
        return ['driver' => 'sqlite', 'database' => $this->source, 'prefix' => 'GDN_'];
    }

    /** @return array the finished run */
    private function import(): array
    {
        $this->app()->getContainer()->make(\Flarum\Locale\LocaleManager::class)->addTranslations('en', __DIR__.'/../../../resources/locale/en.yml');
        $response = $this->send($this->request('POST', '/api/importer/start', ['authenticatedAs' => 1, 'json' => ['source' => 'vanilla', 'config' => $this->cfg()]]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $run = json_decode((string) $response->getBody(), true);
        for ($i = 0; $i < 50 && ! $run['done'] && ! $run['failed']; $i++) {
            $run = json_decode((string) $this->send($this->request('POST', '/api/importer/step', ['authenticatedAs' => 1, 'json' => ['runId' => $run['runId']]]))->getBody(), true);
        }
        $this->assertTrue($run['done'], json_encode($run));

        return $run;
    }

    /** @return array{0:list<string>,1:list<string>} "post user identifier" reactions, "post user" likes */
    private function kudos(): array
    {
        $db = $this->database();
        $who = $db->table('users')->pluck('username', 'id')->all();
        $num = $db->table('posts')->get(['id', 'discussion_id', 'number'])->mapWithKeys(fn ($p) => [$p->id => $p->discussion_id.'#'.$p->number])->all();

        return [
            $db->table('post_reactions')->join('reactions', 'reactions.id', '=', 'post_reactions.reaction_id')->get(['post_id', 'user_id', 'identifier'])
                ->map(fn ($r) => $num[$r->post_id].' '.$who[$r->user_id].' '.$r->identifier)->sort()->values()->all(),
            $db->table('post_likes')->get(['post_id', 'user_id'])->map(fn ($l) => $num[$l->post_id].' '.$who[$l->user_id])->sort()->values()->all(),
        ];
    }

    #[Test]
    public function kudos_views_and_the_accepted_answer_arrive()
    {
        $run = $this->import();
        $db = $this->database();
        $ask = $db->table('discussions')->where('title', 'Why does it rain?')->first();
        $chat = $db->table('discussions')->where('title', 'Just chatting')->first();

        $this->assertSame(42, (int) $ask->view_count);
        $this->assertSame(3, (int) $chat->view_count);

        // Kryptos accepted JoZ3's answer (post 2); JoZ3 has one best answer.
        $answer = (int) $db->table('posts')->where('discussion_id', $ask->id)->where('number', 2)->value('id');
        $this->assertSame($answer, (int) $ask->best_answer_post_id, json_encode($run));
        $this->assertSame((int) $db->table('users')->where('username', 'Kryptos')->value('id'), (int) $ask->best_answer_user_id);
        $this->assertStringStartsWith('2012-01-05 10:00:00', (string) $ask->best_answer_set_at);
        $this->assertSame(1, (int) $db->table('users')->where('username', 'JoZ3')->value('best_answer_count'));
        $this->assertNull($chat->best_answer_post_id);
        $this->assertSame(2, $db->table('posts')->where('discussion_id', $ask->id)->max('number') - 1, 'No post numbering lost');

        // The question is in the secondary "Question" tag, where best answers are on; the chat is not.
        $qna = $db->table('tags')->where('slug', 'qna-question')->first();
        $this->assertNotNull($qna);
        $this->assertNull($qna->position, 'A secondary tag');
        $this->assertTrue((bool) $qna->is_qna);
        $this->assertTrue($db->table('discussion_tag')->where('discussion_id', $ask->id)->where('tag_id', $qna->id)->exists());
        $this->assertFalse($db->table('discussion_tag')->where('discussion_id', $chat->id)->where('tag_id', $qna->id)->exists());

        [$reactions, $likes] = $this->kudos();
        $this->assertSame([
            $ask->id.'#1 Cyrus thumbsdown', $ask->id.'#1 JoZ3 thumbsup',
            $ask->id.'#2 Cyrus thumbsup', $ask->id.'#2 Kryptos thumbsup',
            $ask->id.'#3 Kryptos thumbsdown',
        ], $reactions);
        $this->assertSame([$ask->id.'#1 JoZ3', $ask->id.'#2 Cyrus', $ask->id.'#2 Kryptos'], $likes, 'Likes keeps likes, not dislikes');

        // Read one row at a time, every page boundary falls inside a post's kudos: nothing is lost or doubled.
        $db->table('post_reactions')->delete();
        $db->table('post_likes')->delete();
        $runId = (int) $db->table('importer_runs')->value('id');
        Dst::reset();
        $ctx = new Ctx($runId, $this->cfg());
        foreach (Registry::phases('vanilla', $this->cfg()) as $phase) {
            if ($phase->key !== 'likes') {
                continue;
            }
            for ($cursor = null, $i = 0; $i < 50; $i++) {
                $res = ($phase->batch)($cursor, 1, $ctx);
                $cursor = $res['cursor'];
                if ($res['done']) {
                    break;
                }
            }
        }
        $this->assertSame([$reactions, $likes], $this->kudos());
    }

    #[Test]
    public function html_line_breaks_are_kept_and_wysiwyg_is_left_alone()
    {
        $this->import();
        $formatter = $this->app()->getContainer()->make(\Flarum\Formatter\Formatter::class);
        $db = $this->database();
        $first = fn (string $title) => $formatter->unparse((string) $db->table('posts')->where('number', 1)
            ->where('discussion_id', $db->table('discussions')->where('title', $title)->value('id'))->value('content'));

        $this->assertSame("First line\nSecond line", $first('Why does it rain?'), 'Vanilla showed the Html line break');
        $this->assertSame("One\n\nTwo", $first('Just chatting'), 'Two paragraphs, no extra breaks');
    }

    #[Test]
    public function a_board_without_the_kudos_plugin_imports()
    {
        $this->pdo->exec('DROP TABLE GDN_Kudos');

        $run = $this->import();

        $this->assertSame(0, $this->database()->table('post_reactions')->count());
        $this->assertSame(3, $this->database()->table('posts')->count() - 1, json_encode($run));
    }
}
