<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Private messages → flarum/messages, for every source that keeps them.
 *
 * Each importer describes its old board's messages with a `privateMessages()`
 * method returning `['count' => fn (): int, 'fetch' => fn (int $after, int
 * $limit, Ctx $ctx): array]`. The fetch walks the source in id order and hands
 * back one plain row per message (see {@see write()} for the shape); everything
 * Flarum-side is here, so the twelve sources share one set of rules:
 *
 *  - two people → the one flarum/messages dialog between them (Flarum keeps a
 *    single conversation per pair, so every old thread between the same two
 *    members lands in it, in order);
 *  - three or more → a Group Messages conversation when that extension is on,
 *    otherwise skipped and counted. 🚨 Never split into pairs: that would show
 *    each pair messages that were addressed to other people as well;
 *  - a blind-copied recipient gets their own copy in a dialog with the sender,
 *    so the other recipients never learn they were there;
 *  - a message every participant deleted is not imported, and nor is a
 *    draft; somebody who deleted their copies (or left) is not made a member;
 *  - authors and recipients who were not imported (deleted accounts) stay as
 *    Flarum shows a deleted member, exactly as posts do: no author, no member.
 *
 * 🚨 Message bodies are private. Nothing here logs or reports them; the only
 * thing that leaves a batch is a count.
 */
class PrivateMessages
{
    /** Source ids re-read below the last cursor on a sync, in case of rows written late. */
    private const CATCHUP = 100;

    public static function enabled(): bool
    {
        return self::extension('flarum-messages') && Dst::db()->getSchemaBuilder()->hasTable('dialog_messages');
    }

    public static function groupsEnabled(): bool
    {
        return self::extension('ernestdefoe-group-messages') && Dst::db()->getSchemaBuilder()->hasTable('group_dialogs');
    }

