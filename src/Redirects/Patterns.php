<?php

/*
 * This file is part of ernestdefoe/importer.
 */

namespace ErnestDefoe\Importer\Redirects;

/**
 * What the old forum's addresses looked like, per source.
 *
 * 🚨 **This table is the feature.** Everything else here is a lookup and a 301;
 * the value is in knowing that phpBB says `viewtopic.php?t=`, XenForo says
 * `/threads/slug.123/`, SMF says `index.php?topic=123.0` and Web Wiz says
 * `forum_posts.asp?TID=`. Getting one of those wrong costs somebody every
 * inbound link to that section of their forum, and nothing anywhere would say
 * so — the pages simply 404 and the search results quietly die.
 *
 * ## What a pattern is
 *
 * A regular expression over the path AND query together, with one capture
 * group: the id in the old system (a second, where noted, is a post's position
 * in its topic). `kind` says how to look it up — see {@see all()}.
 *
 * 🚨 The SLUG in an old URL is ignored everywhere it appears. Every forum here
 * resolves by id and treats the slug as decoration, so `/threads/anything.123/`
 * and `/threads/the-real-title.123/` are the same page. Matching on the slug
 * would break every link whose title has since been edited — which is most of
 * the interesting ones.
 */
final class Patterns
{
    /**
     * 🚨 Ordered, and the order matters within a source: the most specific
     * pattern must come first. vBulletin's `/threads/123-slug` and phpBB's
     * `viewtopic.php?t=` can both appear on a vBulletin 4 site, and a loose
     * pattern placed first would swallow a URL the next one would have read
     * correctly. A link to one post (`viewtopic.php?t=5&p=123`) carries the
     * topic as well, so every post pattern comes before its topic pattern.
     *
     * Kinds, and what each looks up (see {@see Resolver}):
     *  - topic      the id → its discussion; a SECOND capture, where a pattern
     *               has one, is the post's position in the topic (Discourse's
     *               `/t/slug/123/4`, NodeBB's `/topic/123/slug/4`)
     *  - post       a post's own id → that post in its discussion
     *  - tag        a forum's id;  tag-slug  the slug or path in its URL
     *  - user       a member's id; user-name  the name in their URL
     *  - pm         one private message → it, in its conversation;
 *    pm-copy    one member's copy of a message (vBulletin, MyBB)
     *  - pm-conv    a conversation's id → that conversation
     *  - poll       a poll's id → the post it is on
     *  - home       the old forum's front page → this one (no capture)
     *
     * @return array<string, list<array{kind: string, pattern: string, example: string}>>
     */
    public static function all(): array
    {
        return [
            'phpbb' => [
                ['kind' => 'post', 'pattern' => '~(?:^|/)viewtopic\.php\?(?:.*&)?p=(\d+)~i', 'example' => '/viewtopic.php?p=1234'],
                ['kind' => 'topic', 'pattern' => '~(?:^|/)viewtopic\.php\?(?:.*&)?t=(\d+)~i', 'example' => '/viewtopic.php?f=2&t=1234'],
                ['kind' => 'tag', 'pattern' => '~(?:^|/)viewforum\.php\?(?:.*&)?f=(\d+)~i', 'example' => '/viewforum.php?f=2'],
                ['kind' => 'user', 'pattern' => '~(?:^|/)memberlist\.php\?.*\bu=(\d+)~i', 'example' => '/memberlist.php?mode=viewprofile&u=5'],
                // A message in the private message centre: `p` is the message id.
                ['kind' => 'pm', 'pattern' => '~(?:^|/)ucp\.php\?(?=.*\bi=(?:pm|ucp_pm)\b).*\bp=(\d+)~i', 'example' => '/ucp.php?i=pm&mode=view&p=42'],
                // The phpBB SEO mod's rewritten addresses.
                ['kind' => 'post', 'pattern' => '~(?:^|/)post(\d+)\.html~i', 'example' => '/post1234.html'],
                ['kind' => 'topic', 'pattern' => '~-t(\d+)(?:-\d+)?\.html~i', 'example' => '/my-topic-t1234.html'],
                ['kind' => 'tag', 'pattern' => '~-f(\d+)/?(?:$|\?|index)~i', 'example' => '/general-f2/'],
                ['kind' => 'user', 'pattern' => '~(?:^|/)member(\d+)\.html~i', 'example' => '/member5.html'],
                ['kind' => 'home', 'pattern' => '~(?:^|/)index\.php$~i', 'example' => '/index.php'],
            ],

            'xenforo' => [
                /*
                 * 🚨 The id is at the END, after a dot: `slug.123/`. XenForo
                 * also serves every one of these under `index.php?` on a site
                 * without friendly URLs, which is why the leading part is
                 * permissive rather than anchored to `/`.
                 */
                ['kind' => 'post', 'pattern' => '~threads/[^/?]*/post-(\d+)~i', 'example' => '/threads/my-topic.123/post-456'],
                ['kind' => 'post', 'pattern' => '~(?:^|[/?])posts/(\d+)~i', 'example' => '/posts/456/'],
                ['kind' => 'post', 'pattern' => '~goto/post[^?]*\?(?:.*&)?id=(\d+)~i', 'example' => '/goto/post?id=456'],
                ['kind' => 'topic', 'pattern' => '~threads/(?:[^/?]*\.)?(\d+)~i', 'example' => '/threads/my-topic.123/'],
                ['kind' => 'tag', 'pattern' => '~forums/(?:[^/?]*\.)?(\d+)~i', 'example' => '/forums/general.4/'],
                ['kind' => 'user', 'pattern' => '~members/(?:[^/?]*\.)?(\d+)~i', 'example' => '/members/alice.9/'],
                // Conversations; "direct messages" from XenForo 2.3.
                ['kind' => 'pm', 'pattern' => '~(?:conversations|direct-messages)/messages/(\d+)~i', 'example' => '/conversations/messages/789/'],
                ['kind' => 'pm-conv', 'pattern' => '~(?:conversations|direct-messages)/(?:[^/?]*\.)?(\d+)~i', 'example' => '/conversations/hello.12/'],
                ['kind' => 'home', 'pattern' => '~(?:^|/)(?:index\.php|forums/?)$~i', 'example' => '/index.php'],
            ],

            'vbulletin' => [
                // A post: `showpost.php?p=`, or a thread link that names one.
                ['kind' => 'post', 'pattern' => '~showpost\.php\?(?:.*&)?p=(\d+)~i', 'example' => '/showpost.php?p=456'],
                ['kind' => 'post', 'pattern' => '~(?:showthread\.php|threads/[^?]*)\?(?:.*&)?p=(\d+)~i', 'example' => '/showthread.php?p=456'],
                // vB4 friendly URLs put the id FIRST, before a dash.
                ['kind' => 'topic', 'pattern' => '~threads/(\d+)~i', 'example' => '/threads/123-my-topic'],
                ['kind' => 'tag', 'pattern' => '~forums/(\d+)~i', 'example' => '/forums/4-general'],
                ['kind' => 'user', 'pattern' => '~members/(\d+)~i', 'example' => '/members/9-alice'],
                // vB3, and vB4 without friendly URLs — `?t=123` or vB4's `?123-slug`.
                ['kind' => 'topic', 'pattern' => '~showthread\.php\?(?:.*&)?t=(\d+)~i', 'example' => '/showthread.php?t=123'],
                ['kind' => 'topic', 'pattern' => '~showthread\.php\?(\d+)~i', 'example' => '/showthread.php?123-my-topic'],
                ['kind' => 'tag', 'pattern' => '~forumdisplay\.php\?(?:.*&)?f=(\d+)~i', 'example' => '/forumdisplay.php?f=4'],
                ['kind' => 'tag', 'pattern' => '~forumdisplay\.php\?(\d+)~i', 'example' => '/forumdisplay.php?4-general'],
                ['kind' => 'user', 'pattern' => '~member\.php\?(?:.*&)?u=(\d+)~i', 'example' => '/member.php?u=9'],
                ['kind' => 'user', 'pattern' => '~member\.php\?(\d+)~i', 'example' => '/member.php?9-alice'],
                // The search-engine archive.
                ['kind' => 'topic', 'pattern' => '~archive/index\.php/t-(\d+)~i', 'example' => '/archive/index.php/t-123.html'],
                ['kind' => 'tag', 'pattern' => '~archive/index\.php/f-(\d+)~i', 'example' => '/archive/index.php/f-4.html'],
                ['kind' => 'poll', 'pattern' => '~poll\.php\?(?:.*&)?pollid=(\d+)~i', 'example' => '/poll.php?do=showresults&pollid=7'],
                // `pmid` is one member's copy of the message.
                ['kind' => 'pm-copy', 'pattern' => '~private\.php\?(?:.*&)?pmid=(\d+)~i', 'example' => '/private.php?do=showpm&pmid=42'],
                ['kind' => 'home', 'pattern' => '~(?:^|/)(?:index|forum)\.php$~i', 'example' => '/forum.php'],
            ],

            'vbulletin5' => [
                // A reply is a node too: `?p=` and `#post` name it on the thread's address.
                ['kind' => 'post', 'pattern' => '~[?&]p=(\d+)~i', 'example' => '/forum/general/123-my-topic?p=456'],
                ['kind' => 'topic', 'pattern' => '~(?:^|/)(\d+)-[^/?]+(?:/page\d+)?/?(?:$|\?)~', 'example' => '/forum/general/123-my-topic'],
                ['kind' => 'topic', 'pattern' => '~node/(\d+)~i', 'example' => '/node/123'],
                ['kind' => 'post', 'pattern' => '~node/(\d+)~i', 'example' => '/node/456'],
                ['kind' => 'user', 'pattern' => '~member/(\d+)~i', 'example' => '/member/9-alice'],
                ['kind' => 'pm', 'pattern' => '~privatemessage/view\?(?:.*&)?id=(\d+)~i', 'example' => '/privatemessage/view?id=42'],
                // A channel is addressed by its route path.
                ['kind' => 'tag-slug', 'pattern' => '~^/([a-z0-9-]+(?:/[a-z0-9-]+)*)/?$~i', 'example' => '/general'],
            ],

            'mybb' => [
                ['kind' => 'post', 'pattern' => '~showthread\.php\?(?:.*&)?pid=(\d+)~i', 'example' => '/showthread.php?pid=456'],
                ['kind' => 'topic', 'pattern' => '~showthread\.php\?(?:.*&)?tid=(\d+)~i', 'example' => '/showthread.php?tid=123'],
                ['kind' => 'tag', 'pattern' => '~forumdisplay\.php\?(?:.*&)?fid=(\d+)~i', 'example' => '/forumdisplay.php?fid=4'],
                ['kind' => 'user', 'pattern' => '~member\.php\?(?:.*&)?uid=(\d+)~i', 'example' => '/member.php?action=profile&uid=9'],
                ['kind' => 'poll', 'pattern' => '~polls\.php\?(?:.*&)?pid=(\d+)~i', 'example' => '/polls.php?action=showresults&pid=7'],
                // `pmid` is one member's copy of the message.
                ['kind' => 'pm-copy', 'pattern' => '~private\.php\?(?:.*&)?pmid=(\d+)~i', 'example' => '/private.php?action=read&pmid=42'],
                // MyBB's rewritten form.
                ['kind' => 'post', 'pattern' => '~thread-\d+-post-(\d+)~i', 'example' => '/thread-123-post-456.html'],
                ['kind' => 'post', 'pattern' => '~(?:^|/)post-(\d+)\.html~i', 'example' => '/post-456.html'],
                ['kind' => 'topic', 'pattern' => '~thread-(\d+)~i', 'example' => '/thread-123.html'],
                ['kind' => 'tag', 'pattern' => '~(?:^|/)forum-(\d+)~i', 'example' => '/forum-4.html'],
                ['kind' => 'user', 'pattern' => '~(?:^|/)user-(\d+)~i', 'example' => '/user-9.html'],
                ['kind' => 'home', 'pattern' => '~(?:^|/)index\.php$~i', 'example' => '/index.php'],
            ],

            'smf' => [
                /*
                 * 🚨 SMF's id carries a message offset after a dot —
                 * `topic=123.60` means "topic 123, from message 60". The
                 * capture stops at the dot deliberately: the offset cannot be
                 * honoured (see Resolver) and taking it as part of the id would
                 * mean matching nothing at all. `topic=123.msg456` names a
                 * post, and does lead to it.
                 */
                ['kind' => 'post', 'pattern' => '~topic[=,]\d+\.msg(\d+)~i', 'example' => '/index.php?topic=123.msg456'],
                ['kind' => 'post', 'pattern' => '~[?&;]msg=(\d+)~i', 'example' => '/index.php?msg=456'],
                ['kind' => 'topic', 'pattern' => '~[?&;/]topic[=,](\d+)~i', 'example' => '/index.php?topic=123.0'],
                ['kind' => 'tag', 'pattern' => '~[?&;/]board[=,](\d+)~i', 'example' => '/index.php?board=4.0'],
                ['kind' => 'user', 'pattern' => '~action[=,]profile.*?[;&,]u[=,](\d+)~i', 'example' => '/index.php?action=profile;u=9'],
                ['kind' => 'pm', 'pattern' => '~action[=,]pm.*?[;&,]pmsg[=,](\d+)~i', 'example' => '/index.php?action=pm;f=inbox;pmsg=42'],
                ['kind' => 'home', 'pattern' => '~(?:^|/)index\.php$~i', 'example' => '/index.php'],
            ],

            'vanilla' => [
                ['kind' => 'post', 'pattern' => '~discussion/comment/(\d+)~i', 'example' => '/discussion/comment/456'],
                ['kind' => 'topic', 'pattern' => '~discussion/(\d+)~i', 'example' => '/discussion/123/my-topic'],
                ['kind' => 'tag-slug', 'pattern' => '~categories/([^/?]+)~i', 'example' => '/categories/general'],
                ['kind' => 'user', 'pattern' => '~profile/(\d+)/~i', 'example' => '/profile/9/alice'],
                ['kind' => 'user-name', 'pattern' => '~profile/([^/?]+)/?$~i', 'example' => '/profile/alice'],
                ['kind' => 'pm-conv', 'pattern' => '~messages/(\d+)~i', 'example' => '/messages/12'],
            ],

            'nodebb' => [
                ['kind' => 'post', 'pattern' => '~(?:^|/)post/(\d+)~i', 'example' => '/post/456'],
                ['kind' => 'topic', 'pattern' => '~topic/(\d+)(?:/[^/?]+/(\d+))?~i', 'example' => '/topic/123/my-topic'],
                ['kind' => 'tag', 'pattern' => '~category/(\d+)~i', 'example' => '/category/4/general'],
                ['kind' => 'user-name', 'pattern' => '~user/([^/?]+)~i', 'example' => '/user/alice'],
                ['kind' => 'pm-conv', 'pattern' => '~chats/(\d+)~i', 'example' => '/chats/12'],
            ],

            'invision' => [
                // A post, linked from its topic (IPS 4: findComment; IPS 3: findpost).
                ['kind' => 'post', 'pattern' => '~topic/\d+[^?]*\?(?:.*&)?comment=(\d+)~i', 'example' => '/topic/123-my-topic/?do=findComment&comment=456'],
                ['kind' => 'post', 'pattern' => '~findpost(?:__p__|&p=|.*[?&]p=)(\d+)~i', 'example' => '/index.php?showtopic=123&view=findpost&p=456'],
                ['kind' => 'topic', 'pattern' => '~topic/(\d+)~i', 'example' => '/topic/123-my-topic/'],
                ['kind' => 'tag', 'pattern' => '~forum/(\d+)~i', 'example' => '/forum/4-general/'],
                ['kind' => 'user', 'pattern' => '~profile/(\d+)~i', 'example' => '/profile/9-alice/'],
                ['kind' => 'pm', 'pattern' => '~messenger/\d+[^?]*\?(?:.*&)?comment=(\d+)~i', 'example' => '/messenger/12/?do=findComment&comment=42'],
                ['kind' => 'pm-conv', 'pattern' => '~messenger/(\d+)~i', 'example' => '/messenger/12/'],
                // IPS without friendly URLs.
                ['kind' => 'topic', 'pattern' => '~showtopic=(\d+)~i', 'example' => '/index.php?showtopic=123'],
                ['kind' => 'tag', 'pattern' => '~showforum=(\d+)~i', 'example' => '/index.php?showforum=4'],
                ['kind' => 'user', 'pattern' => '~showuser=(\d+)~i', 'example' => '/index.php?showuser=9'],
                ['kind' => 'home', 'pattern' => '~(?:^|/)(?:index\.php|forums/?)$~i', 'example' => '/forums/'],
            ],

            'discourse' => [
                /*
                 * 🚨 Discourse puts the id LAST — `/t/slug/123` — and also
                 * serves `/t/123` and `/t/123/4`, where the second number is
                 * the POST'S POSITION in the topic.
                 *
                 * 🚨 The bare-id pattern MUST come first, and this was wrong
                 * the first time round. With the slugged one first, `/t/123/4`
                 * matched it — reading `123` as the slug and `4` as the topic
                 * — so every link anybody ever made to a specific reply landed
                 * on a DIFFERENT DISCUSSION. Not a 404, which somebody would
                 * notice: the wrong page, served confidently.
                 *
                 * The bare pattern cannot make the same mistake in reverse,
                 * because it needs digits immediately after `/t/` and a slug
                 * never is.
                 *
                 * A private message is a topic there too, so each shape is
                 * tried as a conversation when it is not a discussion.
                 */
                ['kind' => 'topic', 'pattern' => '~/t/(\d+)(?:/(\d+))?(?:/|$|\?)~i', 'example' => '/t/123'],
                ['kind' => 'topic', 'pattern' => '~/t/[^/?]+/(\d+)(?:/(\d+))?~i', 'example' => '/t/my-topic/123'],
                ['kind' => 'pm-conv', 'pattern' => '~/t/(\d+)(?:/|$|\?)~i', 'example' => '/t/789'],
                ['kind' => 'pm-conv', 'pattern' => '~/t/[^/?]+/(\d+)~i', 'example' => '/t/hello/789'],
                ['kind' => 'post', 'pattern' => '~(?:^|/)p/(\d+)~i', 'example' => '/p/456'],
                // `/c/slug/4` (the id) since 2.4; `/c/parent/child` and `/c/slug` before.
                ['kind' => 'tag', 'pattern' => '~/c/(?:[^?]+/)?(\d+)(?:/|$|\?)~i', 'example' => '/c/general/4'],
                ['kind' => 'tag-slug', 'pattern' => '~/c/([^?]+?)/?(?:$|\?)~i', 'example' => '/c/general'],
                ['kind' => 'user-name', 'pattern' => '~/u(?:sers)?/([^/?]+)~i', 'example' => '/u/alice'],
            ],

            'convoro' => [
                ['kind' => 'topic', 'pattern' => '~topic/(\d+)~i', 'example' => '/topic/123-my-topic'],
                ['kind' => 'tag-slug', 'pattern' => '~forum/([^/?]+)~i', 'example' => '/forum/general'],
                ['kind' => 'pm-conv', 'pattern' => '~(?:messages|conversations?)/(\d+)~i', 'example' => '/messages/12'],
            ],

            'webwiz' => [
                ['kind' => 'post', 'pattern' => '~forum_posts\.asp\?(?:.*&)?PID=(\d+)~i', 'example' => '/forum_posts.asp?TID=123&PID=456'],
                ['kind' => 'topic', 'pattern' => '~forum_posts\.asp\?(?:.*&)?TID=(\d+)~i', 'example' => '/forum_posts.asp?TID=123'],
                ['kind' => 'tag', 'pattern' => '~forum_topics\.asp\?(?:.*&)?FID=(\d+)~i', 'example' => '/forum_topics.asp?FID=4'],
                ['kind' => 'user', 'pattern' => '~member_profile\.asp\?(?:.*&)?PF=(\d+)~i', 'example' => '/member_profile.asp?PF=9'],
                ['kind' => 'pm', 'pattern' => '~pm_message\.asp\?(?:.*&)?ID=(\d+)~i', 'example' => '/pm_message.asp?ID=42'],
                // Web Wiz's search-engine-friendly addresses.
                ['kind' => 'post', 'pattern' => '~_topic\d+_post(\d+)\.html~i', 'example' => '/my-topic_topic123_post456.html'],
                ['kind' => 'topic', 'pattern' => '~_topic(\d+)(?:_page\d+)?\.html~i', 'example' => '/my-topic_topic123.html'],
                ['kind' => 'tag', 'pattern' => '~_forum(\d+)(?:_page\d+)?\.html~i', 'example' => '/general_forum4.html'],
                ['kind' => 'home', 'pattern' => '~(?:^|/)(?:default|forum)\.asp$~i', 'example' => '/default.asp'],
            ],
        ];
    }

    /** @return list<array{kind: string, pattern: string, example: string}> */
    public static function for(string $source): array
    {
        return self::all()[$source] ?? [];
    }

    /**
     * Whether a source has any patterns at all.
     *
     * 🚨 A source with none must be able to say so rather than offer a switch
     * that does nothing. `vbulletin5` in particular is thin, because vBulletin
     * 5's addresses are configurable to the point where there is no shape worth
     * promising.
     */
    public static function known(string $source): bool
    {
        return self::for($source) !== [];
    }
}
