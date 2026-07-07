<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * Convoro → Flarum (the reverse of Convoro's own Flarum importer).
 *   categories → tags · users → users · topics → discussions · posts → posts
 * Convoro is a Laravel forum that hashes with bcrypt, so passwords copy straight
 * across and members keep their logins. Post bodies are stored as rendered HTML
 * (body_html), which runs through the normal HTML → Markdown → formatter pipeline.
 */
class ConvoroImporter
{
    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $sb = $conn->getSchemaBuilder();
        foreach (['users', 'categories', 'topics', 'posts'] as $req) {
            if (! $sb->hasTable($req)) {
                throw new \RuntimeException("This doesn't look like a Convoro database (missing “{$req}”).");
            }
        }
        // Disambiguate from other “users/topics/posts” schemas by Convoro's columns.
        if (! $sb->hasColumn('posts', 'body_html') || ! $sb->hasColumn('topics', 'is_pinned')) {
            throw new \RuntimeException("This database has the right table names but not Convoro's columns — is it really a Convoro forum?");
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table('users')->count(),
            'categories' => (int) $conn->table('categories')->count(),
            'topics' => (int) $conn->table('topics')->count(),
            'posts' => (int) $conn->table('posts')->count(),
        ]];
    }

    /** @return Phase[] */
    public static function phases(array $cfg): array
    {
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
                fn () => (int) Src::connect($cfg)->table('users')->count(),
                function ($cursor, $limit, Ctx $ctx) {
                    $rows = $ctx->src()->table('users')->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get();
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->id;
                        $email = trim((string) ($u->email ?? ''));
                        if ($email === '') {
                            $skip++;

                            continue;
                        }
                        try {
                            $map[$u->id] = Dst::user(Src::username($u->name ?? null, (int) $u->id), $email, $u->password ?? null, Src::ts($u->created_at ?? null));
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
                fn () => (int) Src::connect($cfg)->table('topics')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($hasTags) {
                    $rows = $ctx->src()->table('topics')->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('user_id')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('category_id')->all()) : [];
                    $map = [];
                    $n = 0;
                    foreach ($rows as $t) {
                        $cursor = $t->id;
                        $did = Dst::discussion($t->title ?: 'Untitled', $userMap[(string) $t->user_id] ?? null, Src::ts($t->created_at ?? null), (bool) ($t->is_pinned ?? false), (bool) ($t->is_locked ?? false));
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
                fn () => (int) Src::connect($cfg)->table('posts')->count(),
                fn ($cursor, $limit, Ctx $ctx) => Phases::postsBatch($cursor, $limit, $ctx,
                    fn ($conn, $cur, $lim) => $conn->table('posts')
                        ->where(fn ($q) => $q->where('topic_id', '>', (int) $cur['tid'])
                            ->orWhere(fn ($q2) => $q2->where('topic_id', (int) $cur['tid'])->where('id', '>', (int) $cur['pid'])))
                        ->orderBy('topic_id')->orderBy('id')->limit($lim)->get(),
                    fn ($post) => [
                        'tid' => (int) $post->topic_id, 'pid' => (int) $post->id, 'uid' => $post->user_id,
                        'html' => (string) ($post->body_html ?? ''), 'at' => Src::ts($post->created_at ?? null), 'ok' => true,
                    ]
                )
            ),
        ], Phases::tail());
    }
}
