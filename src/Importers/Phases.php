<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * Shared phases every importer ends with — chunked recounts of the members and
 * tags it touched (not source-specific), so profile + tag counters are correct.
 * Importers append these with `...Phases::tail()`.
 */
class Phases
{
    /** @return Phase[] */
    public static function tail(): array
    {
        return [
            new Phase(
                'counts-users',
                'member_counts',
                fn () => 0,
                function ($cursor, $limit, Ctx $ctx) {
                    $db = Dst::db();
                    $ids = [];
                    if ($ctx->sync) {
                        // A sync recounts only the members who have a post it wrote.
                        $ids = $db->table('posts')->where('id', '>', (int) $ctx->sync['postHw'])->where('user_id', '>', (int) $cursor)
                            ->distinct()->orderBy('user_id')->limit($limit)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
                        $rows = $ids;
                        $cursor = $ids ? max($ids) : $cursor;
                    } else {
                        $rows = $db->table('importer_map')->where('run_id', $ctx->runId)->where('kind', 'user')
                            ->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get(['id', 'target_id']);
                        foreach ($rows as $r) {
                            $cursor = $r->id;
                            $ids[] = (int) $r->target_id;
                        }
                    }
                    if ($ids) {
                        /*
                         * 🚨 Raw expressions are passed through VERBATIM — the
                         * query builder only prefixes identifiers it wraps
                         * itself, so `table()` and `whereIn()` came out right
                         * while these correlated subqueries did not. On a forum
                         * with a table prefix the whole counts phase died on
                         * "Table 'discussions' doesn't exist" at the very end of
                         * an otherwise finished import. Interpolate the prefix
                         * exactly as core does (Flarum\Post\Post::boot()); an
                         * unprefixed install returns '' and is unchanged.
                         */
                        $p = $db->getTablePrefix();
                        $db->table('users')->whereIn('id', $ids)->update([
                            'discussion_count' => $db->raw("(SELECT COUNT(*) FROM {$p}discussions WHERE {$p}discussions.user_id = {$p}users.id)"),
                            'comment_count' => $db->raw("(SELECT COUNT(*) FROM {$p}posts WHERE {$p}posts.user_id = {$p}users.id AND {$p}posts.type = 'comment')"),
                        ]);
                    }

                    return ['cursor' => (int) $cursor, 'processed' => 0, 'done' => count($rows) < $limit, 'summary' => []];
                }
            ),
            new Phase(
                'counts-tags',
                'finishing',
                fn () => 0,
                function ($cursor, $limit, Ctx $ctx) {
                    if (Dst::hasTags()) {
                        $db = Dst::db();
                        $ids = $ctx->sync
                            // A sync: the tags of the discussions it wrote posts into.
                            ? $db->table('discussion_tag')->whereIn('discussion_id', $db->table('posts')->where('id', '>', (int) $ctx->sync['postHw'])->select('discussion_id'))->distinct()->pluck('tag_id')
                            : $db->table('importer_map')->where('run_id', $ctx->runId)->where('kind', 'tag')->pluck('target_id');

                        /*
                         * 🚨 The last-posted pointers as well as the count.
                         *
                         * Flarum keeps `tags.last_posted_*` current by reacting
                         * to posts as they arrive; an import writes rows straight
                         * into the database and nothing ever fires, so every tag
                         * ended a run claiming no discussions at all while its
                         * own `discussion_count` beside it said seven.
                         *
                         * It fails QUIETLY, which is what makes it worth fixing
                         * here: nothing errors, the board simply says "No
                         * discussions yet" on forums that are full of them, and
                         * it reads as a theme bug rather than an import one.
                         *
                         * Correlated subqueries against `discussion_tag`, so this
                         * stays one statement over the run's tags rather than a
                         * query per tag on a board with a hundred and fifty.
                         */
                        // Prefix every table named inside the raw SQL below —
                        // see the counts-users phase for why.
                        $p = $db->getTablePrefix();

                        $newest = "SELECT d.id FROM {$p}discussion_tag dt
                                     JOIN {$p}discussions d ON d.id = dt.discussion_id
                                    WHERE dt.tag_id = {$p}tags.id
                                      AND d.is_private = false
                                      AND d.hidden_at IS NULL
                                      AND d.comment_count > 0
                                 ORDER BY d.last_posted_at DESC
                                    LIMIT 1";

                        $db->table('tags')
                            ->whereIn('id', $ids)
                            ->update([
                                'discussion_count' => $db->raw("(SELECT COUNT(*) FROM {$p}discussion_tag WHERE {$p}discussion_tag.tag_id = {$p}tags.id)"),
                                'last_posted_discussion_id' => $db->raw("({$newest})"),
                                'last_posted_at' => $db->raw("(SELECT d.last_posted_at FROM {$p}discussions d WHERE d.id = ({$newest}))"),
                                'last_posted_user_id' => $db->raw("(SELECT d.last_posted_user_id FROM {$p}discussions d WHERE d.id = ({$newest}))"),
                            ]);
                    }

                    return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                }
            ),
        ];
    }

