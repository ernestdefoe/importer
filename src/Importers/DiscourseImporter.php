<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * Discourse (PostgreSQL) → Flarum.
 *   categories → tags · users → users · topics → discussions · posts → posts
 * Uses Discourse's already-rendered `cooked` HTML (sanitised). Passwords are
 * PBKDF2 (not portable) so members reset. Needs the pdo_pgsql PHP extension.
 */
class DiscourseImporter
{
    private static function cfg(array $cfg): array
    {
        $cfg['driver'] = ($cfg['driver'] ?? '') ?: 'pgsql';

        return $cfg;
    }

    public static function test(array $cfg): array
    {
        $conn = Src::connect(self::cfg($cfg));
        $sb = $conn->getSchemaBuilder();
        foreach (['users', 'topics', 'posts', 'categories'] as $req) {
            if (! $sb->hasTable($req)) {
                throw new \RuntimeException("This doesn't look like a Discourse database (missing “{$req}”). Discourse uses PostgreSQL.");
            }
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table('users')->where('id', '>', 0)->count(),
            'categories' => (int) $conn->table('categories')->count(),
            'topics' => (int) $conn->table('topics')->whereNull('deleted_at')->where('archetype', 'regular')->count(),
            'posts' => (int) $conn->table('posts')->whereNull('deleted_at')->where('post_type', 1)->count(),
        ]];
    }

    /** @return Phase[] */
    public static function phases(array $rawCfg): array
    {
        $cfg = self::cfg($rawCfg);
        $hasTags = Dst::hasTags();

        return array_merge([
            new Phase('tags', 'Importing categories…',
                fn () => $hasTags ? (int) Src::connect($cfg)->table('categories')->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($hasTags) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $rows = $ctx->src()->table('categories')->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get();
                    $map = [];
                    $n = 0;
                    foreach ($rows as $c) {
                        $cursor = $c->id;
                        $map[$c->id] = Dst::tag($c->name ?: 'Category', Src::tagSlug($c->name ?: 'category', (int) $c->id), $c->description ?? null, $c->color ?? null, (int) ($c->position ?? 0));
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase('users', 'Importing members…',
                fn () => (int) Src::connect($cfg)->table('users')->where('id', '>', 0)->count(),
                function ($cursor, $limit, Ctx $ctx) {
                    $conn = $ctx->src();
                    $sb = $conn->getSchemaBuilder();
                    $rows = $conn->table('users')->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get();
                    // Email lives in user_emails (newer) or users.email (older).
                    $emailByUser = [];
                    if (count($rows) && $sb->hasTable('user_emails')) {
                        foreach ($conn->table('user_emails')->whereIn('user_id', $rows->pluck('id')->all())->orderByDesc('primary')->get(['user_id', 'email']) as $e) {
                            $emailByUser[(string) $e->user_id] ??= $e->email;
                        }
                    }
                    $hasInlineEmail = $sb->hasColumn('users', 'email');
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->id;
                        $email = trim((string) ($emailByUser[(string) $u->id] ?? ($hasInlineEmail ? ($u->email ?? '') : '')));
                        if ($email === '') {
                            $skip++;

                            continue;
                        }
                        try {
                            $map[$u->id] = Dst::user(Src::username($u->username ?? ($u->name ?? null), (int) $u->id), $email, null, Src::ts($u->created_at ?? null));
                            $n++;
                        } catch (\Throwable) {
                            $skip++;
                        }
                    }
                    $ctx->mapPut('user', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['users' => $n, 'skipped' => $skip]];
                }
            ),

            new Phase('topics', 'Importing topics…',
                fn () => (int) Src::connect($cfg)->table('topics')->whereNull('deleted_at')->where('archetype', 'regular')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($hasTags) {
                    $rows = $ctx->src()->table('topics')->whereNull('deleted_at')->where('archetype', 'regular')
                        ->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('user_id')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('category_id')->all()) : [];
                    $map = [];
                    $n = 0;
                    foreach ($rows as $t) {
                        $cursor = $t->id;
                        $did = Dst::discussion($t->title ?: 'Untitled', $userMap[(string) $t->user_id] ?? null, Src::ts($t->created_at ?? null), ! empty($t->pinned_at));
                        $map[$t->id] = $did;
                        if ($hasTags && isset($tagMap[(string) $t->category_id])) {
                            Dst::attachTag($did, $tagMap[(string) $t->category_id]);
                        }
                        $n++;
                    }
                    $ctx->mapPut('topic', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['topics' => $n]];
                }
            ),

            new Phase('posts', 'Importing posts…',
                fn () => (int) Src::connect($cfg)->table('posts')->whereNull('deleted_at')->where('post_type', 1)->count(),
                fn ($cursor, $limit, Ctx $ctx) => Phases::postsBatch($cursor, $limit, $ctx,
                    fn ($conn, $cur, $lim) => $conn->table('posts')->whereNull('deleted_at')->where('post_type', 1)
                        ->where(fn ($q) => $q->where('topic_id', '>', (int) $cur['tid'])
                            ->orWhere(fn ($q2) => $q2->where('topic_id', (int) $cur['tid'])->where('id', '>', (int) $cur['pid'])))
                        ->orderBy('topic_id')->orderBy('id')->limit($lim)->get(),
                    fn ($post) => [
                        'tid' => (int) $post->topic_id, 'pid' => (int) $post->id, 'uid' => $post->user_id,
                        'html' => Src::sanitizeHtml($post->cooked ?? ''), 'at' => Src::ts($post->created_at ?? null), 'ok' => true,
                    ]
                )
            ),
        ], Phases::tail());
    }
}
