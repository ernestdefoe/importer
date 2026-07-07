<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * XenForo 1.x / 2.x → Flarum.
 *   xf_node (Forum) → tags · xf_user → users · xf_thread → discussions · xf_post → posts
 * Passwords come from xf_user_authenticate (XF2 stores the bcrypt under `hash`);
 * anything non-bcrypt is reset. Post bodies are BBCode.
 */
class XenForoImporter
{
    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $sb = $conn->getSchemaBuilder();
        foreach (['xf_user', 'xf_thread', 'xf_post'] as $req) {
            if (! $sb->hasTable($req)) {
                throw new \RuntimeException("This doesn't look like a XenForo database (missing “{$req}”).");
            }
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table('xf_user')->count(),
            'categories' => $sb->hasTable('xf_node') ? (int) $conn->table('xf_node')->where('node_type_id', 'Forum')->count() : 0,
            'topics' => (int) $conn->table('xf_thread')->count(),
            'posts' => (int) $conn->table('xf_post')->count(),
        ]];
    }

    /** @return Phase[] */
    public static function phases(array $cfg): array
    {
        $hasTags = Dst::hasTags();

        return array_merge([
            new Phase('tags', 'Importing categories…',
                fn () => ($hasTags && Src::connect($cfg)->getSchemaBuilder()->hasTable('xf_node')) ? (int) Src::connect($cfg)->table('xf_node')->where('node_type_id', 'Forum')->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($hasTags) {
                    if (! $hasTags || ! $ctx->src()->getSchemaBuilder()->hasTable('xf_node')) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $rows = $ctx->src()->table('xf_node')->where('node_type_id', 'Forum')->where('node_id', '>', (int) $cursor)->orderBy('node_id')->limit($limit)->get();
                    $map = [];
                    $n = 0;
                    foreach ($rows as $c) {
                        $cursor = $c->node_id;
                        $map[$c->node_id] = Dst::tag($c->title ?: 'Forum', Src::tagSlug($c->title ?: 'forum', (int) $c->node_id), $c->description ?? null, null, (int) ($c->display_order ?? 0));
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase('users', 'Importing members…',
                fn () => (int) Src::connect($cfg)->table('xf_user')->count(),
                function ($cursor, $limit, Ctx $ctx) {
                    $conn = $ctx->src();
                    $rows = $conn->table('xf_user')->where('user_id', '>', (int) $cursor)->orderBy('user_id')->limit($limit)->get();
                    $pw = [];
                    if (count($rows) && $conn->getSchemaBuilder()->hasTable('xf_user_authenticate')) {
                        foreach ($conn->table('xf_user_authenticate')->whereIn('user_id', $rows->pluck('user_id')->all())->get(['user_id', 'data']) as $a) {
                            $arr = @unserialize((string) $a->data);
                            if (is_array($arr) && ! empty($arr['hash']) && is_string($arr['hash'])) {
                                $pw[$a->user_id] = $arr['hash'];
                            }
                        }
                    }
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->user_id;
                        $email = trim((string) ($u->email ?? ''));
                        if ($email === '') {
                            $skip++;

                            continue;
                        }
                        try {
                            $map[$u->user_id] = Dst::user(Src::username($u->username ?? null, (int) $u->user_id), $email, $pw[$u->user_id] ?? null, Src::ts($u->register_date ?? null));
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
                fn () => (int) Src::connect($cfg)->table('xf_thread')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($hasTags) {
                    $rows = $ctx->src()->table('xf_thread')->where('thread_id', '>', (int) $cursor)->orderBy('thread_id')->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('user_id')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('node_id')->all()) : [];
                    $map = [];
                    $n = 0;
                    foreach ($rows as $t) {
                        $cursor = $t->thread_id;
                        if (($t->discussion_state ?? 'visible') !== 'visible') {
                            continue;
                        }
                        $did = Dst::discussion($t->title ?: 'Untitled', $userMap[(string) $t->user_id] ?? null, Src::ts($t->post_date ?? null), (bool) ($t->sticky ?? false));
                        $map[$t->thread_id] = $did;
                        if ($hasTags && isset($tagMap[(string) $t->node_id])) {
                            Dst::attachTag($did, $tagMap[(string) $t->node_id]);
                        }
                        $n++;
                    }
                    $ctx->mapPut('topic', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['topics' => $n]];
                }
            ),

            new Phase('posts', 'Importing posts…',
                fn () => (int) Src::connect($cfg)->table('xf_post')->count(),
                fn ($cursor, $limit, Ctx $ctx) => Phases::postsBatch($cursor, $limit, $ctx,
                    fn ($conn, $cur, $lim) => $conn->table('xf_post')
                        ->where(fn ($q) => $q->where('thread_id', '>', (int) $cur['tid'])
                            ->orWhere(fn ($q2) => $q2->where('thread_id', (int) $cur['tid'])->where('post_id', '>', (int) $cur['pid'])))
                        ->orderBy('thread_id')->orderBy('post_id')->limit($lim)->get(),
                    fn ($post) => [
                        'tid' => (int) $post->thread_id, 'pid' => (int) $post->post_id, 'uid' => $post->user_id,
                        'html' => Bbcode::toHtml($post->message ?? ''), 'at' => Src::ts($post->post_date ?? null),
                        'ok' => ($post->message_state ?? 'visible') === 'visible',
                    ]
                )
            ),
        ], Phases::tail());
    }
}
