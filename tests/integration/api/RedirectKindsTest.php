<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use ErnestDefoe\Importer\Redirects\Patterns;
use ErnestDefoe\Importer\Redirects\Resolver;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\TestCase;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every kind of old address, for every source: discussions, single posts and
 * a post's position in its topic, forums by id or by slug, profiles by id or
 * by name, private messages and conversations, polls and the front page.
 *
 * The map is the one an import of each board writes: topic 123 → discussion
 * 1, post 456 → its post #2, forum 4 (slug "general") → the tag, member 9
 * ("Ada") → user 3, message 42 → the 2nd message of conversation 12, poll 7 →
 * a poll on post #2.
 */
class RedirectKindsTest extends TestCase
{
    private const D = '/d/1';
    private const POST = '/d/1/2';
    private const TAG = '/t/lounge';
    private const USER = '/u/Ada';
    private const PM = '/messages/dialog/1/2';
    private const CONV = '/messages/dialog/1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-messages', 'fof-polls', 'ernestdefoe-importer');
        $at = '2020-01-01 00:00:00';
        $this->prepareDatabase([
            'users' => [['id' => 3, 'username' => 'Ada', 'email' => 'ada@x.example', 'is_email_confirmed' => 1, 'password' => '']],
            'discussions' => [['id' => 1, 'title' => 'Hello', 'slug' => 'hello', 'created_at' => $at, 'first_post_id' => 1, 'comment_count' => 2]],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => $at, 'user_id' => 3, 'type' => 'comment', 'content' => '<t></t>'],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'created_at' => $at, 'user_id' => 3, 'type' => 'comment', 'content' => '<t></t>'],
            ],
            'tags' => [['id' => 50, 'name' => 'Lounge', 'slug' => 'lounge', 'position' => 0]],
            'dialogs' => [['id' => 1, 'type' => 'direct', 'created_at' => $at]],
            'dialog_messages' => [
                ['id' => 10, 'dialog_id' => 1, 'user_id' => 3, 'content' => '<t></t>', 'number' => 1, 'created_at' => $at],
                ['id' => 11, 'dialog_id' => 1, 'user_id' => 3, 'content' => '<t></t>', 'number' => 2, 'created_at' => $at],
            ],
            'polls' => [['id' => 70, 'question' => 'Q', 'post_id' => 2, 'user_id' => 3, 'settings' => '{}', 'created_at' => $at, 'updated_at' => $at]],
            'importer_runs' => [['id' => 1, 'source' => 'test', 'state' => '{}', 'status' => 'done']],
            'importer_map' => array_map(fn ($r) => ['run_id' => 1, 'kind' => $r[0], 'source_id' => $r[1], 'target_id' => $r[2]], [
                ['topic', '123', 1], ['post', '456', 2], ['tag', '4', 50], ['tag-slug', 'lounge', 50], ['tag-slug', 'forum/lounge', 50],
                ['user', '9', 3], ['pm', '42', 11], ['pm-copy', '43', 11], ['pm-conv', '12', 1], ['poll', '7', 70],
            ]),
        ]);
    }

    public static function addresses(): array
    {
        $D = self::D;
        $P = self::POST;
        $T = self::TAG;
        $U = self::USER;

        return [
            'phpBB topic' => ['phpbb', '/forum/viewtopic.php?f=2&t=123', $D],
            'phpBB post' => ['phpbb', '/viewtopic.php?f=2&t=123&p=456', $P],
            'phpBB post alone' => ['phpbb', '/viewtopic.php?p=456', $P],
            'phpBB forum' => ['phpbb', '/viewforum.php?f=4', $T],
            'phpBB profile' => ['phpbb', '/memberlist.php?mode=viewprofile&u=9', $U],
            'phpBB message' => ['phpbb', '/ucp.php?i=pm&mode=view&f=0&p=42', self::PM],
            'phpBB SEO topic' => ['phpbb', '/general-f4/hello-t123.html', $D],
            'phpBB SEO post' => ['phpbb', '/post456.html', $P],
            'phpBB front page' => ['phpbb', '/index.php', '/'],

            'XenForo thread' => ['xenforo', '/threads/hello.123/page-2', $D],
            'XenForo post in thread' => ['xenforo', '/threads/hello.123/post-456', $P],
            'XenForo post' => ['xenforo', '/posts/456/', $P],
            'XenForo forum' => ['xenforo', '/forums/general.4/', $T],
            'XenForo member' => ['xenforo', '/members/ada.9/', $U],
            'XenForo conversation' => ['xenforo', '/conversations/hi-there.12/', self::CONV],
            'XenForo direct message' => ['xenforo', '/direct-messages/hi.12/', self::CONV],
            'XenForo message' => ['xenforo', '/conversations/messages/42/', self::PM],

            'vB4 thread' => ['vbulletin', '/threads/123-hello', $D],
            'vB4 thread page' => ['vbulletin', '/threads/123-hello/page3', $D],
            'vB4 post in thread' => ['vbulletin', '/threads/123-hello?p=456#post456', $P],
            'vB3 thread' => ['vbulletin', '/showthread.php?t=123', $D],
            'vB4 basic thread' => ['vbulletin', '/showthread.php?123-hello', $D],
            'vB3 post' => ['vbulletin', '/showpost.php?p=456&postcount=2', $P],
            'vB3 thread post' => ['vbulletin', '/showthread.php?p=456', $P],
            'vB3 forum' => ['vbulletin', '/forumdisplay.php?f=4', $T],
            'vB4 basic forum' => ['vbulletin', '/forumdisplay.php?4-General', $T],
            'vB3 member' => ['vbulletin', '/member.php?u=9', $U],
            'vB4 member' => ['vbulletin', '/members/9-ada', $U],
            'vB archive' => ['vbulletin', '/archive/index.php/t-123.html', $D],
            'vB poll' => ['vbulletin', '/poll.php?do=showresults&pollid=7', $P],
            'vB message copy' => ['vbulletin', '/private.php?do=showpm&pmid=43', self::PM],
            'vB message (no copy map)' => ['vbulletin', '/private.php?do=showpm&pmid=42', self::PM],
            'vB front page' => ['vbulletin', '/forum.php', '/'],

            'vB5 thread' => ['vbulletin5', '/forum/general/123-hello', $D],
            'vB5 reply' => ['vbulletin5', '/forum/general/123-hello?p=456#post456', $P],
            'vB5 node' => ['vbulletin5', '/node/123', $D],
            'vB5 reply node' => ['vbulletin5', '/node/456', $P],
            'vB5 channel' => ['vbulletin5', '/forum/lounge', $T],
            'vB5 member' => ['vbulletin5', '/member/9-ada', $U],
            'vB5 message' => ['vbulletin5', '/privatemessage/view?id=42', self::PM],

            'MyBB thread' => ['mybb', '/showthread.php?tid=123', $D],
            'MyBB post' => ['mybb', '/showthread.php?tid=123&pid=456#pid456', $P],
            'MyBB rewritten post' => ['mybb', '/thread-123-post-456.html#pid456', $P],
            'MyBB rewritten thread' => ['mybb', '/thread-123-page-2.html', $D],
            'MyBB forum' => ['mybb', '/forum-4.html', $T],
            'MyBB member' => ['mybb', '/member.php?action=profile&uid=9', $U],
            'MyBB rewritten member' => ['mybb', '/user-9.html', $U],
            'MyBB poll' => ['mybb', '/polls.php?action=showresults&pid=7', $P],
            'MyBB message' => ['mybb', '/private.php?action=read&pmid=43', self::PM],

            'SMF topic' => ['smf', '/index.php?topic=123.0', $D],
            'SMF post' => ['smf', '/index.php?topic=123.msg456#msg456', $P],
            'SMF pretty topic' => ['smf', '/index.php/topic,123.0.html', $D],
            'SMF board' => ['smf', '/index.php?board=4.0', $T],
            'SMF profile' => ['smf', '/index.php?action=profile;u=9', $U],
            'SMF message' => ['smf', '/index.php?action=pm;f=inbox;pmsg=42', self::PM],

            'Vanilla discussion' => ['vanilla', '/discussion/123/hello', $D],
            'Vanilla comment' => ['vanilla', '/discussion/comment/456#Comment_456', $P],
            'Vanilla category' => ['vanilla', '/categories/lounge', $T],
            'Vanilla profile by id' => ['vanilla', '/profile/9/Ada', $U],
            'Vanilla profile by name' => ['vanilla', '/profile/Ada', $U],
            'Vanilla conversation' => ['vanilla', '/messages/12', self::CONV],

            'NodeBB topic' => ['nodebb', '/topic/123/hello', $D],
            'NodeBB post in topic' => ['nodebb', '/topic/123/hello/2', $P],
            'NodeBB post' => ['nodebb', '/post/456', $P],
            'NodeBB category' => ['nodebb', '/category/4/general', $T],
            'NodeBB user' => ['nodebb', '/user/ada', $U],
            'NodeBB chat' => ['nodebb', '/chats/12', self::CONV],

            'Invision topic' => ['invision', '/topic/123-hello/', $D],
            'Invision post' => ['invision', '/topic/123-hello/?do=findComment&comment=456', $P],
            'Invision 3 post' => ['invision', '/index.php?showtopic=123&view=findpost&p=456', $P],
            'Invision forum' => ['invision', '/forum/4-general/', $T],
            'Invision profile' => ['invision', '/profile/9-ada/', $U],
            'Invision old profile' => ['invision', '/index.php?showuser=9', $U],
            'Invision conversation' => ['invision', '/messenger/12/', self::CONV],
            'Invision message' => ['invision', '/messenger/12/?do=findComment&comment=42', self::PM],

            'Discourse topic' => ['discourse', '/t/hello/123', $D],
            'Discourse post number' => ['discourse', '/t/hello/123/2', $P],
            'Discourse bare post number' => ['discourse', '/t/123/2', $P],
            'Discourse post' => ['discourse', '/p/456', $P],
            'Discourse PM topic' => ['discourse', '/t/hi-there/12', self::CONV],
            'Discourse category id' => ['discourse', '/c/general/4', $T],
            'Discourse category slug' => ['discourse', '/c/lounge', $T],
            'Discourse user' => ['discourse', '/u/ada/summary', $U],

            'Convoro topic' => ['convoro', '/topic/123-hello', $D],
            'Convoro member' => ['convoro', '/member/9', $U],
            'Convoro 1 topic' => ['convoro', '/t/123', $D],
            'Convoro 1 profile' => ['convoro', '/u/Ada', $U],
            'Convoro forum' => ['convoro', '/forum/lounge', $T],
            'Convoro conversation' => ['convoro', '/messages/12', self::CONV],

            'Web Wiz topic' => ['webwiz', '/forum_posts.asp?TID=123', $D],
            'Web Wiz post' => ['webwiz', '/forum_posts.asp?TID=123&PID=456#456', $P],
            'Web Wiz forum' => ['webwiz', '/forum_topics.asp?FID=4', $T],
            'Web Wiz member' => ['webwiz', '/member_profile.asp?PF=9', $U],
            'Web Wiz message' => ['webwiz', '/pm_message.asp?ID=42', self::PM],
            'Web Wiz friendly topic' => ['webwiz', '/hello_topic123.html', $D],
            'Web Wiz friendly post' => ['webwiz', '/hello_topic123_post456.html', $P],
            'Web Wiz front page' => ['webwiz', '/default.asp', '/'],
        ];
    }

    #[Test]
    #[DataProvider('addresses')]
    public function an_old_address_finds_its_new_home(string $source, string $url, string $expected)
    {
        $this->app();
        $this->assertSame($expected, resolve(Resolver::class)->resolve(1, $source, $url));
    }

    #[Test]
    public function an_unknown_id_resolves_to_nothing_on_every_pattern()
    {
        $this->app();
        foreach (Patterns::all() as $source => $rules) {
            foreach ($rules as $rule) {
                if ($rule['kind'] === 'home') {
                    continue;
                }
                $miss = preg_replace_callback('/\d+/', fn ($m) => '9'.$m[0].'99', $rule['example']);
                $this->assertNull(resolve(Resolver::class)->resolve(1, $source, $miss), "$source: $miss");
            }
        }
    }

    #[Test]
    public function the_preview_shows_one_real_example_of_each_kind()
    {
        $this->app();
        $preview = resolve(Resolver::class)->preview(1, 'phpbb');

        $this->assertSame(['post', 'topic', 'tag', 'user', 'pm'], array_column($preview, 'kind'));
        foreach ($preview as $example) {
            $this->assertNotNull($example['to'], $example['from']);
        }
    }

    #[Test]
    public function a_post_link_is_redirected_by_the_live_middleware()
    {
        $this->app();
        $settings = $this->app()->getContainer()->make(SettingsRepositoryInterface::class);
        $settings->set('ernestdefoe-importer.redirect_run', '1');
        $settings->set('ernestdefoe-importer.redirect_source', 'phpbb');

        $response = $this->send($this->request('GET', '/viewtopic.php')->withUri(new Uri('http://localhost/viewtopic.php?p=456'))->withQueryParams(['p' => '456']));

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('http://localhost/d/1/2', $response->getHeaderLine('Location'));
    }
}
