<?php

/*
 * Does every pattern match the URLs it claims to, and only those?
 *
 * 🚨 This table IS the feature. A wrong regular expression costs somebody every
 * inbound link to that part of their forum, and nothing anywhere says so — the
 * pages simply 404 and the search results quietly die. So each one is run
 * against its own example plus the real-world variants that forum actually
 * serves.
 */

declare(strict_types=1);

/*
 * 🚨 No PHPUnit and no Flarum. This file is the pattern table and a list of
 * real addresses, and it must stay runnable with nothing but `php` — the
 * point is that anybody can check it in one command, including on the server
 * of the forum that just migrated.
 *
 *     php tests/patterns.php
 */
require __DIR__.'/../src/Redirects/Patterns.php';

use ErnestDefoe\Importer\Redirects\Patterns;

/** Real addresses these forums serve, and the id each one means. */
$cases = [
    'phpbb' => [
        ['/viewtopic.php?f=2&t=1234', 'topic', '1234'],
        ['/viewtopic.php?t=99', 'topic', '99'],
        ['/forum/viewtopic.php?f=1&t=7&start=20', 'topic', '7'],
        ['/viewforum.php?f=2', 'tag', '2'],
        ['/memberlist.php?mode=viewprofile&u=5', 'user', '5'],
    ],
    'xenforo' => [
        ['/threads/my-topic.123/', 'topic', '123'],
        ['/threads/my-topic.123/page-3', 'topic', '123'],
        ['/index.php?threads/my-topic.123/', 'topic', '123'],
        ['/community/threads/another.45/', 'topic', '45'],
        ['/forums/general.4/', 'tag', '4'],
        ['/members/alice.9/', 'user', '9'],
    ],
    'vbulletin' => [
        ['/threads/123-my-topic', 'topic', '123'],
        ['/showthread.php?t=123', 'topic', '123'],
        ['/showthread.php?p=99&t=123', 'topic', '123'],
        ['/forums/4-general', 'tag', '4'],
        ['/forumdisplay.php?f=4', 'tag', '4'],
        ['/member.php?u=9', 'user', '9'],
    ],
    'mybb' => [
        ['/showthread.php?tid=123', 'topic', '123'],
        ['/thread-123.html', 'topic', '123'],
        ['/forumdisplay.php?fid=4', 'tag', '4'],
        ['/forum-4.html', 'tag', '4'],
    ],
    'smf' => [
        ['/index.php?topic=123.0', 'topic', '123'],
        ['/index.php?topic=123.msg456', 'topic', '123'],
        ['/index.php?board=4.0', 'tag', '4'],
    ],
    'vanilla' => [
        ['/discussion/123/my-topic', 'topic', '123'],
        ['/discussion/123', 'topic', '123'],
    ],
    'nodebb' => [
        ['/topic/123/my-topic', 'topic', '123'],
        ['/topic/123', 'topic', '123'],
        ['/category/4/general', 'tag', '4'],
    ],
    'invision' => [
        ['/topic/123-my-topic/', 'topic', '123'],
        ['/forums/topic/123-my-topic/', 'topic', '123'],
        ['/index.php?showtopic=123', 'topic', '123'],
        ['/forum/4-general/', 'tag', '4'],
        ['/profile/9-alice/', 'user', '9'],
    ],
    'discourse' => [
        ['/t/my-topic/123', 'topic', '123'],
        ['/t/my-topic/123/45', 'topic', '123'],
        ['/t/123', 'topic', '123'],
        /*
         * 🚨 Topic 123, post 456 — every link anybody ever made to a specific
         * reply. This resolved to topic 456 until the patterns were reordered:
         * not a 404 that somebody would notice, but the WRONG DISCUSSION,
         * served confidently.
         */
        ['/t/123/456', 'topic', '123'],
        ['/t/my-topic/123/45', 'topic', '123'],
        ['/c/general', 'tag', 'general'],
        ['/u/alice', 'user', 'alice'],
    ],
    'convoro' => [
        ['/topic/123-my-topic', 'topic', '123'],
        ['/topic/123', 'topic', '123'],
    ],
    'webwiz' => [
        ['/forum_posts.asp?TID=123', 'topic', '123'],
        ['/forum_posts.asp?TID=123&PID=456', 'topic', '123'],
        ['/forum_topics.asp?FID=4', 'tag', '4'],
    ],
];

$fails = 0;
$ran = 0;

foreach ($cases as $source => $urls) {
    foreach ($urls as [$url, $wantKind, $wantId]) {
        $ran++;
        $got = null;

        foreach (Patterns::for($source) as $rule) {
            if (preg_match($rule['pattern'], $url, $m) === 1) {
                $got = [$rule['kind'], $m[1]];
                break;
            }
        }

        if ($got === null) {
            printf("  FAIL %-11s %-42s matched NOTHING (wanted %s %s)\n", $source, $url, $wantKind, $wantId);
            $fails++;

            continue;
        }

        if ($got[0] !== $wantKind || $got[1] !== $wantId) {
            printf(
                "  FAIL %-11s %-42s got %s %s, wanted %s %s\n",
                $source,
                $url,
                $got[0],
                $got[1],
                $wantKind,
                $wantId
            );
            $fails++;
        }
    }
}

/*
 * 🚨 And the other half: addresses that must match NOTHING. Flarum's own URLs
 * pass through this middleware on every 404, and a pattern that swallows one
 * would redirect the site's own pages into whatever the map happens to hold.
 */
$mustNotMatch = [
    'discourse' => ['/d/123', '/t/general', '/u/', '/'],
    'convoro' => ['/d/123', '/settings', '/'],
    'nodebb' => ['/d/123', '/tags', '/'],
    'invision' => ['/d/123', '/'],
];

foreach ($mustNotMatch as $source => $urls) {
    foreach ($urls as $url) {
        $ran++;

        foreach (Patterns::for($source) as $rule) {
            if (preg_match($rule['pattern'], $url) === 1) {
                printf("  FAIL %-11s %-42s matched, and must not\n", $source, $url);
                $fails++;

                break;
            }
        }
    }
}

printf("\n%d checks, %d failed\n", $ran, $fails);

exit($fails === 0 ? 0 : 1);