    /**
     * Opt-in cleanup phase: delete tags this run created that ended up with
     * zero discussions (a category whose vBulletin/phpBB/etc. equivalent
     * genuinely had no topics, or a placement fold that only ever produced
     * empty intermediate nodes). Only ever touches tags in *this* run's own
     * importer_map — never pre-existing tags the admin created by hand.
     * Call from an importer's phases() only when the caller opts in (e.g.
     * --prune-empty-tags on the CLI), never unconditionally: silently
     * deleting content a fresh admin panel run didn't ask for is a bad
     * default even though it's harmless here (tags carry no other data).
     *
     * @return Phase[]
     */
    public static function pruneEmptyTags(): array
    {
        return [
            new Phase(
                'prune-empty-tags',
                'prune_tags',
                fn () => 0,
                function ($cursor, $limit, Ctx $ctx) {
                    if (! Dst::hasTags()) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $db = Dst::db();
                    $tagIds = $db->table('importer_map')->where('run_id', $ctx->runId)->where('kind', 'tag')->pluck('target_id');
                    $emptyIds = $db->table('tags')->whereIn('id', $tagIds)
                        ->where('discussion_count', 0)
                        // A primary tag with empty secondaries under it should keep
                        // those secondaries visible for now — only remove a tag with
                        // no discussions AND no children relying on it as a parent.
                        ->whereNotIn('id', $db->table('tags')->whereNotNull('parent_id')->pluck('parent_id'))
                        ->pluck('id');
                    $n = $emptyIds->count();
                    if ($n) {
                        $db->table('tags')->whereIn('id', $emptyIds)->delete();
                    }

                    return ['cursor' => null, 'processed' => $n, 'done' => true, 'summary' => ['pruned_tags' => $n]];
                }
            ),
        ];
    }

