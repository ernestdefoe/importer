<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Support\Carbon;

/**
 * Post reactions → fof/reactions, for a source with a reactions add-on.
 *
 * An importer describes them with a `reactions()` method returning one set per
 * add-on the old board may run (phpBB has two), each
 *
 *   count  fn (): int                                   for the progress bar
 *   types  fn (ConnectionInterface): array<string, array{code:?string, enabled:bool}>
 *          the add-on's reaction kinds by its own key; `code` is the emoji's
 *          codepoint ("1f44d", "1f469-200d-1f4bb"), null for a picture of the
 *          admin's own that is no emoji
 *   fetch  fn (int $after, int $limit, Ctx): array{cursor:int, rows:int, done:bool, reactions:list<array{0:mixed,1:mixed,2:string,3:?Carbon}>}
 *          [source post id, source user id, type key, when]
 *   slack  int  how far back a sync starts reading (the add-on pages by post, not by reaction)
 *
 * fof/reactions keeps one reaction per member per post and shows each kind by
 * an emoji shortname, so a kind becomes the forum's own reaction for that emoji
 * when there is one, and a new one otherwise. A kind with no emoji (an uploaded
 * picture) has nothing to become, and its reactions are counted, not written.
 * Counts are worked out by fof/reactions when shown: nothing to recount.
 */
