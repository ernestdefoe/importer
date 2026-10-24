<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;

/**
 * A small vBulletin 5 board in a SQLite file, shaped like the one in issue #2:
 * a one-post topic, a Visitor Message (vB5 keeps profile-wall posts as
 * title-less Text nodes under a system channel), a forum tree four deep, and
 * attachments embedded in a post.
 */
class Vbulletin5ImportTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-markdown', 'ernestdefoe-importer');

        $this->prepareDatabase(['users' => [$this->normalUser()]]);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        $this->source = tempnam(sys_get_temp_dir(), 'vb5').'.sqlite';
        $pdo = new PDO('sqlite:'.$this->source);
        $pdo->exec('CREATE TABLE contenttype (contenttypeid INTEGER PRIMARY KEY, class TEXT)');
        $pdo->exec('CREATE TABLE node (nodeid INTEGER PRIMARY KEY, contenttypeid INTEGER, parentid INTEGER, title TEXT, description TEXT, displayorder INTEGER, userid INTEGER, publishdate INTEGER, created INTEGER, approved INTEGER, sticky INTEGER)');
        $pdo->exec('CREATE TABLE text (nodeid INTEGER PRIMARY KEY, rawtext TEXT, htmlstate TEXT)');
        $pdo->exec('CREATE TABLE user (userid INTEGER PRIMARY KEY, username TEXT, email TEXT, joindate INTEGER, lastactivity INTEGER, lastvisit INTEGER, usergroupid INTEGER, membergroupids TEXT)');
        $pdo->exec('CREATE TABLE routenew (routeid INTEGER PRIMARY KEY, prefix TEXT, class TEXT, contentid INTEGER)');
        $pdo->exec('CREATE TABLE attach (nodeid INTEGER PRIMARY KEY, filedataid INTEGER, filename TEXT)');
        $pdo->exec('CREATE TABLE filedata (filedataid INTEGER PRIMARY KEY, filedata BLOB, extension TEXT)');

        $pdo->exec("INSERT INTO contenttype VALUES (1, 'Channel'), (2, 'Text'), (3, 'Attach')");
        $pdo->exec("INSERT INTO node VALUES
            (1, 1, 0, 'Root', '', 0, 0, 0, 0, 1, 0),
            (2, 1, 1, 'Forum', '', 0, 0, 0, 0, 1, 0),
            (3, 1, 1, 'Visitor Messages', '', 0, 0, 0, 0, 1, 0),
            (10, 1, 2, 'Neighbourhoods', 'Where you live', 1, 0, 0, 0, 1, 0),
            (11, 1, 10, 'South-West', '', 1, 0, 0, 0, 1, 0),
            (12, 1, 11, 'Villapark', '', 1, 0, 0, 0, 1, 0),
            (100, 2, 10, 'Solo topic', '', 0, 5, 1600001000, 1600001000, 1, 0),
            (101, 2, 12, 'Topic with a reply', '', 0, 6, 1600002000, 1600002000, 1, 0),
            (102, 2, 101, '', '', 0, 5, 1600002100, 1600002100, 1, 0),
            (103, 2, 3, '', '', 0, 6, 1600003000, 1600003000, 1, 0)");
        $pdo->exec("INSERT INTO text VALUES
            (100, 'Look: [ATTACH=JSON]{\"data-align\":\"none\",\"data-size\":\"full\",\"title\":\"IMG-1.png\",\"data-attachmentid\":200}[/ATTACH] and [ATTACH=CONFIG]n201[/ATTACH] and [ATTACH]999[/ATTACH] end', 'off'),
            (101, 'Starter', 'off'),
            (102, 'A reply', 'off'),
            (103, 'Hi Ada, nice profile!', 'off')");
        $pdo->exec("INSERT INTO user VALUES (5, 'Ada', 'ada@board.example', 1600000000, 1700000000, 0, 2, '6'), (6, 'Bob', 'bob@board.example', 1600000100, 0, 0, 2, '')");
        $pdo->exec("INSERT INTO routenew VALUES (1, '', 'vB5_Route_Channel', 1), (2, 'forum', 'vB5_Route_Channel', 2), (3, 'special/visitor-messages', 'vB5_Route_Channel', 3),
            (4, 'forum/neighbourhoods', 'vB5_Route_Channel', 10), (5, 'forum/neighbourhoods/south-west', 'vB5_Route_Channel', 11), (6, 'forum/neighbourhoods/south-west/villapark', 'vB5_Route_Channel', 12)");
        $pdo->exec("INSERT INTO attach VALUES (200, 1, 'IMG-1.png'), (201, 2, 'shell.php')");
        $insert = $pdo->prepare('INSERT INTO filedata VALUES (?, ?, ?)');
        $insert->bindValue(1, 1);
        $insert->bindValue(2, $png, PDO::PARAM_LOB);
        $insert->bindValue(3, 'png');
        $insert->execute();
        $insert->execute([2, '<?php echo "pwned";', 'php']);
    }

    protected function tearDown(): void
    {
        @unlink($this->source);

        parent::tearDown();
    }

    private function import(): array
    {
        $response = $this->send($this->request('POST', '/api/importer/start', [
            'authenticatedAs' => 1,
            'json' => ['source' => 'vbulletin', 'config' => ['driver' => 'sqlite', 'database' => $this->source, 'prefix' => '']],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $run = json_decode((string) $response->getBody(), true);

        for ($i = 0; $i < 50 && ! $run['done'] && ! $run['failed']; $i++) {
            $response = $this->send($this->request('POST', '/api/importer/step', ['authenticatedAs' => 1, 'json' => ['runId' => $run['runId']]]));
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $run = json_decode((string) $response->getBody(), true);
        }

        $this->assertTrue($run['done'], json_encode($run));

        return $run;
    }

    #[Test]
    public function no_untitled_or_empty_discussions()
    {
        $this->import();
        $db = $this->database();

        $this->assertEqualsCanonicalizing(['Solo topic', 'Topic with a reply'], $db->table('discussions')->pluck('title')->all(), 'The Visitor Message is not a discussion');
        $this->assertSame(0, $db->table('discussions')->whereNull('first_post_id')->count(), 'Every discussion has its first post');

        $solo = $db->table('discussions')->where('title', 'Solo topic')->first();
        $this->assertSame([1, 1], [(int) $solo->comment_count, (int) $solo->last_post_number], 'A topic without replies is finalised');
        $this->assertSame(2, (int) $db->table('discussions')->where('title', 'Topic with a reply')->value('comment_count'));
    }

    #[Test]
    public function the_forum_tree_becomes_two_levels_of_tags()
    {
        $tagsBefore = $this->database()->table('tags')->max('id') ?? 0;
        $this->import();
        $tags = $this->database()->table('tags')->where('id', '>', $tagsBefore)->get()->keyBy('name');

        $this->assertEqualsCanonicalizing(['Neighbourhoods', 'South-West', 'Villapark (South-West)'], $tags->keys()->all(), 'Not Root, Forum or Visitor Messages');
        $top = $tags['Neighbourhoods'];
        $this->assertNull($top->parent_id);
        foreach (['South-West', 'Villapark (South-West)'] as $child) {
            $this->assertSame((int) $top->id, (int) $tags[$child]->parent_id);
        }
        // Stored as flarum/tags itself stores the primary tree (OrderTagsController).
        $this->assertSame(3, $tags->filter(fn ($t) => (bool) $t->is_primary)->count());
    }

    #[Test]
    public function staff_and_last_seen_come_across()
    {
        $this->import();
        $db = $this->database();
        $ada = $db->table('users')->where('email', 'ada@board.example')->first();
        $bob = $db->table('users')->where('email', 'bob@board.example')->first();

        $this->assertTrue($db->table('group_user')->where('user_id', $ada->id)->where('group_id', 1)->exists(), 'A vB administrator (secondary group 6) is an admin');
        $this->assertFalse($db->table('group_user')->where('user_id', $bob->id)->exists());
        $this->assertNotNull($ada->last_seen_at);
        $this->assertNull($bob->last_seen_at, 'Never seen stays never seen, not the join date');
    }

    #[Test]
    public function attachments_are_rehosted_and_never_executable()
    {
        $this->import();
        $content = (string) $this->database()->table('posts')->where('number', 1)
            ->where('discussion_id', $this->database()->table('discussions')->where('title', 'Solo topic')->value('id'))->value('content');

        $this->assertMatchesRegularExpression('#<IMG [^>]*src="[^"]*/assets/attachments/[A-Za-z0-9]{16}-IMG-1\.png"#', $content, 'The image is rehosted');
        $this->assertMatchesRegularExpression('#/assets/attachments/[A-Za-z0-9]{16}-shell\.bin"#', $content, 'A script is stored as a download');
        $this->assertDoesNotMatchRegularExpression('#\.php"#', $content, 'No address ends in .php');
        $this->assertStringNotContainsString('ATTACH', $content, 'An attachment that is gone is dropped, not left as BBCode');
        $this->assertStringNotContainsString('data-attachmentid', $content);
    }
}
