<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use ErnestDefoe\Importer\Importers\Ctx;
use ErnestDefoe\Importer\Importers\Dst;
use ErnestDefoe\Importer\Importers\Registry;
use Flarum\Testing\integration\TestCase;
use Illuminate\Contracts\Filesystem\Factory;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every source's post attachments. Each fixture tells the same story in its
 * board's own tables: Ada's post 500 in topic 50 (imported as discussion 1)
 * shows sky.png in its text, and has report.pdf and evil.php attached below
 * it. The files are in the old board's folder on this server, or in its
 * database.
 *
 *  - the picture is copied and shown where the text put it;
 *  - the others are listed under the post, as the old board listed them;
 *  - 🚨 evil.php is stored as .bin: nothing from the old board can run here;
 *  - with FoF Upload, each is one of its files, tied to the post.
 */
class SourceAttachmentsTest extends TestCase
{
    private const T = 1600000000;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const PDF = "%PDF-1.4\n% a report\n";
    private const PHP = "<?php echo 'pwned';\n";

    private string $file;
    private string $dir;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-markdown', 'fof-upload', 'ernestdefoe-importer');
        $this->prepareDatabase([
            'users' => [['id' => 3, 'username' => 'ada', 'email' => 'ada@forum.example', 'is_email_confirmed' => 1, 'password' => '']],
            'discussions' => [['id' => 1, 'title' => 'Skies', 'slug' => 'skies', 'created_at' => gmdate('Y-m-d H:i:s', self::T), 'user_id' => 3, 'comment_count' => 0]],
        ]);

        $this->file = tempnam(sys_get_temp_dir(), 'importer-files-').'.sqlite';
        $this->pdo = new PDO('sqlite:'.$this->file);
        $this->dir = sys_get_temp_dir().'/importer-old-forum-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        exec('rm -rf '.escapeshellarg($this->dir));

