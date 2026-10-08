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
            new Phase('counts-users', 'Updating member counts…',
                fn () => 0,
                function ($cursor, $limit, Ctx $ctx) {
                    $db = Dst::db();
                    $rows = $db->table('importer_map')->where('run_id', $ctx->runId)->where('kind', 'user')
                        ->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get(['id', 'target_id']);
                    $ids = [];
                    foreach ($rows as $r) {
                        $cursor = $r->id;
                        $ids[] = (int) $r->target_id;
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
            new Phase('counts-tags', 'Finishing up…',
                fn () => 0,
                function ($cursor, $limit, Ctx $ctx) {
                    if (Dst::hasTags()) {
                        $db = Dst::db();
                        $ids = $db->table('importer_map')->where('run_id', $ctx->runId)->where('kind', 'tag')->pluck('target_id');

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
     * A generic "posts ordered by (topic, id)" phase batch — the shape shared by
     * phpBB / XenForo / vBulletin / MyBB / SMF. Callers supply a fetcher and a
     * row→(topicSrcId, postSrcId, userSrcId, html, createdAt, visible) mapper.
     *
     * @param  \Closure  $fetch  fn($conn, array $cur, int $limit): iterable  — rows > cursor, ordered (topic,id)
     * @param  \Closure  $map    fn($row): array{tid:int,pid:int,uid:mixed,html:string,at:\Illuminate\Support\Carbon,ok:bool}
     */
    public static function postsBatch($cursor, int $limit, Ctx $ctx, \Closure $fetch, \Closure $map): array
    {
        $cur = is_array($cursor) ? $cursor : ['tid' => 0, 'pid' => 0, 'carry' => null];
        $carry = $cur['carry'] ?? null;

        $rows = $fetch($ctx->src(), $cur, $limit);
        $rows = is_array($rows) ? $rows : iterator_to_array($rows);

        $topicSrcIds = $userSrcIds = [];
        $mapped = [];
        foreach ($rows as $row) {
            $m = $map($row);
            $mapped[] = $m;
            $topicSrcIds[] = $m['tid'];
            $userSrcIds[] = $m['uid'];
        }
        $topicMap = $ctx->mapGet('topic', $topicSrcIds);
        $userMap = $ctx->mapGet('user', $userSrcIds);

        $n = 0;
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
                Dst::post($did, $num, $userMap[(string) $m['uid']] ?? null, $m['html'], $m['at']);
                $n++;
            } catch (\Throwable) {
                $carry['num']--;
            }
        }

        $done = count($mapped) < $limit;
        if ($done && $carry && ! empty($carry['did'])) {
            Dst::finalizeDiscussion((int) $carry['did']);
        }
        $cur['carry'] = $done ? null : $carry;

        return ['cursor' => $cur, 'processed' => count($mapped), 'done' => $done, 'summary' => ['posts' => $n]];
    }
}