    /**
     * The posts phase shared by phpBB / XenForo / vBulletin 3–4 / MyBB / SMF /
     * Invision / Discourse / Convoro / Web Wiz — plus, where the source keeps
     * an edit time, the phase that copies edits across on a sync.
     *
     * A full import walks the posts ordered by (topic, id), numbering each
     * discussion as it goes. A sync walks only the ids past the last one it
     * has, by id, and appends to whichever discussion each post belongs to.
     *
     * @param  string  $table  source posts table (prefixed)
     * @param  string  $tid  its topic column
     * @param  string  $pid  its post-id column
     * @param  \Closure  $count  fn(): int — total source rows (for the % bar)
     * @param  \Closure  $map  fn($row): array{tid:int,pid:int,uid:mixed,html:string,at:\Illuminate\Support\Carbon,ok:bool}
     * @param  \Closure|null  $filter  fn(Builder $q): Builder — the source's own visibility rules
     * @param  string|null  $edited  column holding the source's last-edit time (unix seconds), if it has one
     * @return Phase[]
     */
    public static function posts(string $table, string $tid, string $pid, \Closure $count, \Closure $map, ?\Closure $filter = null, ?string $edited = null): array
    {
        $query = function (Ctx $ctx) use ($table, $filter) {
            $q = $ctx->src()->table($table);

            return $filter ? $filter($q) : $q;
        };

        return [
            new Phase('posts', 'posts', $count, fn ($cursor, $limit, Ctx $ctx) => $ctx->sync
                ? self::syncPosts($cursor, $limit, $ctx, $query, $pid, $map)
                : self::postsBatch($cursor, $limit, $ctx, $query, $tid, $pid, $map), true),
            new Phase('edits', 'edits', fn () => 0, fn ($cursor, $limit, Ctx $ctx) => $ctx->sync && $edited
                && $ctx->src()->getSchemaBuilder()->hasColumn($table, $edited)
                ? self::edits($cursor, $limit, $ctx, $query, $pid, $map, $edited)
                : ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []]),
        ];
    }

    /** A full import: posts ordered by (topic, id), a running number per discussion carried in the cursor. */
    private static function postsBatch(mixed $cursor, int $limit, Ctx $ctx, \Closure $query, string $tid, string $pid, \Closure $map): array
    {
        $cur = is_array($cursor) ? $cursor : ['tid' => 0, 'pid' => 0, 'carry' => null];
        $carry = $cur['carry'] ?? null;

        $rows = $query($ctx)
            ->where(fn ($q) => $q->where($tid, '>', (int) $cur['tid'])
                ->orWhere(fn ($q2) => $q2->where($tid, (int) $cur['tid'])->where($pid, '>', (int) $cur['pid'])))
            ->orderBy($tid)->orderBy($pid)->limit($limit)->get();

        $mapped = [];
        foreach ($rows as $row) {
            $mapped[] = $map($row);
        }
        $topicMap = $ctx->mapGet('topic', array_column($mapped, 'tid'));
        $userMap = $ctx->mapGet('user', array_column($mapped, 'uid'));
        Dst::prepare($ctx, array_column($mapped, 'html'));

        $n = 0;
        $ids = $hashes = [];
        foreach ($mapped as $m) {
            $cur['tid'] = (int) $m['tid'];
            $cur['pid'] = (int) $m['pid'];
            if (empty($m['ok'])) {
                continue;
            }
            $did = $topicMap[(string) $m['tid']] ?? null;
            if (! $did) {
                continue;
            }
            if (! $carry || (int) $carry['tid'] !== (int) $m['tid']) {
                if ($carry && ! empty($carry['did'])) {
                    Dst::finalizeDiscussion((int) $carry['did']);
                }
                $carry = ['tid' => (int) $m['tid'], 'did' => $did, 'num' => 0];
            }
            $num = ++$carry['num'];

            try {
                $content = Dst::content($m['html']);
                $ids[$m['pid']] = Dst::postContent($did, $num, $userMap[(string) $m['uid']] ?? null, $content, $m['at']);
                Dst::ref('post', (string) $m['pid'], $ids[$m['pid']]);
                $hashes[$m['pid']] = sha1($content);
                $n++;
            } catch (\Throwable) {
                $carry['num']--;
            }
        }
        // Posts are mapped too, so a later sync knows where it got to and which
        // Flarum post an edit on the old board belongs to.
        $ctx->mapPut('post', $ids, $hashes);

        $done = count($mapped) < $limit;
        if ($done && $carry && ! empty($carry['did'])) {
            Dst::finalizeDiscussion((int) $carry['did']);
        }
        $cur['carry'] = $done ? null : $carry;

        return ['cursor' => $cur, 'processed' => count($mapped), 'done' => $done, 'summary' => ['posts' => $n]];
    }

    /**
     * A sync: source posts past the cursor, by id — new replies in old topics
     * as well as new topics. Each is appended to its mapped discussion after
     * whatever that discussion holds now, including replies written here.
     *
     * 🚨 Imports made before 0.4.0 never mapped their posts, so the first sync
     * of one starts at post 0 and must not copy those posts a second time. A
     * post that is already in its discussion — same discussion, same second —
     * is adopted into the map instead of inserted. That is also what keeps a
     * catch-up pass over the last few hundred ids from duplicating anything.
     */
    private static function syncPosts(mixed $cursor, int $limit, Ctx $ctx, \Closure $query, string $pid, \Closure $map): array
    {
        $after = (int) $cursor;
        $rows = $query($ctx)->where($pid, '>', $after)->orderBy($pid)->limit($limit)->get();

        $mapped = [];
        foreach ($rows as $row) {
            $m = $map($row);
            $after = max($after, (int) $m['pid']);
            if (! empty($m['ok'])) {
                $mapped[(string) $m['pid']] = $m;
            }
        }
        $mapped = array_diff_key($mapped, $ctx->mapGet('post', array_keys($mapped)));
        $topicMap = $ctx->mapGet('topic', array_column($mapped, 'tid'));
        $userMap = $ctx->mapGet('user', array_column($mapped, 'uid'));
        Dst::prepare($ctx, array_column($mapped, 'html'));

        $todo = [];
        foreach ($mapped as $key => $m) {
            if (($did = $topicMap[(string) $m['tid']] ?? null) && $m['at']) {
                $todo[$key] = $m + ['did' => $did, 'stamp' => $m['at']->format('Y-m-d H:i:s')];
            }
        }

        $n = 0;
        $adopt = $ids = $hashes = $touched = [];
        if ($todo) {
            $db = Dst::db();
            $dids = array_values(array_unique(array_column($todo, 'did')));

            // Comments already here at those moments (oldest number first).
            $here = [];
            foreach ($db->table('posts')->whereIn('discussion_id', $dids)->where('type', 'comment')
                ->whereIn('created_at', array_values(array_unique(array_column($todo, 'stamp'))))
                ->orderBy('number')->get(['id', 'discussion_id', 'created_at']) as $p) {
                $here[$p->discussion_id.'|'.substr((string) $p->created_at, 0, 19)][] = (int) $p->id;
            }
            $next = $db->table('posts')->whereIn('discussion_id', $dids)->groupBy('discussion_id')
                ->selectRaw('discussion_id, MAX(number) n')->pluck('n', 'discussion_id')->all();

            foreach ($todo as $key => $m) {
                $at = $m['did'].'|'.$m['stamp'];
                if (! empty($here[$at])) {
                    $adopt[$key] = array_shift($here[$at]);

                    continue;
                }
                $content = Dst::content($m['html']);
                $num = (int) ($next[$m['did']] ?? 0) + 1;

                try {
                    $ids[$key] = Dst::postContent($m['did'], $num, $userMap[(string) $m['uid']] ?? null, $content, $m['at']);
                    Dst::ref('post', (string) $key, $ids[$key]);
                } catch (\Throwable) {
                    continue; // as a full import does: one bad row is not the whole sync
                }
                $next[$m['did']] = $num;
                $hashes[$key] = sha1($content);
                $touched[$m['did']] = true;
                $n++;
            }
        }
        $ctx->mapPut('post', $adopt);
        $ctx->mapPut('post', $ids, $hashes);
        foreach (array_keys($touched) as $did) {
            Dst::finalizeDiscussion((int) $did);
        }

        return ['cursor' => $after, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['posts' => $n]];
    }

    /**
     * A sync: re-convert posts edited on the old board since the last run.
     *
     * 🚨 Only over a post nobody has touched here. Its text must still hash to
     * what the importer wrote (or, for a post adopted from an older import
     * that kept no hash, never have been edited on this forum). Anything else
     * was edited on Flarum, and that edit wins — it is counted, never lost.
     */
    private static function edits(mixed $cursor, int $limit, Ctx $ctx, \Closure $query, string $pid, \Closure $map, string $edited): array
    {
        $rows = $query($ctx)->where($edited, '>', (int) ($ctx->sync['since'] ?? 0))->where($pid, '>', (int) $cursor)
            ->orderBy($pid)->limit($limit)->get();

        $mapped = [];
        foreach ($rows as $row) {
            $m = $map($row);
            $cursor = max((int) $cursor, (int) $m['pid']);
            if (! empty($m['ok'])) {
                $mapped[(string) $m['pid']] = $m + ['editedAt' => Src::ts($row->$edited)];
            }
        }
        $known = $ctx->mapRows('post', array_keys($mapped));
        Dst::prepare($ctx, array_column($mapped, 'html'));
        $db = Dst::db();
        $posts = $known ? $db->table('posts')->whereIn('id', array_column($known, 0))->get(['id', 'content', 'edited_at', 'user_id'])->keyBy('id') : collect();

        $n = $kept = 0;
        foreach ($known as $key => [$target, $hash]) {
            if (! $post = $posts->get($target)) {
                continue; // deleted here — never brought back
            }
            $untouched = $hash !== null ? sha1((string) $post->content) === $hash : $post->edited_at === null;
            if (! $untouched) {
                $kept++;

                continue;
            }
            $content = Dst::content($mapped[$key]['html']);
            if ($content === $post->content) {
                continue;
            }
            $db->table('posts')->where('id', $target)->update(['content' => $content, 'edited_at' => $mapped[$key]['editedAt'], 'edited_user_id' => $post->user_id]);
            Dst::mentions('posts', [$target => $content], true);
            $db->table('importer_map')->where('run_id', $ctx->runId)->where('kind', 'post')->where('source_id', (string) $key)->update(['hash' => sha1($content)]);
            $n++;
        }

        return ['cursor' => $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['edited' => $n, 'kept' => $kept]];
    }
}