class Reactions
{
    public static function enabled(): bool
    {
        try {
            return resolve(\Flarum\Extension\ExtensionManager::class)->isEnabled('fof-reactions')
                && Dst::hasTable('reactions') && Dst::hasTable('post_reactions');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  class-string  $importer
     * @return Phase[]
     */
    public static function phases(string $importer, array $cfg): array
    {
        if (! method_exists($importer, 'reactions')) {
            return [];
        }
        $phases = [];
        foreach (array_keys($importer::reactions($cfg)) as $set) {
            // Resolved by its phase only: the phase list is rebuilt on every step.
            $def = fn (): array => $importer::reactions($cfg)[$set];
            $phases[] = new Phase(
                'reactions-'.$set,
                'reactions',
                fn () => self::enabled() ? (int) ($def()['count'])() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($def, $set) {
                    if (! self::enabled()) {
                        $why = (int) ($def()['count'])() > 0 ? ['reactions_unavailable' => 1] : [];

                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => $why];
                    }
                    $d = $def();
                    $res = ($d['fetch'])((int) $cursor, $limit, $ctx);
                    $summary = Dst::db()->transaction(function () use ($d, $res, $set, $ctx) {
                        $summary = $res['reactions'] ? self::write($set, ($d['types'])($ctx->src()), $res['reactions'], $ctx) : [];
                        // Where a sync picks up: one row per set, replaced as the cursor moves.
                        Dst::db()->table('importer_map')->where('run_id', $ctx->runId)->where('kind', 'reaction-'.$set)->delete();
                        $ctx->mapPut('reaction-'.$set, [(string) $res['cursor'] => 0]);

                        return $summary;
                    });

                    return ['cursor' => (int) $res['cursor'], 'processed' => (int) $res['rows'], 'done' => (bool) $res['done'], 'summary' => $summary];
                },
            );
        }

        return $phases;
    }

    /**
     * Where each reactions phase of a sync starts: past what the base run read.
     *
     * @param  class-string  $importer
     * @return array<string,int>
     */
    public static function syncFrom(string $importer, array $cfg, int $runId): array
    {
        $sets = method_exists($importer, 'reactions') ? $importer::reactions($cfg) : [];
        if (! $sets) {
            return [];
        }
        $read = Dst::db()->table('importer_map')->where('run_id', $runId)
            ->whereIn('kind', array_map(fn ($s) => 'reaction-'.$s, array_keys($sets)))->pluck('source_id', 'kind')->all();
        $from = [];
        foreach ($sets as $set => $def) {
            $from['reactions-'.$set] = max(0, (int) ($read['reaction-'.$set] ?? 0) - (int) ($def['slack'] ?? 0));
        }

        return $from;
    }

    /**
     * One batch. 🚨 Runs in the step's transaction with its map rows, so a
     * retried step finds what it wrote and writes nothing twice.
     *
     * @param  array<string, array{code:?string, enabled:bool}>  $types
     * @return array<string,int>
     */
    public static function write(string $set, array $types, array $rows, Ctx $ctx): array
    {
        $kinds = self::kinds($set, $types, array_unique(array_column($rows, 2)), $ctx);
        $maps = $ctx->mapMany(['post' => array_column($rows, 0), 'user' => array_column($rows, 1)]);

        // The newest reaction of each member on each post: fof/reactions keeps one.
        $want = [];
        $skipped = $pictures = 0;
        foreach ($rows as [$post, $uid, $type, $at]) {
            $postId = $maps['post'][(string) $post] ?? null;
            $userId = $maps['user'][(string) $uid] ?? null;
            if (! isset($kinds[$type])) {
                $pictures++;

                continue;
            }
            if (! $postId || ! $userId) {
                $skipped++;

                continue;
            }
            $key = $postId.'|'.$userId;
            if (! isset($want[$key]) || $at && $want[$key][3] && $at->gt($want[$key][3])) {
                $skipped += isset($want[$key]) ? 1 : 0;
                $want[$key] = [(int) $postId, (int) $userId, $kinds[$type], $at];
            } else {
                $skipped++;
            }
        }
        if (! $want) {
            return array_filter(['reactions_skipped' => $skipped, 'reactions_pictures' => $pictures]);
        }

        $db = Dst::db();
        $have = [];
        foreach ($db->table('post_reactions')->whereIn('post_id', array_unique(array_column($want, 0)))->get(['id', 'post_id', 'user_id', 'reaction_id']) as $r) {
            $have[$r->post_id.'|'.$r->user_id] = $r;
        }
        $stamps = Dst::hasColumn('post_reactions', 'created_at');
        $now = Carbon::now();
        $insert = [];
        $made = 0;
        foreach ($want as $key => [$postId, $userId, $reactionId, $at]) {
            if (isset($have[$key])) {
                // Already here: written by an earlier run, or the member reacted on this forum since.
                continue;
            }
            $insert[] = ['post_id' => $postId, 'user_id' => $userId, 'reaction_id' => $reactionId]
                + ($stamps ? ['created_at' => $at ?? $now, 'updated_at' => $at ?? $now] : []);
            $made++;
        }
        foreach (array_chunk($insert, 500) as $chunk) {
            $db->table('post_reactions')->insert($chunk);
        }

        return array_filter(['reactions' => $made, 'reactions_skipped' => $skipped, 'reactions_pictures' => $pictures]);
    }

    /**
     * The forum's reaction for each source kind in the batch, made where the
     * forum has none for that emoji. Mapped, so every batch agrees.
     *
     * @param  array<string, array{code:?string, enabled:bool}>  $types
     * @return array<string,int>  source kind => reactions.id (kinds with no emoji left out)
     */
    private static function kinds(string $set, array $types, array $used, Ctx $ctx): array
    {
        $src = fn (string $type) => mb_substr($set.':'.$type, 0, 80);
        $mapped = $ctx->mapGet('reaction-type', array_map($src, $used));
        $out = $new = [];
        $forum = null;
        foreach ($used as $type) {
            if (isset($mapped[$src($type)])) {
                $out[$type] = (int) $mapped[$src($type)];

                continue;
            }
            // A kind since deleted on the old board: still read off its file name, if that is what it is.
            $code = $types[$type]['code'] ?? (preg_match('/\.(?:svg|png|gif|webp)$/i', (string) $type) ? self::code((string) $type) : null);
            $name = $code !== null ? self::shortname($code) : null;
            if ($name === null) {
                continue; // a picture, or an emoji fof/reactions cannot show
            }
            $forum ??= self::forumReactions();
            $id = $forum[self::normal($code)] ?? null;
            if (! $id) {
                $row = ['identifier' => $name, 'type' => 'emoji'];
                if (Dst::hasColumn('reactions', 'enabled')) {
                    $row['enabled'] = (bool) ($types[$type]['enabled'] ?? false);
                }
                $id = (int) Dst::db()->table('reactions')->insertGetId($row);
                $forum[self::normal($code)] = $id;
            }
            $out[$type] = (int) $id;
            $new[$src($type)] = (int) $id;
        }
        $ctx->mapPut('reaction-type', $new);

        return $out;
    }

    /** @return array<string,int>  codepoint => reactions.id, for the forum's emoji reactions */
    private static function forumReactions(): array
    {
        $byName = array_flip(self::map());
        $out = [];
        foreach (Dst::db()->table('reactions')->where('type', 'emoji')->orderBy('id')->get(['id', 'identifier']) as $r) {
            $code = $byName[$r->identifier] ?? self::code((string) $r->identifier);
            if ($code !== null) {
                $out[self::normal($code)] ??= (int) $r->id;
            }
        }

        return $out;
    }

    /**
     * The codepoint of an emoji, given as the character itself or as a Twemoji
     * file name ("1f44d.svg", "2764-fe0f.png"); null for anything else.
     */
    public static function code(string $emojiOrFile): ?string
    {
        $s = trim($emojiOrFile);
        if (preg_match('/^([0-9a-f]{2,6}(?:-[0-9a-f]{2,6})*)(?:\.(?:svg|png|gif|webp))?$/i', $s, $m)) {
            return strtolower($m[1]);
        }
        if ($s === '' || ! preg_match('/[^\x00-\x7F]/', $s) || ! mb_check_encoding($s, 'UTF-8')) {
            return null;
        }

        return implode('-', array_map(fn ($c) => dechex(mb_ord($c, 'UTF-8')), mb_str_split($s, 1, 'UTF-8')));
    }

    /** The shortname fof/reactions draws an emoji by, without a skin tone if need be; null when it has none. */
    public static function shortname(string $code): ?string
    {
        $map = self::map();
        $code = self::normal($code);

        return $map[$code] ?? $map[self::normal((string) preg_replace('/-?1f3f[b-f]/', '', $code))] ?? null;
    }

    /** Lower case, without the FE0F that only asks for the picture form. */
    private static function normal(string $code): string
    {
        return implode('-', array_filter(explode('-', strtolower($code)), fn ($p) => $p !== '' && $p !== 'fe0f'));
    }

    /**
     * Codepoint => shortname, as fof/reactions' own map has it (simple-emoji-map
     * built from emojibase-data 17: emojibase-legacy, then emojibase).
     *
     * @return array<string,string>
     */
    private static function map(): array
    {
        static $map = null;

        return $map ??= json_decode((string) file_get_contents(__DIR__.'/../../resources/data/emoji-shortnames.json'), true) ?: [];
    }
}