        parent::tearDown();
    }

    private function sql(string ...$statements): void
    {
        foreach ($statements as $s) {
            $this->pdo->exec($s);
        }
    }

    /** Put a file in the old board's folder. */
    private function put(string $path, string $bytes): void
    {
        @mkdir(dirname($this->dir.'/'.$path), 0777, true);
        file_put_contents($this->dir.'/'.$path, $bytes);
    }

    public static function sources(): array
    {
        return [
            'phpBB' => ['phpbb', 'phpbb_'],
            'XenForo' => ['xenforo', ''],
            'vBulletin 3' => ['vbulletin', ''],
            'vBulletin 4' => ['vbulletin4', ''],
            'MyBB' => ['mybb', 'mybb_'],
            'SMF' => ['smf', 'smf_'],
            'Invision' => ['invision', ''],
            'Discourse' => ['discourse', ''],
            'Convoro' => ['convoro', ''],
        ];
    }

    /** The posts phase of a source, as an import that mapped Ada and topic 50 runs it. */
    private function runPosts(string $source, array $cfg): array
    {
        $this->app();
        $db = $this->database();
        $runId = (int) $db->table('importer_runs')->insertGetId(['source' => $source, 'config' => json_encode($cfg), 'state' => '{}', 'status' => 'running']);
        $db->table('importer_map')->insert([
            ['run_id' => $runId, 'kind' => 'user', 'source_id' => '10', 'target_id' => 3],
            ['run_id' => $runId, 'kind' => 'topic', 'source_id' => '50', 'target_id' => 1],
        ]);
        Dst::reset();
        $ctx = new Ctx($runId, $cfg);
        $phase = array_values(array_filter(Registry::phases($source, $cfg), fn ($p) => $p->key === 'posts'))[0];
        $summary = [];
        $cursor = null;
        for ($i = 0; $i < 20; $i++) {
            $res = ($phase->batch)($cursor, 200, $ctx);
            foreach ($res['summary'] as $k => $v) {
                $summary[$k] = ($summary[$k] ?? 0) + $v;
            }
            $cursor = $res['cursor'];
            if ($res['done']) {
                break;
            }
        }
        Dst::flushMentions();

        return $summary;
    }

    /** Every file the importer stored on this forum's public disk. */
    private function stored(): array
    {
        return resolve(Factory::class)->disk('flarum-assets')->allFiles();
    }

    #[Test]
    #[DataProvider('sources')]
    public function a_source_s_attachments_arrive_with_their_post(string $source, string $prefix)
    {
        $want = $this->{'fixture'.ucfirst($source)}() + ['files' => 3, 'listed' => true];
        $source = $source === 'vbulletin4' ? 'vbulletin' : $source;
        $cfg = ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => $prefix, 'files_path' => $this->dir];

        $summary = $this->runPosts($source, $cfg);

        $db = $this->database();
        $post = $db->table('posts')->where('discussion_id', 1)->first();
        $this->assertNotNull($post, json_encode($summary));
        $this->assertSame($want['files'], $summary['attachments'] ?? 0, json_encode($summary));
        $this->assertSame(0, $summary['attachments_missing'] ?? 0);

        $files = $db->table('fof_upload_files')->orderBy('id')->get();
        $this->assertCount($want['files'], $files, 'Each is one of FoF Upload\'s files');
        $this->assertSame($want['files'], $db->table('fof_upload_file_posts')->where('post_id', $post->id)->count(), '… tied to its post');
        $this->assertSame(3, (int) $files[0]->actor_id, 'Uploaded by Ada');
        $sky = $files->firstWhere('type', 'image/png');
        $this->assertNotNull($sky);
        $this->assertStringContainsString('<IMG', $post->content);
        $this->assertStringContainsString($sky->url, $post->content, 'The picture is where the text put it');
        $this->assertSame(1, substr_count($post->content, '<IMG '), '… and not listed again below');
        $this->assertStringContainsString('report.pdf', $post->content);
        $this->assertStringContainsString($files->firstWhere('type', 'application/pdf')->url, $post->content);

        if ($want['listed']) {
            $evil = $files->firstWhere('base_name', 'evil.bin');
            $this->assertNotNull($evil, json_encode($files));
            $this->assertStringEndsWith('.bin', $evil->path);
            $this->assertStringContainsString($evil->url, $post->content);
        }
        foreach ($this->stored() as $path) {
            $this->assertDoesNotMatchRegularExpression('/\.(php|phtml|html?|svg)$/i', $path, 'Nothing stored could run or render on this forum');
        }
    }

    #[Test]
    public function without_fof_upload_an_attachment_is_a_link_and_a_lost_one_keeps_its_name()
    {
        $this->extensions = ['flarum-markdown', 'ernestdefoe-importer'];
        $this->fixturePhpbb();
        $this->sql("INSERT INTO phpbb_attachments VALUES (4, 500, 0, 0, 'gone_d', 'holiday.zip', 10, 50)");

        $summary = $this->runPosts('phpbb', ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => 'phpbb_', 'files_path' => $this->dir]);

        $content = (string) $this->database()->table('posts')->where('discussion_id', 1)->value('content');
        $this->assertSame(3, $summary['attachments'] ?? 0);
        $this->assertSame(1, $summary['attachments_missing'] ?? 0, 'Counted');
        $this->assertStringContainsString('/assets/attachments/', $content);
        $this->assertStringContainsString('holiday.zip', $content, 'Named in the post, not dropped');
        $this->assertSame(3, $this->database()->table('importer_files')->count(), 'Each copy recorded, so nothing is stored twice');
    }

    /* ── One fixture per source ─────────────────────────────────────────── */

    private function fixturePhpbb(): array
    {
        $this->sql(
            'CREATE TABLE phpbb_posts (post_id INTEGER PRIMARY KEY, topic_id INTEGER, poster_id INTEGER, post_text TEXT, bbcode_uid TEXT, post_time INTEGER, post_visibility INTEGER)',
            'CREATE TABLE phpbb_attachments (attach_id INTEGER PRIMARY KEY, post_msg_id INTEGER, in_message INTEGER, is_orphan INTEGER, physical_filename TEXT, real_filename TEXT, poster_id INTEGER, filetime INTEGER)',
            'CREATE TABLE phpbb_config (config_name TEXT, config_value TEXT)',
            "INSERT INTO phpbb_config VALUES ('upload_path', 'files')",
            "INSERT INTO phpbb_posts VALUES (500, 50, 10, 'Look: [attachment=0:u1]sky.png[/attachment:u1]', 'u1', ".self::T.', 1)',
            // phpBB numbers a post's attachments from the newest.
            "INSERT INTO phpbb_attachments VALUES (1, 500, 0, 0, '10_a', 'sky.png', 10, 300), (2, 500, 0, 0, '10_b', 'report.pdf', 10, 200), (3, 500, 0, 0, '10_c', 'evil.php', 10, 100)",
        );
        $this->put('files/10_a', base64_decode(self::PNG));
        $this->put('files/10_b', self::PDF);
        $this->put('files/10_c', self::PHP);

        return [];
    }

    private function fixtureXenforo(): array
    {
        $this->sql(
            'CREATE TABLE xf_post (post_id INTEGER PRIMARY KEY, thread_id INTEGER, user_id INTEGER, message TEXT, post_date INTEGER, message_state TEXT)',
            'CREATE TABLE xf_attachment (attachment_id INTEGER PRIMARY KEY, data_id INTEGER, content_type TEXT, content_id INTEGER)',
            'CREATE TABLE xf_attachment_data (data_id INTEGER PRIMARY KEY, user_id INTEGER, filename TEXT, file_hash TEXT)',
            "INSERT INTO xf_post VALUES (500, 50, 10, 'Look: [ATTACH=full]1[/ATTACH]', ".self::T.", 'visible')",
            "INSERT INTO xf_attachment VALUES (1, 11, 'post', 500), (2, 12, 'post', 500), (3, 13, 'post', 500)",
            "INSERT INTO xf_attachment_data VALUES (11, 10, 'sky.png', 'aaa'), (12, 10, 'report.pdf', 'bbb'), (13, 10, 'evil.php', 'ccc')",
        );
        $this->put('internal_data/attachments/0/11-aaa.data', base64_decode(self::PNG));
        $this->put('internal_data/attachments/0/12-bbb.data', self::PDF);
        $this->put('internal_data/attachments/0/13-ccc.data', self::PHP);

        return [];
    }

    /** vBulletin 3: the file in the attachment row itself. */
    private function fixtureVbulletin(): array
    {
        $this->sql(
            'CREATE TABLE post (postid INTEGER PRIMARY KEY, threadid INTEGER, userid INTEGER, pagetext TEXT, dateline INTEGER, visible INTEGER)',
            'CREATE TABLE attachment (attachmentid INTEGER PRIMARY KEY, postid INTEGER, userid INTEGER, filename TEXT, filedata BLOB, visible INTEGER)',
            "INSERT INTO post VALUES (500, 50, 10, 'Look: [ATTACH]1[/ATTACH]', ".self::T.', 1)',
        );
        $ins = $this->pdo->prepare('INSERT INTO attachment VALUES (?, 500, 10, ?, ?, 1)');
        foreach ([[1, 'sky.png', base64_decode(self::PNG)], [2, 'report.pdf', self::PDF], [3, 'evil.php', self::PHP]] as $row) {
            $ins->execute($row);
        }

        return [];
    }

    /** vBulletin 4: `filedata`, kept on disk by member id, a folder per digit. */
    private function fixtureVbulletin4(): array
    {
        $this->sql(
            'CREATE TABLE post (postid INTEGER PRIMARY KEY, threadid INTEGER, userid INTEGER, pagetext TEXT, dateline INTEGER, visible INTEGER)',
            'CREATE TABLE contenttype (contenttypeid INTEGER PRIMARY KEY, class TEXT)',
            'CREATE TABLE attachment (attachmentid INTEGER PRIMARY KEY, contenttypeid INTEGER, contentid INTEGER, userid INTEGER, filedataid INTEGER, filename TEXT, state TEXT)',
            'CREATE TABLE filedata (filedataid INTEGER PRIMARY KEY, userid INTEGER, filedata BLOB, extension TEXT)',
            "INSERT INTO post VALUES (500, 50, 10, 'Look: [ATTACH=CONFIG]1[/ATTACH]', ".self::T.', 1)',
            "INSERT INTO contenttype VALUES (1, 'Thread'), (2, 'Post')",
            "INSERT INTO attachment VALUES (1, 2, 500, 10, 21, 'sky.png', 'visible'), (2, 2, 500, 10, 22, 'report.pdf', 'visible'), (3, 2, 500, 10, 23, 'evil.php', 'visible'), (4, 2, 500, 10, 24, 'held.png', 'moderation')",
            "INSERT INTO filedata VALUES (21, 10, '', 'png'), (22, 10, '', 'pdf'), (23, 10, '', 'php'), (24, 10, '', 'png')",
        );
        $this->put('attachments/1/0/21.attach', base64_decode(self::PNG));
        $this->put('attachments/1/0/22.attach', self::PDF);
        $this->put('attachments/1/0/23.attach', self::PHP);
        $this->put('attachments/1/0/24.attach', base64_decode(self::PNG));

        return [];
    }

    private function fixtureMybb(): array
    {
        $this->sql(
            'CREATE TABLE mybb_posts (pid INTEGER PRIMARY KEY, tid INTEGER, uid INTEGER, message TEXT, dateline INTEGER, visible INTEGER, edittime INTEGER)',
            'CREATE TABLE mybb_attachments (aid INTEGER PRIMARY KEY, pid INTEGER, uid INTEGER, filename TEXT, attachname TEXT, visible INTEGER)',
            'CREATE TABLE mybb_settings (name TEXT, value TEXT)',
            "INSERT INTO mybb_settings VALUES ('uploadspath', './uploads')",
            "INSERT INTO mybb_posts VALUES (500, 50, 10, 'Look: [attachment=1]', ".self::T.', 1, 0)',
            "INSERT INTO mybb_attachments VALUES (1, 500, 10, 'sky.png', '202009/post_10_a.attach', 1), (2, 500, 10, 'report.pdf', '202009/post_10_b.attach', 1), (3, 500, 10, 'evil.php', '202009/post_10_c.attach', 1)",
        );
        $this->put('uploads/202009/post_10_a.attach', base64_decode(self::PNG));
        $this->put('uploads/202009/post_10_b.attach', self::PDF);
        $this->put('uploads/202009/post_10_c.attach', self::PHP);

        return [];
    }

    private function fixtureSmf(): array
    {
        $this->sql(
            'CREATE TABLE smf_messages (id_msg INTEGER PRIMARY KEY, id_topic INTEGER, id_member INTEGER, body TEXT, poster_time INTEGER, approved INTEGER, modified_time INTEGER)',
            'CREATE TABLE smf_attachments (id_attach INTEGER PRIMARY KEY, id_msg INTEGER, id_member INTEGER, id_folder INTEGER, attachment_type INTEGER, filename TEXT, file_hash TEXT, approved INTEGER)',
            'CREATE TABLE smf_settings (variable TEXT, value TEXT)',
            "INSERT INTO smf_settings VALUES ('attachmentUploadDir', '{\"1\":\"/home/old-server/forum/attachments\"}')",
            "INSERT INTO smf_messages VALUES (500, 50, 10, 'Look: [attach id=1]sky.png[/attach]', ".self::T.', 1, 0)',
            // 4 is the thumbnail SMF made of 1.
            "INSERT INTO smf_attachments VALUES (1, 500, 10, 1, 0, 'sky.png', 'aaa', 1), (2, 500, 10, 1, 0, 'report.pdf', 'bbb', 1), (3, 500, 10, 1, 0, 'evil.php', 'ccc', 1), (4, 500, 10, 1, 3, 'sky.png_thumb', 'ddd', 1)",
        );
        $this->put('attachments/1_aaa.dat', base64_decode(self::PNG));
        $this->put('attachments/2_bbb.dat', self::PDF);
        $this->put('attachments/3_ccc.dat', self::PHP);
        $this->put('attachments/4_ddd.dat', base64_decode(self::PNG));

        return [];
    }

    private function fixtureInvision(): array
    {
        $this->sql(
            'CREATE TABLE forums_posts (pid INTEGER PRIMARY KEY, topic_id INTEGER, author_id INTEGER, post TEXT, post_date INTEGER, pdelete_time INTEGER, queued INTEGER, edit_time INTEGER)',
            'CREATE TABLE core_attachments (attach_id INTEGER PRIMARY KEY, attach_file TEXT, attach_location TEXT, attach_member_id INTEGER)',
            'CREATE TABLE core_attachments_map (attachment_id INTEGER, location_key TEXT, id1 INTEGER, id2 INTEGER)',
            "INSERT INTO forums_posts VALUES (500, 50, 10, '<p>Look: <img src=\"<fileStore.core_Attachment>/monthly_2020_09/sky.png\" data-fileid=\"1\" alt=\"sky.png\"></p>', ".self::T.', 0, 0, 0)',
            "INSERT INTO core_attachments VALUES (1, 'sky.png', 'monthly_2020_09/sky.png', 10), (2, 'report.pdf', 'monthly_2020_09/report.pdf.abc', 10), (3, 'evil.php', 'monthly_2020_09/evil.php.def', 10)",
            "INSERT INTO core_attachments_map VALUES (1, 'forums_Forums', 50, 500), (2, 'forums_Forums', 50, 500), (3, 'forums_Forums', 50, 500)",
        );
        $this->put('uploads/monthly_2020_09/sky.png', base64_decode(self::PNG));
        $this->put('uploads/monthly_2020_09/report.pdf.abc', self::PDF);
        $this->put('uploads/monthly_2020_09/evil.php.def', self::PHP);

        return [];
    }

    private function fixtureDiscourse(): array
    {
        $pdf = sha1(self::PDF);
        $short = \ErnestDefoe\Importer\Importers\Attachments::base62($pdf);
        $this->sql(
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, topic_id INTEGER, user_id INTEGER, cooked TEXT, created_at TEXT, deleted_at TEXT, post_type INTEGER)',
            'CREATE TABLE upload_references (upload_id INTEGER, target_type TEXT, target_id INTEGER)',
            'CREATE TABLE uploads (id INTEGER PRIMARY KEY, url TEXT, original_filename TEXT, sha1 TEXT, extension TEXT, user_id INTEGER)',
            "INSERT INTO posts VALUES (500, 50, 10, '<p>Look: <img src=\"/uploads/default/original/1X/aaa.png\" alt=\"sky\"></p><p><a class=\"attachment\" href=\"/uploads/short-url/{$short}.pdf\">report.pdf</a></p>', '2020-09-13 12:26:40', NULL, 1)",
            "INSERT INTO upload_references VALUES (1, 'Post', 500), (2, 'Post', 500)",
            "INSERT INTO uploads VALUES (1, '/uploads/default/original/1X/aaa.png', 'sky.png', '".sha1(base64_decode(self::PNG))."', 'png', 10), (2, '/uploads/default/original/1X/{$pdf}.pdf', 'report.pdf', '{$pdf}', 'pdf', 10)",
        );
        $this->put('public/uploads/default/original/1X/aaa.png', base64_decode(self::PNG));
        $this->put("public/uploads/default/original/1X/{$pdf}.pdf", self::PDF);

        // Discourse lists nothing under a post: what it holds is what its text shows.
        return ['files' => 2, 'listed' => false];
    }

    private function fixtureConvoro(): array
    {
        $this->sql(
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'CREATE TABLE forums (id INTEGER PRIMARY KEY, title TEXT)',
            'CREATE TABLE topics (id INTEGER PRIMARY KEY, forum_id INTEGER, is_pinned INTEGER)',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, topic_id INTEGER, user_id INTEGER, content_html TEXT, created_at TEXT)',
            'CREATE TABLE attachments (id INTEGER PRIMARY KEY, user_id INTEGER, owner_type TEXT, owner_id INTEGER, token TEXT, path TEXT, original_name TEXT)',
            "INSERT INTO posts VALUES (500, 50, 10, '<p>Look: <img src=\"/attachment/tokA\"></p>', '2020-09-13 12:26:40')",
            "INSERT INTO attachments VALUES (1, 10, 'post', 500, 'tokA', '2020/09/a.png', 'sky.png'), (2, 10, 'post', 500, 'tokB', '2020/09/b.pdf', 'report.pdf'), (3, 10, 'post', 500, 'tokC', '2020/09/c.txt', 'evil.php')",
        );
        $this->put('content/attachments/2020/09/a.png', base64_decode(self::PNG));
        $this->put('content/attachments/2020/09/b.pdf', self::PDF);
        $this->put('content/attachments/2020/09/c.txt', self::PHP);

        return [];
    }
}
