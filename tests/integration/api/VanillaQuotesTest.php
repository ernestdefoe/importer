<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;

/**
 * Issue #1: a Vanilla board that has used every post format over the years,
 * each quoting a member the way Vanilla's Quotes plugin writes it.
 */
class VanillaQuotesTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private string $source;

    /** Comment id => [Format, Body]. */
    private const COMMENTS = [
        10 => ['BBCode', '[quote="Kryptos;c-5"]The BBCode original[/quote]My BBCode reply'],
        11 => ['Markdown', "> @Kryptos said:\n> The Markdown original\n\nMy Markdown reply"],
        12 => ['Html', '<blockquote class="Quote" rel="Kryptos">The HTML original</blockquote><p>My HTML reply</p>'],
        13 => ['Wysiwyg', '<blockquote class="Quote UserQuote"><div class="QuoteAuthor"><a rel="nofollow" target="_blank">Kryptos</a> wrote:</div><div class="QuoteText">The WYSIWYG original</div></blockquote><p>My WYSIWYG reply</p>'],
        14 => ['Markdown', "> @\"Ana Maria\" dijo:\n> Original\n\nRespuesta"],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-markdown', 'ernestdefoe-importer');

        $this->prepareDatabase(['users' => [$this->normalUser()]]);

        $this->source = tempnam(sys_get_temp_dir(), 'vanilla').'.sqlite';
        $pdo = new PDO('sqlite:'.$this->source);
        $pdo->exec('CREATE TABLE GDN_User (UserID INTEGER PRIMARY KEY, Name TEXT, Email TEXT, Password TEXT, DateInserted TEXT, Deleted INTEGER)');
        $pdo->exec('CREATE TABLE GDN_Category (CategoryID INTEGER PRIMARY KEY, Name TEXT, UrlCode TEXT, Description TEXT, Sort INTEGER)');
        $pdo->exec('CREATE TABLE GDN_Discussion (DiscussionID INTEGER PRIMARY KEY, CategoryID INTEGER, Name TEXT, Body TEXT, Format TEXT, InsertUserID INTEGER, DateInserted TEXT, Announce INTEGER, Closed INTEGER)');
        $pdo->exec('CREATE TABLE GDN_Comment (CommentID INTEGER PRIMARY KEY, DiscussionID INTEGER, Body TEXT, Format TEXT, InsertUserID INTEGER, DateInserted TEXT)');
        $pdo->exec("INSERT INTO GDN_User VALUES (5, 'Kryptos', 'kryptos@board.example', '', '2010-01-01 00:00:00', 0), (6, 'JoZ3', 'joz3@board.example', '', '2010-01-02 00:00:00', 0)");
        $pdo->exec("INSERT INTO GDN_Discussion VALUES (1, -1, 'Fourteen years of formats', 'The original post', 'Html', 5, '2012-01-01 00:00:00', 0, 0)");
        $insert = $pdo->prepare("INSERT INTO GDN_Comment VALUES (?, 1, ?, ?, 6, '2012-01-02 00:00:00')");
        foreach (self::COMMENTS as $id => [$format, $body]) {
            $insert->execute([$id, $body, $format]);
        }
    }

    /** An import writes each post it brings across: one insert per post is the job, not an N+1. */
    protected function allowedRepeatedQueries(): array
    {
        // The importer writes one post per source record by design; match the
        // insert whatever the table prefix and identifier quoting.
        return ['posts" ("discussion_id", "number"', 'posts` (`discussion_id`, `number`'];
    }

    protected function tearDown(): void
    {
        @unlink($this->source);

        parent::tearDown();
    }

    /** @return array<int, string> post number => Markdown the member would see in the editor */
    private function import(): array
    {
        // The test app resolves its LocaleManager before this extension's
        // Locales extender runs; load the strings the attribution is built from.
        $this->app()->getContainer()->make(\Flarum\Locale\LocaleManager::class)->addTranslations('en', __DIR__.'/../../../resources/locale/en.yml');

        $response = $this->send($this->request('POST', '/api/importer/start', [
            'authenticatedAs' => 1,
            'json' => ['source' => 'vanilla', 'config' => ['driver' => 'sqlite', 'database' => $this->source, 'prefix' => 'GDN_']],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $run = json_decode((string) $response->getBody(), true);

        for ($i = 0; $i < 50 && ! $run['done'] && ! $run['failed']; $i++) {
            $response = $this->send($this->request('POST', '/api/importer/step', ['authenticatedAs' => 1, 'json' => ['runId' => $run['runId']]]));
            $run = json_decode((string) $response->getBody(), true);
        }
        $this->assertTrue($run['done'], json_encode($run));

        $formatter = $this->app()->getContainer()->make(\Flarum\Formatter\Formatter::class);

        return $this->database()->table('posts')->orderBy('number')->pluck('content', 'number')
            ->map(fn ($xml) => $formatter->unparse($xml))->all();
    }

    #[Test]
    public function every_format_keeps_who_was_quoted()
    {
        $posts = $this->import();

        // Post 1 is the discussion; the comments follow in id order.
        [$bbcode, $markdown, $html, $wysiwyg, $spanish] = array_values(array_slice($posts, 1));

        $this->assertStringStartsWith('> **Kryptos wrote:**', $bbcode, 'BBCode: the author was dropped');
        $this->assertStringContainsString('> The BBCode original', $bbcode);
        $this->assertStringNotContainsString('c-5', $bbcode);

        $this->assertStringStartsWith('> **Kryptos said:**', $markdown, 'Markdown: kept, not a mention');
        $this->assertStringNotContainsString('@Kryptos', $markdown, 'Flarum would link @Kryptos to whoever has that username now');
        $this->assertStringContainsString('The Markdown original', $markdown);

        $this->assertStringStartsWith('> **Kryptos wrote:**', $html, 'HTML: the author lives in rel=');

        $this->assertStringStartsWith('> Kryptos wrote:', $wysiwyg);
        $this->assertStringNotContainsString('<a', $wysiwyg, 'No raw markup shown as text');

        $this->assertStringStartsWith('> **Ana Maria dijo:**', $spanish, 'Names with spaces, in the board\'s own language');
    }
}
