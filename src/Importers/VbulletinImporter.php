<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * vBulletin 3.x / 4.x → Flarum.
 *   forum → tags · user → users · thread → discussions · post → posts
 * vB hashes are md5-based (not portable) so members reset on first login.
 * Supports a table prefix; when a `node` table is present (vB5/6) we hand off
 * to Vbulletin5Importer so one wizard option covers both generations.
 */
class VbulletinImporter
{
    private static function isNodeSchema(array $cfg): bool
    {
        $conn = Src::connect($cfg);
        $p = trim((string) ($cfg['prefix'] ?? ''));
        $sb = $conn->getSchemaBuilder();

        return $sb->hasTable($p.'node') && $sb->hasTable($p.'contenttype') && ! $sb->hasTable($p.'thread');
    }

    public static function test(array $cfg): array
    {
        if (self::isNodeSchema($cfg)) {
            return Vbulletin5Importer::test($cfg);
        }
        $conn = Src::connect($cfg);
        $p = trim((string) ($cfg['prefix'] ?? ''));
        $sb = $conn->getSchemaBuilder();
        foreach (['user', 'thread', 'post', 'forum'] as $req) {
            if (! $sb->hasTable($p.$req)) {
                throw new \RuntimeException(Src::t('not_source.vbulletin', ['table' => $p.$req]));
            }
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table($p.'user')->count(),
            'categories' => (int) $conn->table($p.'forum')->count(),
            'topics' => (int) $conn->table($p.'thread')->count(),
            'posts' => (int) $conn->table($p.'post')->count(),
        ]];
    }

    /** @return Phase[] */
    public static function phases(array $cfg): array
    {
        if (self::isNodeSchema($cfg)) {
            return Vbulletin5Importer::phases($cfg);
        }
        $p = trim((string) ($cfg['prefix'] ?? ''));
        $hasTags = Dst::hasTags();

        return array_merge([
            new Phase(
                'tags',
                'categories',
                fn () => $hasTags ? (int) Src::connect($cfg)->table($p.'forum')->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $rows = $ctx->src()->table($p.'forum')->where('forumid', '>', (int) $cursor)->orderBy('forumid')->limit($limit)->get();
                    $map = [];
                    $n = 0;
                    foreach ($rows as $f) {
                        $cursor = $f->forumid;
                        $map[$f->forumid] = Dst::tag($f->title ?: 'Forum', Src::tagSlug($f->title ?: 'forum', (int) $f->forumid), $f->description ?? null, null, (int) ($f->displayorder ?? 0));
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase(
                'users',
                'members',
                fn () => (int) Src::connect($cfg)->table($p.'user')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p) {
                    $rows = $ctx->src()->table($p.'user')->where('userid', '>', (int) $cursor)->orderBy('userid')->limit($limit)->get();
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->userid;
                        $email = trim((string) ($u->email ?? ''));
                        if ($email === '') {
                            $skip++;

                            continue;
                        }

                        try {
                            // vB md5 → not portable; members reset (null password).
                            $map[$u->userid] = Dst::user(Src::username($u->username ?? null, (int) $u->userid), $email, null, Src::ts($u->joindate ?? null));
                            $n++;
                        } catch (\Throwable) {
                            $skip++;
                        }
                    }
                    $ctx->mapPut('user', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['users' => $n, 'skipped' => $skip]];
                }
            ),

            new Phase(
                'topics',
                'topics',
                fn () => (int) Src::connect($cfg)->table($p.'thread')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    $rows = $ctx->src()->table($p.'thread')->where('threadid', '>', (int) $cursor)->orderBy('threadid')->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('postuserid')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('forumid')->all()) : [];
                    $map = [];
                    $n = 0;
                    $known = $ctx->known('topic', $rows->pluck('threadid'));
                    foreach ($rows as $t) {
                        $cursor = $t->threadid;
                        if (isset($known[(string) $t->threadid])) {
                            continue;
                        }
                        if ((int) ($t->visible ?? 1) !== 1) {
                            continue;
                        }
                        $did = Dst::discussion($t->title ?: 'Untitled', $userMap[(string) $t->postuserid] ?? null, Src::ts($t->dateline ?? null), (bool) ($t->sticky ?? false));
                        $map[$t->threadid] = $did;
                        if ($hasTags && isset($tagMap[(string) $t->forumid])) {
                            Dst::attachTag($did, $tagMap[(string) $t->forumid]);
                        }
                        $n++;
                    }
                    $ctx->mapPut('topic', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['topics' => $n]];
                }
            ),

            ...Phases::posts(
                $p.'post',
                'threadid',
                'postid',
                fn () => (int) Src::connect($cfg)->table($p.'post')->count(),
                fn ($post) => [
                    'tid' => (int) $post->threadid, 'pid' => (int) $post->postid, 'uid' => $post->userid,
                    'html' => Bbcode::toHtml($post->pagetext ?? ''), 'at' => Src::ts($post->dateline ?? null),
                    'ok' => (int) ($post->visible ?? 1) === 1,
                ],
            ),
        ], Phases::tail());
    }
    /**
     * vBulletin 3/4: `pmtext` holds each message once (`fromuserid`, `title`,
     * `message`, `touserarray` — serialized [userid => name], or from 3.7
     * ['cc' => [...], 'bcc' => [...]]) and `pm` one row per member's copy
     * (`userid`, `folderid` -1 = sent items, `messageread` 0 while unread).
     * Deleting removes the member's `pm` row, so a text nobody holds a copy of
     * any more was deleted by everyone. Delegates to vBulletin 5 on its schema.
     */
    public static function privateMessages(array $cfg): array
    {
        if (self::isNodeSchema($cfg)) {
            return Vbulletin5Importer::privateMessages($cfg);
        }
        $p = trim((string) ($cfg['prefix'] ?? ''));
        $has = fn ($conn) => $conn->getSchemaBuilder()->hasTable($p.'pmtext') && $conn->getSchemaBuilder()->hasTable($p.'pm');

        return [
            'count' => function () use ($cfg, $p, $has) {
                $conn = Src::connect($cfg);

                return $has($conn) ? (int) $conn->table($p.'pmtext')->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($p, $has) {
                $conn = $ctx->src();
                if (! $has($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'messages' => []];
                }
                $rows = $conn->table($p.'pmtext')->where('pmtextid', '>', $after)->orderBy('pmtextid')->limit($limit)->get();
                $copies = [];
                foreach ($rows->isEmpty() ? [] : $conn->table($p.'pm')->whereIn('pmtextid', $rows->pluck('pmtextid')->all())->get() as $c) {
                    $copies[$c->pmtextid][] = $c;
                }
                $out = [];
                foreach ($rows as $m) {
                    $after = (int) $m->pmtextid;
                    $mine = $copies[$m->pmtextid] ?? [];
                    if (! $mine) {
                        continue;
                    }
                    $list = @unserialize((string) ($m->touserarray ?? ''), ['allowed_classes' => false]);
                    $to = $bcc = [];
                    foreach (is_array($list) ? $list : [] as $k => $v) {
                        if ($k === 'cc' || $k === 'bcc') {
                            $ids = is_array($v) ? array_keys($v) : [];
                            if ($k === 'cc') {
                                $to = array_merge($to, $ids);
                            } else {
                                $bcc = array_merge($bcc, $ids);
                            }
                        } else {
                            $to[] = $k;
                        }
                    }
                    $people = array_merge([$m->fromuserid], $to);
                    $out[] = [
                        'id' => (string) $m->pmtextid,
                        'conv' => PrivateMessages::conv('', $people),
                        'title' => (string) ($m->title ?? ''),
                        'uid' => $m->fromuserid,
                        'people' => $people,
                        'bcc' => $bcc,
                        'keep' => array_map(fn ($c) => $c->userid, $mine),
                        'unread' => array_map(fn ($c) => $c->userid, array_filter($mine, fn ($c) => (int) ($c->folderid ?? 0) !== -1 && ! (int) ($c->messageread ?? 1))),
                        'html' => Bbcode::toHtml($m->message ?? ''),
                        'at' => Src::ts($m->dateline ?? null),
                    ];
                }

                return ['cursor' => $after, 'rows' => count($rows), 'done' => count($rows) < $limit, 'messages' => $out];
            },
        ];
    }
}
