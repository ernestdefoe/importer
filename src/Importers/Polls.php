<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Polls → fof/polls, for every source that keeps them.
 *
 * Each importer describes its old board's polls with a `polls()` method
 * returning `['count' => fn (): int, 'fetch' => fn (int $after, int $limit,
 * Ctx $ctx): array]`, exactly as `privateMessages()` does. The fetch walks the
 * source in id order and hands back one plain row per poll (see {@see write()}
 * for the shape); everything Flarum-side is here:
 *
 *  - a poll lands on the first post of the discussion its topic became, as a
 *    poll made on the forum does (or on the very post it was made in, for a
 *    source that puts polls in posts);
 *  - options keep their order, and every vote is a `poll_votes` row, because
 *    fof/polls recounts an option from its rows the moment anybody votes;
 *  - a vote by somebody who was not imported (a deleted account, a guest)
 *    keeps its place with no voter, which is how fof/polls itself keeps the
 *    vote of a member who is deleted. Where the old board kept only a total
 *    (SMF guests, Web Wiz), the votes it cannot name are written the same
 *    way, so every result reads as it did on the old board;
 *  - a poll whose topic was not imported is skipped, and nothing is written
 *    twice: polls are mapped like everything else.
 */
class Polls
{
    public static function enabled(): bool
    {
        try {
            $sb = Dst::db()->getSchemaBuilder();

            return resolve(\Flarum\Extension\ExtensionManager::class)->isEnabled('fof-polls')
                && $sb->hasTable('polls') && $sb->hasTable('poll_options') && $sb->hasTable('poll_votes');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param  class-string  $importer */
    public static function supported(string $importer): bool
    {
        return method_exists($importer, 'polls');
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
        // Resolved by the polls phase only: the phase list is rebuilt on every
        // step of an import, and the other phases need neither.
        $def = fn (): array => $importer::polls($cfg);

        return [
            new Phase(
                'polls',
                'polls',
                fn () => self::enabled() ? (int) ($def()['count'])() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($def) {
                    if (! self::enabled()) {
                        // Said once, in the summary, and only to a board that had polls.
                        $why = (int) ($def()['count'])() > 0 ? ['polls_unavailable' => 1] : [];

                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => $why];
                    }
                    $res = ($def()['fetch'])((int) $cursor, $limit, $ctx);
                    $summary = Dst::db()->transaction(fn () => self::write($res['polls'], $ctx));

                    return ['cursor' => (int) $res['cursor'], 'processed' => (int) $res['rows'], 'done' => (bool) $res['done'], 'summary' => $summary];
                },
            ),
        ];
    }

    /**
     * Write one batch of polls. A poll, as the importers hand it over:
     *
     *   id        string  the source poll id (unique within the source)
     *   topic     ?string the source topic it belongs to, or
     *   post      ?string the source post it was made in
     *   question  string  plain text
     *   uid       mixed   the source id of who made it, if known
     *   at        Carbon  when it was made
     *   ends      ?Carbon when voting closes (or closed)
     *   max       int     choices a member may pick: 1, n, or 0 for any number
     *   public    bool    voters are shown
     *   change    bool    a vote can be changed
     *   hide      bool    results are hidden until it ends
     *   options   array<string, array{text:string, count:?int}>  in order, by the source's option key
     *   votes     list<array{0:mixed, 1:string|int, 2:?Carbon}>  [source user id, option key, when]
     *
     * 🚨 The batch runs in one transaction with its id map, so a step that
     * dies half way writes nothing and the retry cannot duplicate a poll.
     *
     * @return array<string,int>
     */
    public static function write(array $polls, Ctx $ctx): array
    {
        $polls = array_diff_key(array_column($polls, null, 'id'), $ctx->mapGet('poll', array_column($polls, 'id')));
        if (! $polls) {
            return [];
        }
        $uids = [];
        foreach ($polls as $p) {
            $uids[] = $p['uid'] ?? null;
            foreach ($p['votes'] as $v) {
                $uids[] = $v[0];
            }
        }
        $maps = $ctx->mapMany([
            'topic' => array_filter(array_column($polls, 'topic'), fn ($v) => $v !== null),
            'post' => array_filter(array_column($polls, 'post'), fn ($v) => $v !== null),
            'user' => $uids,
        ]);
        $db = Dst::db();
        $firstPosts = $maps['topic']
            ? $db->table('discussions')->whereIn('id', array_values(array_unique($maps['topic'])))->pluck('first_post_id', 'id')->all()
            : [];
        $published = Dst::hasColumn('polls', 'published_at');
        $now = Carbon::now();

        $made = $skipped = 0;
        $ids = $options = $votes = [];
        foreach ($polls as $key => $p) {
            $postId = isset($p['post']) ? ($maps['post'][(string) $p['post']] ?? null)
                : ($firstPosts[$maps['topic'][(string) ($p['topic'] ?? '')] ?? 0] ?? null);
            $opts = array_filter($p['options'], fn ($o) => self::line($o['text']) !== '');
            if (! $postId || count($opts) < 2) {
                $skipped++;

                continue;
            }

            // Every vote as a row; the totals the old board kept but cannot name, without a voter.
            $rows = $seen = [];
            foreach ($p['votes'] as [$uid, $opt, $at]) {
                $user = $maps['user'][(string) $uid] ?? null;
                if (! isset($opts[$opt]) || $user && isset($seen[$user.'|'.$opt])) {
                    continue;
                }
                if ($user) {
                    $seen[$user.'|'.$opt] = true;
                }
                $rows[$opt][] = [$user, $at ?? $p['at']];
            }
            $counts = [];
            foreach ($opts as $opt => $o) {
                $rows[$opt] ??= [];
                for ($n = count($rows[$opt]), $want = (int) ($o['count'] ?? 0); $n < $want; $n++) {
                    $rows[$opt][] = [null, $p['at']];
                }
                $counts[$opt] = count($rows[$opt]);
            }

            $max = max(0, (int) $p['max']);
            $multiple = $max !== 1;
            $row = [
                'question' => Str::limit(self::line($p['question']) ?: 'Poll', 250, ''),
                'post_id' => (int) $postId,
                'user_id' => $maps['user'][(string) ($p['uid'] ?? '')] ?? null,
                'end_date' => $p['ends'] ?? null,
                'vote_count' => array_sum($counts),
                'settings' => json_encode([
                    'public_poll' => (bool) $p['public'],
                    'allow_multiple_votes' => $multiple,
                    'max_votes' => $multiple && $max < count($opts) ? $max : 0,
                    // fof/polls hides results only until a poll's end date.
                    'hide_votes' => ! empty($p['hide']) && ! empty($p['ends']),
                    'allow_change_vote' => (bool) $p['change'],
                ]),
                'created_at' => $p['at'],
                'updated_at' => $now,
            ];
            if ($published) {
                $row['published_at'] = $p['at'];
            }
            $pollId = (int) $db->table('polls')->insertGetId($row);
            $ids[$key] = $pollId;
            foreach ($opts as $opt => $o) {
                $options[] = ['poll_id' => $pollId, 'answer' => Str::limit(self::line($o['text']), 250, ''), 'vote_count' => $counts[$opt], 'created_at' => $p['at'], 'updated_at' => $now];
                $votes[$pollId][] = $rows[$opt];
            }
            $made++;
        }

        // Options in one go: ids come back in the order they were inserted, poll by poll.
        $n = 0;
        if ($options) {
            foreach (array_chunk($options, 500) as $chunk) {
                $db->table('poll_options')->insert($chunk);
            }
            $optionIds = [];
            foreach (array_chunk(array_keys($votes), 500) as $chunk) {
                foreach ($db->table('poll_options')->whereIn('poll_id', $chunk)->orderBy('id')->get(['id', 'poll_id']) as $o) {
                    $optionIds[(int) $o->poll_id][] = (int) $o->id;
                }
            }
            $insert = [];
            foreach ($votes as $pollId => $byOption) {
                foreach ($byOption as $i => $voters) {
                    foreach ($voters as [$user, $at]) {
                        $insert[] = ['poll_id' => $pollId, 'option_id' => $optionIds[$pollId][$i], 'user_id' => $user, 'created_at' => $at, 'updated_at' => $at];
                        if (count($insert) === 500) {
                            $db->table('poll_votes')->insert($insert);
                            $n += 500;
                            $insert = [];
                        }
                    }
                }
            }
            if ($insert) {
                $db->table('poll_votes')->insert($insert);
                $n += count($insert);
            }
        }
        $ctx->mapPut('poll', $ids);

        return array_filter(['polls' => $made, 'poll_votes' => $n, 'polls_skipped' => $skipped]);
    }

    /**
     * One page of a source's polls, by id, less the ones already imported (a
     * sync, or a retried step) so their options and votes are never read.
     *
     * @param  string  $id  the id column ("poll_id", or "poll.nodeid" in a join)
     * @return array{0:\Illuminate\Support\Collection,1:int,2:bool,3:int}  [new rows, cursor, done, rows read]
     */
    public static function page(\Illuminate\Database\Query\Builder $q, string $id, int $after, int $limit, Ctx $ctx): array
    {
        $rows = $q->where($id, '>', $after)->orderBy($id)->limit($limit)->get();
        $prop = str_contains($id, '.') ? substr($id, strrpos($id, '.') + 1) : $id;
        $known = $ctx->mapGet('poll', $rows->pluck($prop)->all());

        return [
            $rows->reject(fn ($r) => isset($known[(string) $r->$prop]))->values(),
            $rows->isEmpty() ? $after : (int) $rows->last()->$prop,
            count($rows) < $limit,
            count($rows),
        ];
    }

    /** A source timestamp, or null where the board keeps 0 for "never". */
    public static function at(mixed $v): ?Carbon
    {
        return $v === null || $v === '' || is_numeric($v) && (int) $v <= 0 ? null : Src::ts($v);
    }

    /** A question or answer kept as HTML (or BBCode turned into it), as plain text. */
    public static function plain(?string $html): string
    {
        return html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Plain text on one line. */
    private static function line(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    /**
     * "When voting closed" for a poll the old board closed by hand rather than
     * by date: its last vote, else when it was made — never in the future.
     */
    public static function closedAt(?Carbon ...$candidates): Carbon
    {
        foreach ($candidates as $c) {
            if ($c && $c->isPast()) {
                return $c;
            }
        }

        return Carbon::now();
    }
}
