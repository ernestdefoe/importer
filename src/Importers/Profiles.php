<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Support\Carbon;

/**
 * Profile photos and birthdays, for every source that keeps them.
 *
 * Each importer describes its members' profiles with a `profiles()` method
 * returning `['count' => fn (): int, 'fetch' => fn (int $after, int $limit,
 * Ctx $ctx): array]`, as `polls()` does. The fetch walks the members in id
 * order and hands back, per member:
 *
 *   uid       the source user id
 *   avatar    ?array  where the uploaded or linked picture is ({@see Files::bytes()})
 *   birthday  ?array{0:?int,1:int,2:int}  [year or null, month, day]
 *
 * A picture is copied, never linked ({@see Dst::avatar()}), and only onto a
 * member with none: a resumed step, a sync, or a member who already chose a
 * picture here, keeps the one they have.
 *
 * A birthday goes where datlechin/flarum-birthdays keeps it (`users.birthday`,
 * a date). One with no year is stored in 1904, a leap year so 29 February
 * survives, with the age hidden (`show_age`). Without that extension
 * enabled the birthdays are counted in the summary, never guessed into
 * another column.
 */
final class Profiles
{
    /** A picture is fetched per member, so a step takes fewer of them than the usual batch. */
    private const BATCH = 50;

    /**
     * @param  class-string  $importer
     * @return Phase[]
     */
    public static function phases(string $importer, array $cfg): array
    {
        if (! method_exists($importer, 'profiles')) {
            return [];
        }
        $def = fn (): array => $importer::profiles($cfg);

        return [new Phase('profiles', 'profiles', fn () => (int) ($def()['count'])(), function ($cursor, $limit, Ctx $ctx) use ($def) {
            $res = ($def()['fetch'])((int) $cursor, min($limit, self::BATCH), $ctx);
            if ($res['done'] && ! $ctx->mapGet('profiles', ['done'])) {
                $ctx->mapPut('profiles', ['done' => 0]); // a sync then takes only new members ({@see \ErnestDefoe\Importer\Runner::sync()})
            }

            return ['cursor' => $res['cursor'], 'processed' => (int) $res['rows'], 'done' => (bool) $res['done'], 'summary' => self::write($res['profiles'], $ctx)];
        })];
    }

    /** @return array<string,int> */
    public static function write(array $profiles, Ctx $ctx): array
    {
        $profiles = array_filter($profiles, fn ($p) => ! empty($p['avatar']) || ! empty($p['birthday']));
        if (! $profiles) {
            return [];
        }
        $users = $ctx->mapGet('user', array_column($profiles, 'uid'));
        $db = Dst::db();
        $birthdays = self::birthdays();
        $have = $users ? $db->table('users')->whereIn('id', array_values($users))->get(['id', 'avatar_url', ...($birthdays ? ['birthday'] : [])])->keyBy('id') : collect();
        $hideAge = Dst::hasColumn('users', 'show_age');
        $out = $dates = $noYear = [];
        $add = function (string $k) use (&$out) {
            $out[$k] = ($out[$k] ?? 0) + 1;
        };
        foreach ($profiles as $p) {
            $user = $have->get($users[(string) $p['uid']] ?? 0);
            if (! $user) {
                continue;
            }
            if (! empty($p['avatar']) && ($user->avatar_url ?? '') === '') {
                $bytes = Files::bytes($p['avatar'], $ctx->cfg, 3_000_000, 15);
                $add($bytes !== null && Dst::avatarBytes((int) $user->id, $bytes) ? 'avatars' : 'avatars_missing');
            }
            if (! empty($p['birthday'])) {
                [$y, $m, $d] = $p['birthday'] + [null, 0, 0];
                // 1904 is how a year-less birthday is kept, here and on Discourse.
                $y = $y && $y > 1900 && $y !== 1904 && $y <= (int) date('Y') ? (int) $y : null;
                if (! checkdate((int) $m, (int) $d, $y ?? 1904)) {
                    continue; // "00-00", 31 June: the old board had no real date
                }
                if (! $birthdays) {
                    $add('birthdays_skipped');
                } elseif (empty($user->birthday)) {
                    $dates[(int) $user->id] = Carbon::create($y ?? 1904, (int) $m, (int) $d)->toDateString();
                    if ($y === null) {
                        $noYear[] = (int) $user->id;
                    }
                    $add('birthdays');
                }
            }
        }
        if ($dates) {
            // One statement for the batch. Every value is an integer id or a date Carbon wrote.
            $case = 'CASE '.$db->getQueryGrammar()->wrap('id');
            $type = $db->getDriverName() === 'pgsql' ? 'DATE ' : ''; // PostgreSQL will not put text in a date column
            foreach ($dates as $id => $date) {
                $case .= ' WHEN '.$id.' THEN '.$type."'".$date."'";
            }
            $db->table('users')->whereIn('id', array_keys($dates))->update(['birthday' => $db->raw($case.' END')]);
            if ($noYear && $hideAge) {
                $db->table('users')->whereIn('id', $noYear)->update(['show_age' => false]);
            }
        }

        return $out;
    }

    /** Birthdays (datlechin/flarum-birthdays) is enabled: a column left by one switched off is not used. */
    private static function birthdays(): bool
    {
        try {
            return resolve(\Flarum\Extension\ExtensionManager::class)->isEnabled('datlechin-birthdays') && Dst::hasColumn('users', 'birthday');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * A page of members for a `profiles()` fetch: the rows past $after in id
     * order, each turned into a profile by $map (null: nothing to bring).
     */
    public static function page(\Illuminate\Database\Query\Builder $q, string $idCol, int $after, int $limit, \Closure $map): array
    {
        $rows = $q->where($idCol, '>', $after)->orderBy($idCol)->limit($limit)->get();
        $prop = str_contains($idCol, '.') ? substr((string) strrchr($idCol, '.'), 1) : $idCol;
        $out = [];
        foreach ($rows as $r) {
            $after = (int) $r->$prop;
            if ($p = $map($r)) {
                $out[] = $p + ['uid' => $r->$prop];
            }
        }

        return ['cursor' => $after, 'rows' => count($rows), 'done' => count($rows) < $limit, 'profiles' => $out];
    }

    /** [year|null, month, day] from a date string in the given order ('ymd', 'dmy', 'mdy'), with any separator. */
    public static function date(?string $s, string $order = 'ymd'): ?array
    {
        $parts = preg_split('/[^0-9]+/', trim((string) $s), -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) < 2) {
            return null;
        }
        $at = array_flip(str_split($order));
        if (count($parts) === 2) { // no year at all: "5-3"
            unset($at['y']);
            $at = array_flip(array_values(array_diff(str_split($order), ['y'])));
        }
        $get = fn ($k) => isset($at[$k], $parts[$at[$k]]) ? (int) $parts[$at[$k]] : null;

        return [$get('y') ?: null, (int) $get('m'), (int) $get('d')];
    }

    /** Gravatar's picture for an address: what the old board showed when a member chose it. */
    public static function gravatar(string $email): array
    {
        return ['url' => 'https://secure.gravatar.com/avatar/'.md5(mb_strtolower(trim($email))).'?s=200&d=404'];
    }
}
