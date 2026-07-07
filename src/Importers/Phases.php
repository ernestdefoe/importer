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
                        $db->table('users')->whereIn('id', $ids)->update([
                            'discussion_count' => $db->raw('(SELECT COUNT(*) FROM discussions WHERE discussions.user_id = users.id)'),
                            'comment_count' => $db->raw("(SELECT COUNT(*) FROM posts WHERE posts.user_id = users.id AND posts.type = 'comment')"),
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
                        $db->table('tags')
                            ->whereIn('id', $db->table('importer_map')->where('run_id', $ctx->runId)->where('kind', 'tag')->pluck('target_id'))
                            ->update(['discussion_count' => $db->raw('(SELECT COUNT(*) FROM discussion_tag WHERE discussion_tag.tag_id = tags.id)')]);
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
