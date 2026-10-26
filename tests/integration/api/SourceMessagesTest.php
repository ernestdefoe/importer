<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use ErnestDefoe\Importer\Tests\integration\MessagesFixture;
use Flarum\Formatter\Formatter;
use Flarum\Testing\integration\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every source's private messages, read from its own tables, with Group
 * Messages installed. Each fixture tells the same story in its board's terms:
 *
 *  - Ada and Bob write to each other (Ada at T, Bob at T+100);
 *  - Ada writes to Bob and Cy together, "Plans" (T+200);
 *  - Ada writes to Dee, and both of them delete it (T+300);
 *  - an account the old board has since deleted (99) writes to Ada (T+400).
 */
class SourceMessagesTest extends TestCase
{
    use MessagesFixture;

    private const T = 1600000000;

    private string $file;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-messages', 'ernestdefoe-group-messages', 'ernestdefoe-importer');
        $this->prepareDatabase(['users' => $this->members()]);

        $this->file = tempnam(sys_get_temp_dir(), 'importer-pm-').'.sqlite';
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
            'MyBB' => ['mybb', 'mybb_'],
            'SMF' => ['smf', 'smf_'],
            'vBulletin 3/4' => ['vbulletin', ''],
            'vBulletin 5' => ['vbulletin5', ''],
            'Invision' => ['invision', ''],
            'Discourse' => ['discourse', ''],
            'Vanilla' => ['vanilla', 'GDN_'],
            'Web Wiz' => ['webwiz', 'tbl'],
            'Convoro' => ['convoro', ''],
        ];
    }

    #[Test]
    #[DataProvider('sources')]
    public function a_source_s_messages_arrive_with_the_right_people(string $source, string $prefix)
    {
        $this->{'fixture'.ucfirst($source)}();
        $cfg = ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => $prefix];

        $summary = $this->runMessages($source, $cfg);

        $direct = $this->find('direct', [3, 4]);
        $this->assertNotNull($direct, json_encode($this->dialogs()));
        $this->assertSame([[3, 'Hello Bob', $this->date(0)], [4, 'Hi Ada', $this->date(100)]], $direct[3], 'Order, authors and times kept');

        $departed = $this->find('direct', [3]);
        $this->assertSame([[null, 'From the departed', $this->date(400)]], $departed[3] ?? null, 'A deleted author shows as a deleted member, as on posts');

        $this->assertNull($this->find('direct', [3, 6]), 'Deleted by both: not imported');
        $this->assertSame(0, $this->database()->table('dialog_user')->where('user_id', 6)->count());

        if ($source !== 'webwiz') { // Web Wiz messages are always between two people
            $group = $this->find('group', [3, 4, 5]);
            $this->assertNotNull($group, json_encode($this->dialogs()));
            $this->assertSame('Plans', $group[2]);
            $this->assertSame([[3, 'Group hello', $this->date(200)]], $group[3]);
            $this->assertSame(3, $this->database()->table('group_dialogs')->where('dialog_id', $this->dialogId('group', [3, 4, 5]))->value('owner_id'));
        }
        $this->assertSame($source === 'webwiz' ? 2 : 3, $summary['conversations']);
        $this->assertSame($source === 'webwiz' ? 3 : 4, $summary['messages']);

        // Again: nothing new, nothing doubled.
        $before = $this->dialogs();
        $this->runMessages($source, $cfg, (int) $this->database()->table('importer_runs')->value('id'));
        $this->assertSame($before, $this->dialogs());
    }

    public static function formatted(): array
    {
        $out = [];
        foreach (self::sources() as $name => $args) {
            foreach (['Markdown' => ['flarum-markdown'], 'BBCode' => ['flarum-bbcode'], 'Scribe' => ['ernestdefoe-scribe']] as $editor => $exts) {
                $out["$name → $editor"] = [...$args, $exts];
            }
        }

        return $out;
    }

    /** A message's formatting is written for the forum's editor, exactly as a post's is. */
    #[Test]
    #[DataProvider('formatted')]
    public function a_source_s_messages_keep_their_formatting(string $source, string $prefix, array $editor)
    {
        $this->extension(...$editor);
        $this->{'fixture'.ucfirst($source)}();
        $this->runMessages($source, ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => $prefix]);

        $id = $this->dialogId('direct', [3, 4]);
        $first = $this->database()->table('dialog_messages')->where('dialog_id', $id)->orderBy('number')->value('content');
        $html = $this->app()->getContainer()->make(Formatter::class)->render((string) $first, null);
        $this->assertMatchesRegularExpression('#<(strong|b)>Hello</\1> Bob#', $html, (string) $first);
    }

    private function find(string $type, array $members): ?array
    {
        foreach ($this->dialogs() as $d) {
            if ($d[0] === $type && $d[1] === $members) {
                return $d;
            }
        }

        return null;
    }

    private function dialogId(string $type, array $members): ?int
    {
        foreach ($this->database()->table('dialogs')->where('type', $type)->pluck('id') as $id) {
            if ($this->database()->table('dialog_user')->where('dialog_id', $id)->orderBy('user_id')->pluck('user_id')->map(fn ($u) => (int) $u)->all() === $members) {
                return (int) $id;
            }
        }

        return null;
    }

    private function fixturePhpbb(): void
    {
        $t = self::T;
        $this->sql(
            'CREATE TABLE phpbb_privmsgs (msg_id INTEGER PRIMARY KEY, root_level INTEGER, author_id INTEGER, message_time INTEGER, message_subject TEXT, message_text TEXT, bbcode_uid TEXT, to_address TEXT, bcc_address TEXT)',
            'CREATE TABLE phpbb_privmsgs_to (msg_id INTEGER, user_id INTEGER, author_id INTEGER, pm_deleted INTEGER, pm_new INTEGER, pm_unread INTEGER, folder_id INTEGER)',
            "INSERT INTO phpbb_privmsgs VALUES (1, 0, 10, $t, 'Hi', '[b]Hello[/b] Bob', '', 'u_11', ''), (2, 1, 11, $t + 100, 'Re: Hi', 'Hi Ada', '', 'u_10', ''),
                (3, 0, 10, $t + 200, 'Plans', 'Group hello', '', 'u_11:u_12', ''), (4, 0, 10, $t + 300, 'Gone', 'Gone', '', 'u_13', ''),
                (5, 0, 99, $t + 400, 'Boo', 'From the departed', '', 'u_10', '')",
            'INSERT INTO phpbb_privmsgs_to VALUES (1, 10, 10, 0, 0, 0, -1), (1, 11, 10, 0, 0, 0, 0), (2, 11, 11, 0, 0, 0, -1), (2, 10, 11, 0, 0, 1, 0),
                (3, 10, 10, 0, 0, 0, -1), (3, 11, 10, 0, 0, 0, 0), (3, 12, 10, 0, 0, 0, 0), (5, 10, 99, 0, 0, 0, 0)',
            // Withdrawn from the outbox before Dee read it: her copy is marked deleted, Ada's is gone.
            'INSERT INTO phpbb_privmsgs_to VALUES (4, 13, 10, 1, 1, 1, -3)',
        );
    }

    private function fixtureXenforo(): void
    {
        $t = self::T;
        $this->sql(
            'CREATE TABLE xf_conversation_master (conversation_id INTEGER PRIMARY KEY, title TEXT, user_id INTEGER, start_date INTEGER)',
            'CREATE TABLE xf_conversation_message (message_id INTEGER PRIMARY KEY, conversation_id INTEGER, user_id INTEGER, message TEXT, message_date INTEGER)',
            'CREATE TABLE xf_conversation_recipient (conversation_id INTEGER, user_id INTEGER, recipient_state TEXT, last_read_date INTEGER)',
            "INSERT INTO xf_conversation_master VALUES (1, 'Hi', 10, $t), (2, 'Plans', 10, $t + 200), (3, 'Gone', 10, $t + 300), (4, 'Boo', 99, $t + 400)",
            "INSERT INTO xf_conversation_message VALUES (1, 1, 10, '[B]Hello[/B] Bob', $t), (2, 1, 11, 'Hi Ada', $t + 100), (3, 2, 10, 'Group hello', $t + 200),
                (4, 3, 10, 'Gone', $t + 300), (5, 4, 99, 'From the departed', $t + 400)",
            "INSERT INTO xf_conversation_recipient VALUES (1, 10, 'active', $t + 100), (1, 11, 'active', $t + 100), (2, 10, 'active', 0), (2, 11, 'active', 0), (2, 12, 'active', 0),
                (3, 10, 'deleted', 0), (3, 13, 'deleted_ignored', 0), (4, 10, 'active', 0)",
        );
    }

    private function fixtureMybb(): void
    {
        $t = self::T;
        $to = fn (array $ids) => $this->pdo->quote(serialize(['to' => $ids]));
        $this->sql(
            'CREATE TABLE mybb_privatemessages (pmid INTEGER PRIMARY KEY, uid INTEGER, toid INTEGER, fromid INTEGER, recipients TEXT, folder INTEGER, subject TEXT, message TEXT, dateline INTEGER, deletetime INTEGER, status INTEGER)',
            // One copy per member: the recipient's inbox copy and the sender's sent copy.
            "INSERT INTO mybb_privatemessages VALUES
                (1, 11, 11, 10, {$to([11])}, 1, 'Hi', '[b]Hello[/b] Bob', $t, 0, 1), (2, 10, 11, 10, {$to([11])}, 2, 'Hi', '[b]Hello[/b] Bob', $t, 0, 1),
                (3, 10, 10, 11, {$to([10])}, 1, 'Re: Hi', 'Hi Ada', $t + 100, 0, 0), (4, 11, 10, 11, {$to([10])}, 2, 'Re: Hi', 'Hi Ada', $t + 100, 0, 1),
                (5, 11, 11, 10, {$to([11, 12])}, 1, 'Plans', 'Group hello', $t + 200, 0, 1), (6, 12, 12, 10, {$to([11, 12])}, 1, 'Plans', 'Group hello', $t + 200, 0, 1),
                (7, 10, 0, 10, {$to([11, 12])}, 2, 'Plans', 'Group hello', $t + 200, 0, 1),
                (8, 13, 13, 10, {$to([13])}, 4, 'Gone', 'Gone', $t + 300, $t + 350, 1), (9, 10, 13, 10, {$to([13])}, 4, 'Gone', 'Gone', $t + 300, $t + 350, 1),
                (10, 10, 10, 99, {$to([10])}, 1, 'Boo', 'From the departed', $t + 400, 0, 1),
                (11, 10, 0, 10, {$to([11])}, 3, 'Draft', 'Never sent', $t + 500, 0, 0)",
        );
    }

    private function fixtureSmf(): void
    {
        $t = self::T;
        $this->sql(
            'CREATE TABLE smf_personal_messages (id_pm INTEGER PRIMARY KEY, id_pm_head INTEGER, id_member_from INTEGER, deleted_by_sender INTEGER, from_name TEXT, msgtime INTEGER, subject TEXT, body TEXT)',
            'CREATE TABLE smf_pm_recipients (id_pm INTEGER, id_member INTEGER, labels TEXT, bcc INTEGER, is_read INTEGER, is_new INTEGER, deleted INTEGER)',
            "INSERT INTO smf_personal_messages VALUES (1, 1, 10, 0, 'Ada', $t, 'Hi', '[b]Hello[/b] Bob'), (2, 1, 11, 0, 'Bob', $t + 100, 'Re: Hi', 'Hi Ada'),
                (3, 3, 10, 0, 'Ada', $t + 200, 'Plans', 'Group hello'), (4, 4, 10, 1, 'Ada', $t + 300, 'Gone', 'Gone'), (5, 5, 99, 0, 'Gone', $t + 400, 'Boo', 'From the departed')",
            "INSERT INTO smf_pm_recipients VALUES (1, 11, '', 0, 1, 0, 0), (2, 10, '', 0, 0, 1, 0), (3, 11, '', 0, 1, 0, 0), (3, 12, '', 0, 1, 0, 0),
                (4, 13, '', 0, 1, 0, 1), (5, 10, '', 0, 1, 0, 0)",
        );
    }

    private function fixtureVbulletin(): void
    {
        $t = self::T;
        $q = fn (array $a) => $this->pdo->quote(serialize($a));
        $this->sql(
            'CREATE TABLE pmtext (pmtextid INTEGER PRIMARY KEY, fromuserid INTEGER, fromusername TEXT, title TEXT, message TEXT, touserarray TEXT, dateline INTEGER)',
            'CREATE TABLE pm (pmid INTEGER PRIMARY KEY, pmtextid INTEGER, userid INTEGER, folderid INTEGER, messageread INTEGER, parentpmid INTEGER)',
            // vBulletin 3's [id => name] and 3.7+'s ['cc' => [id => name]] side by side.
            "INSERT INTO pmtext VALUES (1, 10, 'Ada', 'Hi', '[B]Hello[/B] Bob', {$q([11 => 'Bob'])}, $t), (2, 11, 'Bob', 'Re: Hi', 'Hi Ada', {$q(['cc' => [10 => 'Ada']])}, $t + 100),
                (3, 10, 'Ada', 'Plans', 'Group hello', {$q(['cc' => [11 => 'Bob', 12 => 'Cy']])}, $t + 200), (4, 10, 'Ada', 'Gone', 'Gone', {$q([13 => 'Dee'])}, $t + 300),
                (5, 99, 'Gone', 'Boo', 'From the departed', {$q([10 => 'Ada'])}, $t + 400)",
            'INSERT INTO pm VALUES (1, 1, 10, -1, 1, 0), (2, 1, 11, 0, 1, 0), (3, 2, 11, -1, 1, 0), (4, 2, 10, 0, 0, 0), (5, 3, 10, -1, 1, 0), (6, 3, 11, 0, 1, 0), (7, 3, 12, 0, 1, 0), (8, 5, 10, 0, 1, 0)',
        );
    }

    private function fixtureVbulletin5(): void
    {
        $t = self::T;
        $this->sql(
            'CREATE TABLE contenttype (contenttypeid INTEGER PRIMARY KEY, class TEXT)',
            'CREATE TABLE node (nodeid INTEGER PRIMARY KEY, contenttypeid INTEGER, parentid INTEGER, starter INTEGER, userid INTEGER, title TEXT, created INTEGER, publishdate INTEGER, approved INTEGER)',
            'CREATE TABLE text (nodeid INTEGER PRIMARY KEY, rawtext TEXT, htmlstate TEXT)',
            'CREATE TABLE privatemessage (nodeid INTEGER PRIMARY KEY, msgtype TEXT, about TEXT, aboutid INTEGER)',
            'CREATE TABLE sentto (nodeid INTEGER, userid INTEGER, folderid INTEGER, msgread INTEGER, deleted INTEGER)',
            "INSERT INTO contenttype VALUES (1, 'Channel'), (2, 'PrivateMessage')",
            "INSERT INTO node VALUES (1, 2, 50, 1, 10, 'Hi', $t, $t, 1), (2, 2, 1, 1, 11, '', $t + 100, $t + 100, 1), (3, 2, 50, 3, 10, 'Plans', $t + 200, $t + 200, 1),
                (4, 2, 50, 4, 10, 'Gone', $t + 300, $t + 300, 1), (5, 2, 50, 5, 99, 'Boo', $t + 400, $t + 400, 1), (6, 2, 50, 6, 10, 'Notice', $t + 500, $t + 500, 1)",
            "INSERT INTO text VALUES (1, '[B]Hello[/B] Bob', 'off'), (2, 'Hi Ada', 'off'), (3, 'Group hello', 'off'), (4, 'Gone', 'off'), (5, 'From the departed', 'off'), (6, 'A system notice', 'off')",
            "INSERT INTO privatemessage VALUES (1, 'message', '', 0), (2, 'message', '', 0), (3, 'message', '', 0), (4, 'message', '', 0), (5, 'message', '', 0), (6, 'notification', 'vm', 0)",
            'INSERT INTO sentto VALUES (1, 10, 1, 1, 0), (1, 11, 2, 1, 0), (2, 10, 2, 0, 0), (2, 11, 1, 1, 0), (3, 10, 1, 1, 0), (3, 11, 2, 1, 0), (3, 12, 3, 1, 0),
                (4, 10, 1, 1, 1), (4, 13, 4, 1, 1), (5, 10, 2, 1, 0), (6, 10, 2, 0, 0)',
        );
    }

    private function fixtureInvision(): void
    {
        $t = self::T;
        $this->sql(
            'CREATE TABLE core_message_topics (mt_id INTEGER PRIMARY KEY, mt_title TEXT, mt_date INTEGER, mt_starter_id INTEGER, mt_is_draft INTEGER)',
            'CREATE TABLE core_message_posts (msg_id INTEGER PRIMARY KEY, msg_topic_id INTEGER, msg_date INTEGER, msg_post TEXT, msg_author_id INTEGER)',
            'CREATE TABLE core_message_topic_user_map (map_id INTEGER PRIMARY KEY, map_user_id INTEGER, map_topic_id INTEGER, map_read_time INTEGER, map_user_active INTEGER)',
            "INSERT INTO core_message_topics VALUES (1, 'Hi', $t, 10, 0), (2, 'Plans', $t + 200, 10, 0), (3, 'Gone', $t + 300, 10, 0), (4, 'Boo', $t + 400, 99, 0), (5, 'Draft', $t + 500, 10, 1)",
            "INSERT INTO core_message_posts VALUES (1, 1, $t, '<p><strong>Hello</strong> Bob</p>', 10), (2, 1, $t + 100, '<p>Hi Ada</p>', 11), (3, 2, $t + 200, '<p>Group hello</p>', 10),
                (4, 3, $t + 300, '<p>Gone</p>', 10), (5, 4, $t + 400, '<p>From the departed</p>', 99), (6, 5, $t + 500, '<p>Never sent</p>', 10)",
            "INSERT INTO core_message_topic_user_map VALUES (1, 10, 1, $t + 100, 1), (2, 11, 1, $t + 100, 1), (3, 10, 2, 0, 1), (4, 11, 2, 0, 1), (5, 12, 2, 0, 1),
                (6, 10, 3, 0, 0), (7, 13, 3, 0, 0), (8, 10, 4, 0, 1), (9, 10, 5, 0, 1)",
        );
    }

    private function fixtureDiscourse(): void
    {
        $d = fn ($o) => $this->pdo->quote($this->date($o));
        $this->sql(
            'CREATE TABLE topics (id INTEGER PRIMARY KEY, title TEXT, archetype TEXT, user_id INTEGER, deleted_at TEXT)',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, topic_id INTEGER, user_id INTEGER, post_number INTEGER, cooked TEXT, created_at TEXT, deleted_at TEXT, post_type INTEGER)',
            'CREATE TABLE topic_allowed_users (id INTEGER PRIMARY KEY, topic_id INTEGER, user_id INTEGER)',
            'CREATE TABLE topic_users (topic_id INTEGER, user_id INTEGER, last_read_post_number INTEGER)',
            "INSERT INTO topics VALUES (1, 'Hi', 'private_message', 10, NULL), (2, 'Plans', 'private_message', 10, NULL), (3, 'Gone', 'private_message', 10, NULL),
                (4, 'Boo', 'private_message', 99, NULL), (5, 'Welcome!', 'private_message', -1, NULL), (6, 'A topic', 'regular', 10, NULL)",
            "INSERT INTO posts VALUES (1, 1, 10, 1, '<p><strong>Hello</strong> Bob</p>', {$d(0)}, NULL, 1), (2, 1, 11, 2, '<p>Hi Ada</p>', {$d(100)}, NULL, 1),
                (3, 2, 10, 1, '<p>Group hello</p>', {$d(200)}, NULL, 1), (4, 3, 10, 1, '<p>Gone</p>', {$d(300)}, NULL, 1),
                (5, 4, 99, 1, '<p>From the departed</p>', {$d(400)}, NULL, 1), (6, 5, -1, 1, '<p>Welcome</p>', {$d(500)}, NULL, 1),
                (7, 6, 10, 1, '<p>Public</p>', {$d(600)}, NULL, 1), (8, 1, 10, 3, '<p>Whisper</p>', {$d(700)}, NULL, 4)",
            // Both removed themselves from "Gone"; the welcome message went to Ada.
            'INSERT INTO topic_allowed_users (topic_id, user_id) VALUES (1, 10), (1, 11), (2, 10), (2, 11), (2, 12), (4, 99), (4, 10), (5, 10), (5, -1)',
            'INSERT INTO topic_users VALUES (1, 10, 1), (1, 11, 2)',
        );
    }

    private function fixtureVanilla(): void
    {
        $d = fn ($o) => $this->pdo->quote($this->date($o));
        $this->sql(
            'CREATE TABLE GDN_Conversation (ConversationID INTEGER PRIMARY KEY, Subject TEXT, InsertUserID INTEGER)',
            'CREATE TABLE GDN_ConversationMessage (MessageID INTEGER PRIMARY KEY, ConversationID INTEGER, Body TEXT, Format TEXT, InsertUserID INTEGER, DateInserted TEXT)',
            'CREATE TABLE GDN_UserConversation (UserID INTEGER, ConversationID INTEGER, CountReadMessages INTEGER, DateLastViewed TEXT, DateCleared TEXT, Deleted INTEGER)',
            "INSERT INTO GDN_Conversation VALUES (1, NULL, 10), (2, 'Plans', 10), (3, NULL, 10), (4, NULL, 99)",
            "INSERT INTO GDN_ConversationMessage VALUES (1, 1, '**Hello** Bob', 'Markdown', 10, {$d(0)}), (2, 1, 'Hi Ada', 'Markdown', 11, {$d(100)}),
                (3, 2, '<p>Group hello</p>', 'Html', 10, {$d(200)}), (4, 3, 'Gone', 'Text', 10, {$d(300)}), (5, 4, 'From the departed', 'BBCode', 99, {$d(400)})",
            // Ada cleared "Gone" after it arrived; Dee left it.
            "INSERT INTO GDN_UserConversation VALUES (10, 1, 2, {$d(100)}, NULL, 0), (11, 1, 2, {$d(100)}, NULL, 0), (10, 2, 0, NULL, NULL, 0), (11, 2, 0, NULL, NULL, 0),
                (12, 2, 0, NULL, NULL, 0), (10, 3, 1, {$d(300)}, {$d(350)}, 0), (13, 3, 0, NULL, NULL, 1), (10, 4, 0, NULL, NULL, 0)",
        );
    }

    private function fixtureWebwiz(): void
    {
        $t = self::T;
        $this->sql(
            // The board's own tables, which the import's other phases are built from.
            'CREATE TABLE tblTopic (Topic_ID INTEGER PRIMARY KEY, Forum_ID INTEGER, Subject TEXT, Author_ID INTEGER, Start_date TEXT)',
            'CREATE TABLE tblThread (Thread_ID INTEGER PRIMARY KEY, Topic_ID INTEGER, Author_ID INTEGER, Message TEXT, Message_date TEXT)',
            'CREATE TABLE tblPMMessage (PM_ID INTEGER PRIMARY KEY, Author_ID INTEGER, From_ID INTEGER, PM_Tittle TEXT, PM_Message TEXT, PM_Message_Date TEXT, Read_Post INTEGER, Email_notify INTEGER, Inbox INTEGER, Outbox INTEGER)',
            "INSERT INTO tblPMMessage VALUES (1, 11, 10, 'Hi', '<strong>Hello</strong> Bob', $t, 1, 0, 1, 1), (2, 10, 11, 'Re: Hi', 'Hi Ada', $t + 100, 0, 0, 1, 1),
                (4, 13, 10, 'Gone', 'Gone', $t + 300, 1, 0, 0, 0), (5, 10, 99, 'Boo', 'From the departed', $t + 400, 1, 0, 1, 1)",
        );
    }

    private function fixtureConvoro(): void
    {
        $d = fn ($o) => $this->pdo->quote($this->date($o));
        $this->sql(
            'CREATE TABLE conversations (id INTEGER PRIMARY KEY, subject TEXT, creator_id INTEGER)',
            'CREATE TABLE conversation_participants (id INTEGER PRIMARY KEY, conversation_id INTEGER, user_id INTEGER, last_read_at TEXT, has_left INTEGER)',
            'CREATE TABLE messages (id INTEGER PRIMARY KEY, conversation_id INTEGER, user_id INTEGER, content_html TEXT, created_at TEXT)',
            "INSERT INTO conversations VALUES (1, 'Hi', 10), (2, 'Plans', 10), (3, 'Gone', 10), (4, 'Boo', 99)",
            "INSERT INTO conversation_participants (conversation_id, user_id, last_read_at, has_left) VALUES (1, 10, {$d(100)}, 0), (1, 11, {$d(100)}, 0),
                (2, 10, NULL, 0), (2, 11, NULL, 0), (2, 12, NULL, 0), (3, 10, NULL, 1), (3, 13, NULL, 1), (4, 10, NULL, 0)",
            "INSERT INTO messages VALUES (1, 1, 10, '<p><strong>Hello</strong> Bob</p>', {$d(0)}), (2, 1, 11, '<p>Hi Ada</p>', {$d(100)}), (3, 2, 10, '<p>Group hello</p>', {$d(200)}),
                (4, 3, 10, '<p>Gone</p>', {$d(300)}), (5, 4, 99, '<p>From the departed</p>', {$d(400)})",
        );
    }
}
