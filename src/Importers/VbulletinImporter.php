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
}