    private static function extension(string $id): bool
    {
        try {
            return resolve(\Flarum\Extension\ExtensionManager::class)->isEnabled($id);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Whether this run should bring messages across (the wizard's switch; on unless turned off). */
    public static function wanted(array $cfg): bool
    {
        return ! array_key_exists('private_messages', $cfg)
            || filter_var($cfg['private_messages'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    /** @param  class-string  $importer */
    public static function supported(string $importer): bool
    {
        return method_exists($importer, 'privateMessages');
    }

    /**
     * How many messages the old board holds, for the connection test. Null when
     * the source keeps none (or they cannot be counted).
     *
     * @param  class-string  $importer
     */
    public static function count(string $importer, array $cfg): ?int
    {
        try {
            $def = self::supported($importer) ? $importer::privateMessages($cfg) : null;

            return $def ? (int) ($def['count'])() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  class-string  $importer
     * @return Phase[]
     */
    public static function phases(string $importer, array $cfg): array
    {
        if (! self::supported($importer)) {
            return [];
        }
        $on = self::wanted($cfg) && self::enabled();
        $def = $on ? $importer::privateMessages($cfg) : null;

        return [
            new Phase(
                'pms',
                'messages',
                fn () => $def ? (int) ($def['count'])() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($def, $cfg) {
                    if (! $def) {
                        // Said once, in the summary: why no messages came across.
                        $why = self::wanted($cfg) ? ['pms_unavailable' => 1] : [];

                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => $why];
                    }
                    $res = ($def['fetch'])((int) $cursor, $limit, $ctx);
                    $summary = self::write($res['messages'], $ctx);
                    self::remember($ctx, (int) $res['cursor']);

                    return ['cursor' => (int) $res['cursor'], 'processed' => (int) $res['rows'], 'done' => (bool) $res['done'], 'summary' => $summary];
                },
                true,
            ),
            new Phase('pms-finish', 'messages', fn () => 0, fn ($cursor, $limit, Ctx $ctx) => $def
                ? self::reorder((int) $cursor, $ctx)
                : ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []]),
        ];
    }

    /**
     * Where a sync's message phase starts: a little before where the last run
     * stopped. From the start again when group conversations were skipped and
     * Group Messages has been switched on since, so they come across now.
     */
    public static function syncFrom(int $baseRunId): int
    {
        $db = Dst::db();
        $map = fn (string $kind) => $db->table('importer_map')->where('run_id', $baseRunId)->where('kind', $kind);
        if (self::groupsEnabled() && $map('pm-skip')->exists()) {
            $map('pm-skip')->delete();

            return 0;
        }

        return max(0, (int) $map('pm-cursor')->value('target_id') - self::CATCHUP);
    }

    private static function remember(Ctx $ctx, int $cursor): void
    {
        Dst::db()->table('importer_map')->updateOrInsert(
            ['run_id' => $ctx->runId, 'kind' => 'pm-cursor', 'source_id' => '0'],
            ['target_id' => max(0, $cursor)]
        );
    }

    /** Source user ids as unique non-empty strings (0 and below are "nobody" on every source). */
    public static function ids(iterable $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id !== '' && (! is_numeric($id) || (int) $id > 0)) {
                $out[$id] = true;
            }
        }

        return array_map('strval', array_keys($out));
    }

    /**
     * The conversation key for a source that stores loose messages rather than
     * conversations (phpBB, vBulletin, MyBB, SMF): its thread, if it has one,
     * and exactly who the message went to — so a reply sent to fewer people
     * never joins a conversation the others are in.
     */
    public static function conv(string $thread, array $people): string
    {
        $people = self::ids($people);
        sort($people);

        return sha1($thread.'|'.implode(',', $people));
    }

    /**
     * Write one batch.
     *
     * Bounded work per batch, whatever its size: two reads of the id map, one
     * insert for all the messages and one read of their ids, one update for
     * the dialogs and one for the read markers. Only a NEW dialog is inserted
     * on its own, because its id is needed straight away.
     *
     * @param  list<array{id:string,conv:string,title?:?string,uid:mixed,people:array,bcc?:array,keep?:?array,unread?:array,html:string,at:Carbon,thread?:?string,aliases?:list<string>}>  $messages
     *         `id` unique per message (≤ 60 chars); `conv` the source conversation;
     *         `people` everybody it was visibly addressed to (the author is added);
     *         `bcc` blind copies; `keep` who still has it (null: everyone);
     *         `unread` who has not read it yet; `thread` the conversation's id where
     *         the old board's addresses name one (/conversations/…12/) and `aliases`
     *         other ids of this same message there (one member's copy of it), so
     *         old links to either can be redirected
     * @return array<string,int>
     */
    public static function write(array $messages, Ctx $ctx): array
    {
        $sum = ['messages' => 0, 'conversations' => 0];
        $groups = self::groupsEnabled();

        // One delivery per audience: the message itself, and a copy per blind recipient.
        $todo = [];
        foreach ($messages as $m) {
            $people = self::ids(array_merge([$m['uid']], $m['people']));
            $keep = isset($m['keep']) ? self::ids($m['keep']) : null;
            $base = ['uid' => self::ids([$m['uid']])[0] ?? null, 'unread' => self::ids($m['unread'] ?? [])] + $m;
            $todo[] = ['people' => $people, 'keep' => $keep ?? $people] + $base;
            foreach (array_diff(self::ids($m['bcc'] ?? []), $people) as $b) {
                $pair = self::ids([$m['uid'], $b]);
                $todo[] = ['id' => $m['id'].':b'.$b, 'people' => $pair, 'keep' => $keep ?? $pair, 'thread' => null, 'aliases' => []] + $base;
            }
        }

        $ready = $skips = [];
        foreach ($todo as $d) {
            $keepers = array_values(array_intersect($d['keep'], $d['people']));
            if (count($d['people']) < 2 || ! $keepers) {
                continue; // a note to self · deleted by everyone
            }
            if (count($d['people']) === 2) {
                $p = $d['people'];
                sort($p);
                $key = 'd:'.implode(':', $p);
            } else {
                $key = 'g:'.(strlen($d['conv']) > 60 ? sha1($d['conv']) : $d['conv']);
                if (! $groups) {
                    $skips[$key] = 0;

                    continue;
                }
            }
            $ready[] = ['key' => $key, 'keepers' => $keepers] + $d;
        }

        $known = $ctx->mapMany([
            'pm' => array_column($ready, 'id'),
            'pm-skip' => array_keys($skips),
            'pm-dialog' => array_column($ready, 'key'),
            'user' => $ready ? array_merge(...array_map(fn ($d) => array_merge($d['people'], [(string) $d['uid']]), $ready)) : [],
        ]);
        if ($skips) {
            $new = array_diff_key($skips, $known['pm-skip']);
            $ctx->mapPut('pm-skip', $new);
            if ($new) {
                $sum['pm_groups_skipped'] = count($new);
            }
        }
        $users = $known['user'];
        $member = fn (array $sourceIds) => array_values(array_unique(array_filter(array_map(fn ($id) => $users[$id] ?? null, $sourceIds))));

        // Already here, or nobody who could still see it was imported.
        $ready = array_values(array_filter($ready, fn ($d) => ! isset($known['pm'][$d['id']]) && $member($d['keepers'])));
        if (! $ready) {
            return $sum;
        }

        $db = Dst::db();
        $dialogs = $known['pm-dialog'] + self::existingDirect($ready, $known['pm-dialog'], $users);

        // Create what is missing, in message order.
        $created = $details = $pairs = [];
        foreach ($ready as $d) {
            $group = $d['key'][0] === 'g';
            // Two old accounts that became one member here share that member's dialogs.
            $pair = $member($d['people']);
            sort($pair);
            $pair = $group ? null : implode(':', $pair);
            if (isset($dialogs[$d['key']])) {
                if ($pair !== null) {
                    $pairs[$pair] ??= $dialogs[$d['key']];
                }

                continue;
            }
            if ($pair !== null && isset($pairs[$pair])) {
                $dialogs[$d['key']] = $pairs[$pair];
                $ctx->mapPut('pm-dialog', [$d['key'] => $pairs[$pair]]);

                continue;
            }
            $id = (int) $db->table('dialogs')->insertGetId(['type' => $group ? 'group' : 'direct', 'created_at' => $d['at'], 'updated_at' => $d['at']]);
            if ($group) {
                $title = trim(html_entity_decode((string) ($d['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $members = $member($d['keepers']);
                $author = $users[(string) $d['uid']] ?? null;
                $details[] = [
                    'dialog_id' => $id, 'title' => $title !== '' ? Str::limit($title, 150, '') : null,
                    // Whoever started it, if they are in it here; else its first member.
                    'owner_id' => in_array($author, $members, true) ? $author : $members[0], 'created_at' => $d['at'], 'updated_at' => $d['at'],
                ];
            } else {
                $pairs[$pair] = $id;
            }
            $dialogs[$d['key']] = $id;
            $created[$d['key']] = $id;
        }
        if ($details) {
            $db->table('group_dialogs')->insert($details);
        }
        $ctx->mapPut('pm-dialog', $created);
        $sum['conversations'] = count($created);
        $isNew = array_flip($created);

        $ids = array_values(array_unique(array_map(fn ($d) => $dialogs[$d['key']], $ready)));
        $prev = $db->table('dialogs')->whereIn('id', $ids)->pluck('last_message_id', 'id')->map(fn ($v) => (int) $v)->all();
        $state = [];
        foreach ($db->table('dialog_user')->whereIn('dialog_id', $ids)->get(['id', 'dialog_id', 'user_id', 'last_read_message_id']) as $s) {
            $state[$s->dialog_id][$s->user_id] = ['id' => (int) $s->id, 'read' => (int) $s->last_read_message_id];
        }
        $top = [];
        foreach ($db->table('dialog_messages')->whereIn('dialog_id', $ids)->groupBy('dialog_id')
            ->selectRaw('dialog_id, MAX(number) AS top_number, MAX(created_at) AS top_at')->get() as $t) {
            $top[$t->dialog_id] = ['n' => (int) $t->top_number, 'at' => substr((string) $t->top_at, 0, 19)];
        }
        $start = array_map(fn ($t) => $t['n'], $top);

        // The messages, numbered on from what each dialog holds; who joins with them.
        Dst::prepare($ctx, array_column($ready, 'html'), false);
        $rows = $joined = $placed = $reorder = $stored = [];
        foreach ($ready as $i => $d) {
            $did = $dialogs[$d['key']];
            foreach ($member($d['keepers']) as $u) {
                if (! isset($state[$did][$u])) {
                    $state[$did][$u] = ['id' => null, 'read' => null];
                    $joined[] = ['dialog_id' => $did, 'user_id' => $u, 'joined_at' => $d['at'], 'last_read_message_id' => 0];
                }
            }
            try {
                $content = Dst::content($d['html']);
            } catch (\Throwable) {
                continue; // as posts do: one unreadable message is not the whole run
            }
            $stamp = $d['at']->format('Y-m-d H:i:s');
            if (isset($top[$did]) && $stamp < $top[$did]['at']) {
                $reorder[(string) $did] = $did; // older than something the dialog already holds
            }
            $number = ($top[$did]['n'] ?? 0) + 1;
            $top[$did] = ['n' => $number, 'at' => max($stamp, $top[$did]['at'] ?? '')];
            $placed[$i] = $did.':'.$number;
            $stored[$did.':'.$number] = $content;
            $rows[] = ['dialog_id' => $did, 'user_id' => $users[(string) $d['uid']] ?? null, 'content' => $content, 'number' => $number, 'created_at' => $d['at'], 'updated_at' => $d['at']];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            $db->table('dialog_messages')->insert($chunk);
        }
        $newIds = [];
        if ($rows) {
            $q = $db->table('dialog_messages');
            foreach (array_unique(array_column($rows, 'dialog_id')) as $did) {
                $q->orWhere(fn ($w) => $w->where('dialog_id', $did)->where('number', '>', $start[$did] ?? 0));
            }
            foreach ($q->get(['id', 'dialog_id', 'number']) as $r) {
                $newIds[$r->dialog_id.':'.$r->number] = (int) $r->id;
            }
        }
        foreach (array_chunk($joined, 500) as $chunk) {
            $db->table('dialog_user')->insert($chunk);
        }
        if ($joined) {
            foreach ($db->table('dialog_user')->whereIn('dialog_id', array_unique(array_column($joined, 'dialog_id')))->get(['id', 'dialog_id', 'user_id']) as $s) {
                if ($state[$s->dialog_id][$s->user_id]['id'] === null) {
                    $state[$s->dialog_id][$s->user_id]['id'] = (int) $s->id;
                }
            }
        }

        $earlier = $ctx->mapMany([
            'pm-unread' => array_merge(...array_map(fn ($did) => array_map(fn ($u) => $did.':'.$u, array_keys($state[$did] ?? [])), $ids)),
            'pm-reorder' => array_keys($reorder),
        ]);

        // Pointers, map entries and who has not read what, in message order.
        $mapped = $markers = $unread = $first = $last = $threads = $copies = [];
        foreach ($ready as $i => $d) {
            if (! isset($placed[$i], $newIds[$placed[$i]])) {
                continue;
            }
            $did = $dialogs[$d['key']];
            $mid = $newIds[$placed[$i]];
            $before = $last[$did]['id'] ?? ($prev[$did] ?? 0);
            $first[$did] ??= ['id' => $mid, 'at' => $d['at']];
            $last[$did] = ['id' => $mid, 'at' => $d['at'], 'user' => $users[(string) $d['uid']] ?? null];
            $mapped[$d['id']] = $mid;
            foreach ($d['aliases'] ?? [] as $alias) {
                $copies[(string) $alias] ??= $mid;
            }
            if (($d['thread'] ?? '') !== '') {
                $threads[(string) $d['thread']] ??= $did;
            }
            foreach ($member(array_diff(array_intersect($d['unread'], $d['keepers']), [(string) $d['uid']])) as $u) {
                $k = $did.':'.$u;
                if (! isset($earlier['pm-unread'][$k]) && ! isset($unread[$k])) {
                    $unread[$k] = $before; // read up to the message before their first unread one
                    $markers[$k] = $mid;
                }
            }
        }
        Dst::mentions('dialog_messages', array_combine(array_values($newIds), array_map(fn ($k) => $stored[$k] ?? '', array_keys($newIds))) ?: []);
        $sum['messages'] = count($mapped);
        $ctx->mapPut('pm', $mapped);
        // A copy's id can be another message's id (separate sequences), so they are kept apart.
        $ctx->mapPut('pm-copy', $copies);
        $ctx->mapPut('pm-conv', array_diff_key($threads, $ctx->mapGet('pm-conv', array_keys($threads))));
        $ctx->mapPut('pm-unread', $markers);
        $ctx->mapPut('pm-reorder', array_diff_key($reorder, $earlier['pm-reorder']));

        $dialogRows = $readRows = [];
        foreach ($last as $did => $l) {
            $dialogRows[$did] = ['last_message_id' => $l['id'], 'last_message_at' => $l['at'], 'last_message_user_id' => $l['user'], 'updated_at' => $l['at']]
                + (isset($isNew[$did]) ? ['first_message_id' => $first[$did]['id'], 'created_at' => $first[$did]['at']] : []);

            // Read markers. Somebody who was up to date (or has just joined) is
            // up to date again, unless the old board says otherwise; somebody
            // with something unread already keeps exactly that.
            foreach ($state[$did] ?? [] as $u => $s) {
                $k = $did.':'.$u;
                if (! $s['id'] || isset($earlier['pm-unread'][$k]) || ($s['read'] !== null && $s['read'] < ($prev[$did] ?? 0))) {
                    continue;
                }
                $readRows[$s['id']] = isset($unread[$k])
                    ? ['last_read_message_id' => max((int) $s['read'], $unread[$k])]
                    : ['last_read_message_id' => $l['id'], 'last_read_at' => $l['at']];
            }
        }
        self::updateMany('dialogs', $dialogRows);
        self::updateMany('dialog_user', $readRows);

        return $sum;
    }

    /**
     * One UPDATE for many rows, each with its own values (a CASE per column).
     *
     * @param  array<int,array<string,mixed>>  $rows  id => [column => value]
     */
    private static function updateMany(string $table, array $rows): void
    {
        $db = Dst::db();
        $grammar = $db->getQueryGrammar();
        foreach (array_chunk($rows, 200, true) as $chunk) {
            $sets = $bindings = [];
            foreach (array_keys(array_merge(...array_values($chunk))) as $col) {
                $case = 'CASE '.$grammar->wrap('id');
                foreach ($chunk as $id => $values) {
                    if (array_key_exists($col, $values)) {
                        $v = $values[$col];
                        $case .= ' WHEN '.(int) $id.' THEN ?';
                        $bindings[] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v;
                    }
                }
                $sets[] = $grammar->wrap($col).' = '.$case.' ELSE '.$grammar->wrap($col).' END';
            }
            $db->update('UPDATE '.$grammar->wrapTable($table).' SET '.implode(', ', $sets)
                .' WHERE '.$grammar->wrap('id').' IN ('.implode(',', array_map('intval', array_keys($chunk))).')', $bindings);
        }
    }

    /**
     * Direct dialogs the members already have on this forum: a pair that has
     * been writing here meanwhile keeps the one conversation Flarum allows them,
     * with the old messages added to it. One query for the batch.
     *
     * @return array<string,int> key => dialog id
     */
    private static function existingDirect(array $ready, array $known, array $users): array
    {
        $want = [];
        foreach ($ready as $d) {
            if ($d['key'][0] !== 'd' || isset($known[$d['key']]) || count($pair = array_filter(array_map(fn ($id) => $users[$id] ?? null, $d['people']))) !== 2) {
                continue;
            }
            sort($pair);
            $want[implode(':', $pair)] = $d['key'];
        }
        if (! $want) {
            return [];
        }
        $byDialog = [];
        $people = array_unique(array_merge(...array_map(fn ($p) => array_map('intval', explode(':', $p)), array_keys($want))));
        foreach (Dst::db()->table('dialog_user')->join('dialogs', 'dialogs.id', '=', 'dialog_user.dialog_id')
            ->where('dialogs.type', 'direct')->whereIn('dialog_user.user_id', $people)
            ->orderBy('dialog_user.dialog_id')->get(['dialog_user.dialog_id', 'dialog_user.user_id']) as $r) {
            $byDialog[$r->dialog_id][] = (int) $r->user_id;
        }
        $out = [];
        foreach ($byDialog as $did => $pair) {
            sort($pair);
            $key = $want[implode(':', $pair)] ?? null;
            if ($key && ! isset($out[$key])) {
                $out[$key] = (int) $did;
            }
        }

        return $out;
    }

    /**
     * Finishing: a dialog that received messages older than ones it already
     * held (a pair who had been writing here before their old messages came
     * across) is renumbered by date, so the conversation reads in order.
     */
    private static function reorder(int $cursor, Ctx $ctx): array
    {
        $db = Dst::db();
        $rows = $db->table('importer_map')->where('run_id', $ctx->runId)->where('kind', 'pm-reorder')
            ->where('id', '>', $cursor)->orderBy('id')->limit(20)->get(['id', 'target_id']);
        foreach ($rows as $r) {
            $cursor = (int) $r->id;
            $did = (int) $r->target_id;
            $ids = $db->table('dialog_messages')->where('dialog_id', $did)->orderBy('created_at')->orderBy('id')->pluck('id')->all();
            if (! $ids) {
                continue;
            }
            // Out of the way first: (dialog_id, number) is unique.
            $db->table('dialog_messages')->where('dialog_id', $did)->update(['number' => $db->raw('number + 1000000000')]);
            $p = $db->getTablePrefix();
            foreach (array_chunk($ids, 500, true) as $chunk) {
                $case = '';
                foreach ($chunk as $i => $id) {
                    $case .= ' WHEN '.(int) $id.' THEN '.($i + 1);
                }
                $db->table('dialog_messages')->whereIn('id', $chunk)->update(['number' => $db->raw("CASE {$p}dialog_messages.id{$case} END")]);
            }
            $first = $db->table('dialog_messages')->where('id', $ids[0])->first(['id', 'created_at']);
            $last = $db->table('dialog_messages')->where('id', end($ids))->first(['id', 'created_at', 'user_id']);
            $db->table('dialogs')->where('id', $did)->update([
                'first_message_id' => $first->id, 'created_at' => $first->created_at,
                'last_message_id' => $last->id, 'last_message_at' => $last->created_at, 'last_message_user_id' => $last->user_id,
            ]);
        }
        $db->table('importer_map')->whereIn('id', $rows->pluck('id'))->delete();

        return ['cursor' => $cursor, 'processed' => 0, 'done' => count($rows) < 20, 'summary' => []];
    }
}
