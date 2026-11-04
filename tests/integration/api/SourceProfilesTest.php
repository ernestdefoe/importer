<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use ErnestDefoe\Importer\Importers\Ctx;
use ErnestDefoe\Importer\Importers\Dst;
use ErnestDefoe\Importer\Importers\Groups;
use ErnestDefoe\Importer\Importers\Profiles;
use ErnestDefoe\Importer\Importers\Registry;
use Flarum\Testing\integration\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every source's groups, profile photos and birthdays. Each fixture tells the
 * same story in its board's own tables:
 *
 *  - Ada (10) is an administrator, Bob (11) a moderator and a member of the
 *    board's own "Weather Spotters" group, Cy (12) an ordinary member;
 *  - the board's groups for the banned, the unconfirmed, bots, ranks and the
 *    like are counted, never made into groups;
 *  - Ada uploaded a picture (kept on disk under the old board's folder, or in
 *    the database), and was born on 5 March 1980; Bob gave his birthday,
 *    29 February, without the year.
 */
class SourceProfilesTest extends TestCase
{
    /** Flarum user id for each source user id. */
    private const PEOPLE = ['10' => 3, '11' => 4, '12' => 5];

    /** A real 1×1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $file;
    private string $dir;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('datlechin-birthdays', 'flarum-markdown', 'fof-signature', 'ernestdefoe-importer');
        $this->prepareDatabase([
            'users' => array_map(fn ($id, $name) => ['id' => $id, 'username' => $name, 'email' => $name.'@forum.example', 'is_email_confirmed' => 1, 'password' => ''], [3, 4, 5], ['ada', 'bob', 'cy']),
        ]);

        $this->file = tempnam(sys_get_temp_dir(), 'importer-profiles-').'.sqlite';
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

    /** Put the picture in the old board's folder. */
    private function picture(string $path): void
    {
        @mkdir(dirname($this->dir.'/'.$path), 0777, true);
        file_put_contents($this->dir.'/'.$path, base64_decode(self::PNG));
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
            'Vanilla' => ['vanilla', 'GDN_'],
            'Invision' => ['invision', ''],
            'Discourse' => ['discourse', ''],
            'Convoro' => ['convoro', ''],
            'Web Wiz' => ['webwiz', 'tbl'],
        ];
    }

    /**
     * The groups and profile phases of a source, as an import that mapped
     * the three members would run them — in batches of one, so every
     * boundary gets crossed.
     *
     * @return array<string,int> the summary
     */
    private function runPhases(string $source, array $cfg, ?int $runId = null): array
    {
        $this->app();
        $db = $this->database();
        if (! $runId) {
            $runId = (int) $db->table('importer_runs')->insertGetId(['source' => $source, 'config' => json_encode($cfg), 'state' => '{}', 'status' => 'done']);
            foreach (self::PEOPLE as $src => $target) {
                $db->table('importer_map')->insert(['run_id' => $runId, 'kind' => 'user', 'source_id' => $src, 'target_id' => $target]);
            }
        }
        Dst::reset();
        $ctx = new Ctx($runId, $cfg);
        $summary = [];
        $importer = Registry::get($source);
        foreach ([...Groups::phases($importer, $cfg), ...Profiles::phases($importer, $cfg)] as $phase) {
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

    /** [user id, group id] for every membership, the custom group by name. */
    private function memberships(): array
    {
        $db = $this->database();
        $names = $db->table('groups')->pluck('name_singular', 'id')->all();
        $out = [];
        foreach ($db->table('group_user')->whereIn('user_id', [3, 4, 5])->orderBy('user_id')->orderBy('group_id')->get() as $r) {
            $out[] = [(int) $r->user_id, (int) $r->group_id > 4 ? $names[$r->group_id] : (int) $r->group_id];
        }

        return $out;
    }

    #[Test]
    #[DataProvider('sources')]
    public function a_source_s_groups_photos_birthdays_and_signatures_arrive(string $source, string $prefix)
    {
        $want = $this->{'fixture'.ucfirst($source)}();
        $signed = $this->signature($source);
        $cfg = ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => $prefix, 'files_path' => $this->dir];

        $summary = $this->runPhases($source, $cfg);

        $this->assertSame($want['members'] ?? [[3, 1], [4, 4], [4, 'Weather Spotters']], $this->memberships(), json_encode($summary));
        $group = $this->database()->table('groups')->where('name_singular', 'Weather Spotters')->first();
        $this->assertNotNull($group, 'The board\'s own group is a Flarum group');
        $this->assertSame(array_key_exists('color', $want) ? $want['color'] : '#3366ff', $group->color);
        $this->assertSame(1, $summary['groups_created'] ?? 0);
        $this->assertSame($want['skipped'], $summary['groups_skipped'] ?? 0, 'The banned, unconfirmed, bots, ranks… are counted, not made into groups');

        if (! ($want['profiles'] ?? true)) {
            return;
        }
        $users = $this->database()->table('users')->whereIn('id', [3, 4, 5])->orderBy('id')->get(['id', 'avatar_url', 'birthday', 'show_age'])->keyBy('id');
        $this->assertNotEmpty($users[3]->avatar_url, 'Ada\'s picture is copied: '.json_encode($summary));
        $this->assertNull($users[4]->avatar_url);
        $this->assertSame(1, $summary['avatars'] ?? 0);
        $this->assertSame($want['ada'] ?? '1980-03-05', substr((string) $users[3]->birthday, 0, 10));
        $this->assertSame('1904-02-29', substr((string) $users[4]->birthday, 0, 10), 'A birthday with no year keeps its day');
        $this->assertFalse((bool) $users[4]->show_age, '… and shows no age');
        $this->assertNull($users[5]->birthday);
        if ($signed) {
            $sig = (string) $this->database()->table('users')->where('id', 3)->value('signature');
            $this->assertStringContainsString('<STRONG><s>**</s>Ada<e>**</e></STRONG> loves rain', $sig, 'Ada\'s signature, in the Markdown fof/signature reads: '.$sig);
            $this->assertSame(1, $summary['signatures'] ?? 0);
        }

        // Again: nothing new, nothing doubled, nobody's picture replaced.
        $before = [$this->memberships(), $this->database()->table('groups')->count(), $users[3]->avatar_url];
        $this->runPhases($source, $cfg, (int) $this->database()->table('importer_runs')->value('id'));
        $this->assertSame($before, [$this->memberships(), $this->database()->table('groups')->count(), $this->database()->table('users')->where('id', 3)->value('avatar_url')]);
    }

    #[Test]
    public function an_account_nobody_confirmed_is_never_made_an_admin()
    {
        $this->fixturePhpbb();
        $this->app();
        $this->database()->table('users')->where('id', 3)->update(['is_email_confirmed' => 0]);

        $this->runPhases('phpbb', ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => 'phpbb_', 'files_path' => $this->dir]);

        $this->assertSame([[4, 4], [4, 'Weather Spotters']], $this->memberships());
    }

    #[Test]
    public function a_group_with_the_name_of_one_here_is_that_group()
    {
        $this->fixturePhpbb();
        $this->app();
        $id = $this->database()->table('groups')->insertGetId(['name_singular' => 'Weather spotter', 'name_plural' => 'Weather Spotters', 'color' => '#000000', 'is_hidden' => 0]);

        $summary = $this->runPhases('phpbb', ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => 'phpbb_']);

        $this->assertSame(0, $summary['groups_created'] ?? 0);
        $this->assertTrue($this->database()->table('group_user')->where('user_id', 4)->where('group_id', $id)->exists());
    }

    #[Test]
    public function birthdays_and_signatures_are_counted_without_their_extensions_and_a_path_cannot_leave_the_folder()
    {
        $this->fixturePhpbb();
        $this->signature('phpbb');
        // A member whose stored file name climbs out of the avatar folder.
        $this->sql("UPDATE phpbb_users SET user_avatar = '../../../secret_12.png', user_avatar_type = 'avatar.driver.local' WHERE user_id = 12");
        $this->picture('secret_12.png');
        $this->extensions = ['ernestdefoe-importer'];

        $summary = $this->runPhases('phpbb', ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => 'phpbb_', 'files_path' => $this->dir]);

        $this->assertSame(2, $summary['birthdays_skipped'] ?? 0);
        $this->assertSame(1, $summary['signatures_skipped'] ?? 0);
        $this->assertTrue($this->database()->table('importer_map')->where('kind', 'profiles-retry')->exists(), 'A sync brings them once FoF Signature is on');
        $this->assertSame(1, $summary['avatars_missing'] ?? 0, 'The picture outside the gallery folder is not read');
        $this->assertNull($this->database()->table('users')->where('id', 5)->value('avatar_url'));
    }

    #[Test]
    public function a_photo_on_a_dead_host_is_a_member_without_a_photo_not_a_failed_import()
    {
        $this->fixturePhpbb();
        // A port nothing listens on: the connection is refused, as on a long-dead image host.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $dead = 'http://'.stream_socket_get_name($socket, false);
        fclose($socket);
        $this->sql("UPDATE phpbb_users SET user_avatar = '{$dead}/face.png', user_avatar_type = 'avatar.driver.remote' WHERE user_id = 12");
        $this->extensions = ['ernestdefoe-importer'];
        $cfg = ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => 'phpbb_', 'files_path' => $this->dir, 'assets_base' => $dead];

        $summary = $this->runPhases('phpbb', $cfg);

        $this->assertSame(1, $summary['avatars'] ?? 0, 'The members after it still get theirs');
        $this->assertSame(1, $summary['avatars_missing'] ?? 0);
        $this->assertNull($this->database()->table('users')->where('id', 5)->value('avatar_url'));
    }

    #[Test]
    public function a_photo_linked_on_the_old_site_is_read_from_its_folder_and_a_refused_one_is_retried()
    {
        $this->fixturePhpbb();
        // Bob linked a picture on the old site itself ("remote"): it is in the folder here.
        $this->picture('images/members/bob.png');
        // Cy's is not, and the old site turns this server away, as Cloudflare does.
        $router = $this->dir.'-router.php';
        file_put_contents($router, '<?php http_response_code(403); echo "Just a moment...";');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $addr = stream_socket_get_name($socket, false);
        fclose($socket);
        $server = proc_open([PHP_BINARY, '-S', $addr, $router], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50 && ! @fsockopen(...[...explode(':', $addr), null, null, 0.1]); $i++) {
            usleep(50_000);
        }
        $this->sql(
            "UPDATE phpbb_users SET user_avatar = 'https://{$addr}/forum/images/members/bob.png', user_avatar_type = 'avatar.driver.remote' WHERE user_id = 11",
            "UPDATE phpbb_users SET user_avatar = 'http://{$addr}/forum/faces/cy.png', user_avatar_type = 'avatar.driver.remote' WHERE user_id = 12",
        );
        $this->extensions = ['ernestdefoe-importer'];

        try {
            $summary = $this->runPhases('phpbb', ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => 'phpbb_', 'files_path' => $this->dir.'/', 'assets_base' => "http://{$addr}/forum"]);
        } finally {
            proc_terminate($server);
            @unlink($router);
        }

        $this->assertSame(2, $summary['avatars'] ?? 0, 'Ada\'s upload and Bob\'s linked picture: '.json_encode($summary));
        $this->assertNotEmpty($this->database()->table('users')->where('id', 4)->value('avatar_url'));
        $this->assertSame(1, $summary['avatars_refused'] ?? 0);
        $this->assertArrayNotHasKey('avatars_missing', $summary);
        $this->assertTrue($this->database()->table('importer_map')->where('kind', 'profiles-retry')->exists(), 'The next sync asks again');
    }

    /**
     * Ada's signature, "**Ada** loves rain", as each board keeps it (in its own
     * BBCode, HTML or Markdown). False for a board with no signatures.
     */
    private function signature(string $source): bool
    {
        $bb = "'[b]Ada[/b] loves rain'";
        $sql = match ($source) {
            'phpbb' => ['ALTER TABLE phpbb_users ADD COLUMN user_sig TEXT', 'ALTER TABLE phpbb_users ADD COLUMN user_sig_bbcode_uid TEXT', "UPDATE phpbb_users SET user_sig = '[b:x1]Ada[/b:x1] loves rain', user_sig_bbcode_uid = 'x1' WHERE user_id = 10"],
            'xenforo' => ['ALTER TABLE xf_user_profile ADD COLUMN signature TEXT', "UPDATE xf_user_profile SET signature = '[B]Ada[/B] loves rain' WHERE user_id = 10"],
            'vbulletin', 'vbulletin5' => ['CREATE TABLE usertextfield (userid INTEGER PRIMARY KEY, signature TEXT)', "INSERT INTO usertextfield VALUES (10, {$bb})"],
            'mybb' => ['ALTER TABLE mybb_users ADD COLUMN signature TEXT', "UPDATE mybb_users SET signature = {$bb} WHERE uid = 10"],
            'smf' => ['ALTER TABLE smf_members ADD COLUMN signature TEXT', "UPDATE smf_members SET signature = {$bb} WHERE id_member = 10"],
            'vanilla' => ['CREATE TABLE GDN_UserMeta (UserID INTEGER, Name TEXT, Value TEXT)', "INSERT INTO GDN_UserMeta VALUES (10, 'Plugin.Signatures.Sig', '**Ada** loves rain'), (10, 'Plugin.Signatures.Format', 'Markdown')"],
            'invision' => ['ALTER TABLE core_members ADD COLUMN signature TEXT', "UPDATE core_members SET signature = '<p><strong>Ada</strong> loves rain</p>' WHERE member_id = 10"],
            'discourse' => ['CREATE TABLE user_custom_fields (user_id INTEGER, name TEXT, value TEXT)', "INSERT INTO user_custom_fields VALUES (10, 'signature_raw', '**Ada** loves rain')"],
            'webwiz' => ['ALTER TABLE tblAuthor ADD COLUMN Signature TEXT', "UPDATE tblAuthor SET Signature = '<b>Ada</b> loves rain' WHERE Author_ID = 10"],
            default => [],
        };
        $this->sql(...$sql);

        return (bool) $sql;
    }

    /* ── One fixture per source ─────────────────────────────────────────── */

    private function fixturePhpbb(): array
    {
        $this->sql(
            'CREATE TABLE phpbb_groups (group_id INTEGER PRIMARY KEY, group_type INTEGER, group_name TEXT, group_colour TEXT)',
            'CREATE TABLE phpbb_user_group (group_id INTEGER, user_id INTEGER, user_pending INTEGER)',
            'CREATE TABLE phpbb_users (user_id INTEGER PRIMARY KEY, user_avatar TEXT, user_avatar_type TEXT, user_birthday TEXT)',
            'CREATE TABLE phpbb_config (config_name TEXT, config_value TEXT)',
            "INSERT INTO phpbb_config VALUES ('avatar_salt', 's4lt'), ('avatar_path', 'images/avatars/upload'), ('avatar_gallery_path', 'images/avatars/gallery')",
            "INSERT INTO phpbb_groups VALUES (1, 3, 'GUESTS', ''), (2, 3, 'REGISTERED', ''), (4, 3, 'GLOBAL_MODERATORS', '00AA00'), (5, 3, 'ADMINISTRATORS', 'AA0000'), (6, 3, 'BOTS', '9E8DA7'), (7, 0, 'Weather Spotters', '3366FF')",
            'INSERT INTO phpbb_user_group VALUES (2, 10, 0), (5, 10, 0), (2, 11, 0), (4, 11, 0), (7, 11, 0), (2, 12, 0), (7, 12, 1)',
            "INSERT INTO phpbb_users VALUES (10, '10_1600000000.png', 'avatar.driver.upload', ' 5- 3-1980'), (11, '', '', '29- 2-   0'), (12, '', '', '')",
        );
        $this->picture('images/avatars/upload/s4lt_10.png');

        return ['skipped' => 1];
    }

    private function fixtureXenforo(): array
    {
        $this->sql(
            'CREATE TABLE xf_user_group (user_group_id INTEGER PRIMARY KEY, title TEXT, username_css TEXT)',
            'CREATE TABLE xf_user (user_id INTEGER PRIMARY KEY, user_group_id INTEGER, secondary_group_ids TEXT, is_admin INTEGER, avatar_date INTEGER, gravatar TEXT)',
            'CREATE TABLE xf_user_profile (user_id INTEGER PRIMARY KEY, dob_day INTEGER, dob_month INTEGER, dob_year INTEGER)',
            "INSERT INTO xf_user_group VALUES (1, 'Unregistered / Unconfirmed', ''), (2, 'Registered', ''), (3, 'Administrative', ''), (4, 'Moderating', ''), (5, 'Weather Spotters', 'color: #3366FF; font-weight: bold;')",
            "INSERT INTO xf_user VALUES (10, 2, '3', 1, 1600000000, ''), (11, 2, '4,5', 0, 0, ''), (12, 2, '', 0, 0, '')",
            'INSERT INTO xf_user_profile VALUES (10, 5, 3, 1980), (11, 29, 2, 0), (12, 0, 0, 0)',
        );
        $this->picture('data/avatars/l/0/10.jpg');

        return ['skipped' => 0];
    }

    private function fixtureVbulletin(): array
    {
        $this->sql(
            'CREATE TABLE usergroup (usergroupid INTEGER PRIMARY KEY, title TEXT, opentag TEXT)',
            'CREATE TABLE user (userid INTEGER PRIMARY KEY, usergroupid INTEGER, membergroupids TEXT, birthday TEXT, avatarid INTEGER, avatarrevision INTEGER)',
            'CREATE TABLE customavatar (userid INTEGER PRIMARY KEY, filedata BLOB, filename TEXT)',
            "INSERT INTO usergroup VALUES (1, 'Unregistered / Not Logged In', ''), (2, 'Registered Users', ''), (3, 'Users Awaiting Email Confirmation', ''), (4, '(COPPA) Users Awaiting Moderation', ''), (5, 'Super Moderators', ''), (6, 'Administrators', '<span style=\"color:red\">'), (7, 'Moderators', ''), (8, 'Banned Users', ''), (9, 'Weather Spotters', '<span style=\"color: #3366ff\">')",
            "INSERT INTO user VALUES (10, 6, '', '03-05-1980', 0, 0), (11, 2, '7,9', '02-29-0000', 0, 0), (12, 2, '', '', 0, 0)",
        );
        $this->pdo->prepare('INSERT INTO customavatar VALUES (10, ?, ?)')->execute([base64_decode(self::PNG), 'ada.png']);

        return ['skipped' => 3];
    }

    private function fixtureVbulletin5(): array
    {
        return $this->fixtureVbulletin();
    }

    private function fixtureMybb(): array
    {
        $this->sql(
            'CREATE TABLE mybb_usergroups (gid INTEGER PRIMARY KEY, title TEXT, namestyle TEXT, cancp INTEGER, issupermod INTEGER)',
            'CREATE TABLE mybb_users (uid INTEGER PRIMARY KEY, usergroup INTEGER, additionalgroups TEXT, avatar TEXT, avatartype TEXT, birthday TEXT)',
            "INSERT INTO mybb_usergroups VALUES (1, 'Guests', '{username}', 0, 0), (2, 'Registered', '{username}', 0, 0), (3, 'Super Moderators', '', 0, 1), (4, 'Administrators', '', 1, 1), (5, 'Awaiting Activation', '', 0, 0), (6, 'Moderators', '', 0, 1), (7, 'Banned', '', 0, 0), (8, 'Weather Spotters', '<span style=\"color: #3366FF;\"><strong>{username}</strong></span>', 0, 0)",
            "INSERT INTO mybb_users VALUES (10, 4, '', './uploads/avatars/avatar_10.png?dateline=1600000000', 'upload', '5-3-1980'), (11, 2, '6,8', '', '', '29-2-'), (12, 2, '', '', '', '')",
        );
        $this->picture('uploads/avatars/avatar_10.png');

        return ['skipped' => 2];
    }

    private function fixtureSmf(): array
    {
        $this->sql(
            'CREATE TABLE smf_membergroups (id_group INTEGER PRIMARY KEY, group_name TEXT, online_color TEXT, min_posts INTEGER)',
            'CREATE TABLE smf_members (id_member INTEGER PRIMARY KEY, id_group INTEGER, additional_groups TEXT, avatar TEXT, birthdate TEXT)',
            'CREATE TABLE smf_attachments (id_attach INTEGER PRIMARY KEY, id_member INTEGER, id_msg INTEGER, id_folder INTEGER, attachment_type INTEGER, filename TEXT, file_hash TEXT)',
            'CREATE TABLE smf_settings (variable TEXT, value TEXT)',
            "INSERT INTO smf_settings VALUES ('attachmentUploadDir', '/home/old-server/forum/attachments')",
            "INSERT INTO smf_membergroups VALUES (1, 'Administrator', '#FF0000', -1), (2, 'Global Moderator', '#0000FF', -1), (3, 'Moderator', '', -1), (4, 'Newbie', '', 0), (9, 'Weather Spotters', '#3366FF', -1)",
            "INSERT INTO smf_members VALUES (10, 1, '', '', '1980-03-05'), (11, 0, '2,9', '', '0004-02-29'), (12, 0, '', '', '0001-01-01')",
            "INSERT INTO smf_attachments VALUES (1, 10, 0, 1, 0, 'ada.png', 'abc123')",
        );
        $this->picture('attachments/1_abc123.dat');

        return ['skipped' => 2];
    }

    private function fixtureVanilla(): array
    {
        $this->sql(
            'CREATE TABLE GDN_Role (RoleID INTEGER PRIMARY KEY, Name TEXT, Type TEXT)',
            'CREATE TABLE GDN_UserRole (UserID INTEGER, RoleID INTEGER)',
            'CREATE TABLE GDN_User (UserID INTEGER PRIMARY KEY, Photo TEXT, DateOfBirth TEXT)',
            "INSERT INTO GDN_Role VALUES (2, 'Guest', 'guest'), (3, 'Unconfirmed', 'unconfirmed'), (8, 'Member', 'member'), (16, 'Administrator', 'administrator'), (32, 'Moderator', 'moderator'), (40, 'Weather Spotters', NULL)",
            'INSERT INTO GDN_UserRole VALUES (10, 8), (10, 16), (11, 8), (11, 32), (11, 40), (12, 8)',
            "INSERT INTO GDN_User VALUES (10, 'userpics/10/ada.png', '1980-03-05'), (11, NULL, '1904-02-29'), (12, NULL, NULL)",
        );
        $this->picture('uploads/userpics/10/pada.png');

        // Vanilla has no colours, and keeps a birthday's year: Bob's is given as 1904.
        return ['skipped' => 1, 'color' => null];
    }

    private function fixtureInvision(): array
    {
        $this->sql(
            'CREATE TABLE core_groups (g_id INTEGER PRIMARY KEY, g_access_cp INTEGER, g_is_supmod INTEGER, prefix TEXT)',
            'CREATE TABLE core_sys_lang_words (lang_id INTEGER, word_app TEXT, word_key TEXT, word_default TEXT, word_custom TEXT)',
            'CREATE TABLE core_members (member_id INTEGER PRIMARY KEY, member_group_id INTEGER, mgroup_others TEXT, pp_main_photo TEXT, pp_photo_type TEXT, pp_gravatar TEXT, bday_day INTEGER, bday_month INTEGER, bday_year INTEGER)',
            "INSERT INTO core_groups VALUES (1, 0, 0, ''), (2, 0, 0, ''), (3, 0, 0, ''), (4, 1, 1, ''), (5, 0, 0, ''), (6, 0, 1, ''), (7, 0, 0, '<span style=''color:#3366ff''>')",
            "INSERT INTO core_sys_lang_words VALUES (1, 'core', 'core_group_1', 'Validating', NULL), (1, 'core', 'core_group_2', 'Guests', NULL), (1, 'core', 'core_group_3', 'Members', NULL), (1, 'core', 'core_group_4', 'Administrators', NULL), (1, 'core', 'core_group_5', 'Banned', NULL), (1, 'core', 'core_group_6', 'Moderators', NULL), (1, 'core', 'core_group_7', 'Spotters', 'Weather Spotters')",
            "INSERT INTO core_members VALUES (10, 4, '', 'monthly_2020_09/ada.png', 'custom', '', 5, 3, 1980), (11, 3, ',6,7,', '', 'none', '', 29, 2, NULL), (12, 3, '', '', 'none', '', NULL, NULL, NULL)",
        );
        $this->picture('uploads/monthly_2020_09/ada.png');

        return ['skipped' => 2];
    }

    private function fixtureDiscourse(): array
    {
        $this->sql(
            'CREATE TABLE groups (id INTEGER PRIMARY KEY, name TEXT, full_name TEXT, automatic INTEGER, flair_bg_color TEXT)',
            'CREATE TABLE group_users (group_id INTEGER, user_id INTEGER)',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, uploaded_avatar_id INTEGER, date_of_birth TEXT)',
            'CREATE TABLE uploads (id INTEGER PRIMARY KEY, url TEXT)',
            "INSERT INTO groups VALUES (0, 'everyone', NULL, 1, NULL), (1, 'admins', NULL, 1, NULL), (2, 'moderators', NULL, 1, NULL), (3, 'staff', NULL, 1, NULL), (10, 'trust_level_0', NULL, 1, NULL), (41, 'weather_spotters', 'Weather Spotters', 0, '3366ff')",
            'INSERT INTO group_users VALUES (1, 10), (3, 10), (10, 10), (2, 11), (3, 11), (41, 11), (10, 12)',
            "INSERT INTO users VALUES (-1, NULL, NULL), (10, 7, '1904-03-05'), (11, NULL, '1904-02-29'), (12, NULL, NULL)",
            "INSERT INTO uploads VALUES (7, '/uploads/default/original/1X/ada.png')",
        );
        $this->picture('public/uploads/default/original/1X/ada.png');

        // Discourse keeps no year.
        return ['skipped' => 2, 'ada' => '1904-03-05'];
    }

    private function fixtureConvoro(): array
    {
        $this->sql(
            'CREATE TABLE groups (id INTEGER PRIMARY KEY, name TEXT, is_default INTEGER, is_admin INTEGER, is_moderator INTEGER, is_guest INTEGER)',
            'CREATE TABLE group_members (id INTEGER PRIMARY KEY, user_id INTEGER, group_id INTEGER)',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, deleted_at TEXT)',
            "INSERT INTO groups VALUES (1, 'Administrators', 0, 1, 0, 0), (2, 'Moderators', 0, 0, 1, 0), (3, 'Members', 1, 0, 0, 0), (4, 'Guests', 0, 0, 0, 1), (5, 'Weather Spotters', 0, 0, 0, 0)",
            'INSERT INTO group_members (user_id, group_id) VALUES (10, 1), (10, 3), (11, 2), (11, 5), (11, 3), (12, 3)',
            'INSERT INTO users VALUES (10, NULL), (11, NULL), (12, NULL)',
        );

        // Profile photos come with Convoro's members, and it keeps no birthdays.
        return ['skipped' => 0, 'color' => null, 'profiles' => false];
    }

    private function fixtureWebwiz(): array
    {
        $this->sql(
            'CREATE TABLE tblGroup (Group_ID INTEGER PRIMARY KEY, Name TEXT, Special_rank INTEGER)',
            'CREATE TABLE tblAuthor (Author_ID INTEGER PRIMARY KEY, Username TEXT, Group_ID INTEGER, Avatar TEXT, DOB TEXT)',
            "INSERT INTO tblGroup VALUES (1, 'Admin Group', 1), (2, 'Guest Group', 1), (3, 'Moderator Group', 1), (4, 'Newbie', 0), (5, 'Weather Spotters', 1)",
            "INSERT INTO tblAuthor VALUES (10, 'ada', 1, 'avatars/ada.png', '1980-03-05 00:00:00'), (11, 'bob', 3, '', '1904-02-29 00:00:00'), (12, 'cy', 5, '', NULL)",
        );
        $this->picture('avatars/ada.png');

        // One group per member on Web Wiz: Cy is the spotter here, and Web Wiz keeps the year.
        return ['skipped' => 1, 'color' => null, 'members' => [[3, 1], [4, 4], [5, 'Weather Spotters']]];
    }
}
