<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * The old board's user groups → Flarum groups, for every source that has them.
 *
 * Each importer describes its groups with a `groups()` method returning
 *
 *   'groups'  fn (Ctx $ctx): list<array{id:string, name:string, color?:?string, role?:?string}>
 *   'members' fn (mixed $after, int $limit, Ctx $ctx): array{cursor:mixed, rows:int, done:bool, pairs:list<array{0:mixed,1:string}>}
 *   'needs'   list<string>  the source tables both read (without them: nothing to do)
 *
 * A group's role says what it is on the old board:
 *
 *   admin   its administrators          → Flarum's Admin group
 *   mod     its (global) moderators     → Flarum's Mod group
 *   member  everybody who registered    → Flarum's Members (implicit: no row)
 *   guest   people not signed in        → Flarum's Guests (implicit: no row)
 *   banned / pending / bots / automatic / ranks / permissions
 *           not a group anybody joins here: a ban is a suspension and an
 *           unconfirmed address stays unconfirmed (both come across with the
 *           member), bots are not imported, automatic and post-count groups
 *           are worked out by the old board itself, and a permission set is
 *           not a group of people — counted in the summary, by reason
 *   null    the board's own group       → a Flarum group of that name (one
 *           already here with the same name is used instead), with its colour
 *
 * 🚨 Nothing is dropped silently: every group is mapped, created or counted.
 * A pair may name '@admin' or '@mod' instead of a group, for a board that
 * flags staff on the member rather than through a group. A created group has
 * no permissions until an admin gives it some, and an account nobody has
 * confirmed is never made an Admin or Mod ({@see Dst::assignCoreGroup()}).
 */
final class Groups
{
    public const CORE = ['admin' => 1, 'guest' => 2, 'member' => 3, 'mod' => 4];

    /**
     * @param  class-string  $importer
     * @return Phase[]
     */
    public static function phases(string $importer, array $cfg): array
    {
        if (! method_exists($importer, 'groups')) {
            return [];
        }
        $def = fn (): array => $importer::groups($cfg);
        // A board (or a dump of one) without its group tables has no groups to bring.
        $has = function (Ctx $ctx) use ($def): bool {
            $sb = ($needs = $def()['needs'] ?? []) ? $ctx->src()->getSchemaBuilder() : null;
            foreach ($needs as $t) {
                if (! $sb->hasTable($t)) {
                    return false;
                }
            }

            return true;
        };
        $none = ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];

        return [
            new Phase('groups', 'groups', fn () => 0, fn ($cursor, $limit, Ctx $ctx) => ! $has($ctx) ? $none : [
                'cursor' => null, 'processed' => 0, 'done' => true,
                'summary' => Dst::db()->transaction(fn () => self::write(($def()['groups'])($ctx), $ctx)),
            ]),
            new Phase('group-members', 'group_members', fn () => 0, function ($cursor, $limit, Ctx $ctx) use ($def, $has, $none) {
                if (! $has($ctx)) {
                    return $none;
                }
                $res = ($def()['members'])($cursor, $limit, $ctx);
                $n = Dst::db()->transaction(fn () => self::join($res['pairs'], $ctx));

                return ['cursor' => $res['cursor'], 'processed' => 0, 'done' => (bool) $res['done'], 'summary' => $n ? ['group_members' => $n] : []];
            }),
        ];
    }

    /** @return array<string,int> */
    private static function write(array $groups, Ctx $ctx): array
    {
        $db = Dst::db();
        $known = $ctx->mapGet('group', array_column($groups, 'id'));
        $byName = [];
        foreach ($db->table('groups')->get(['id', 'name_singular', 'name_plural']) as $g) {
            $byName[mb_strtolower(trim((string) $g->name_plural))] ??= (int) $g->id;
            $byName[mb_strtolower(trim((string) $g->name_singular))] ??= (int) $g->id;
        }
        $map = [];
        $out = [];
        foreach ($groups as $g) {
            $id = (string) $g['id'];
            $role = $g['role'] ?? null;
            if (isset($known[$id])) {
                continue;
            }
            if ($role !== null && ! isset(self::CORE[$role])) {
                if ($ctx->sync) {
                    continue; // counted by the import already
                }
                $out['groups_skipped'] = ($out['groups_skipped'] ?? 0) + 1;
                $out['groups_skipped_'.$role] = ($out['groups_skipped_'.$role] ?? 0) + 1;

                continue;
            }
            $name = trim(html_entity_decode(strip_tags((string) $g['name']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($role === null && $name === '') {
                $name = 'Group '.$id;
            }
            if ($role !== null) {
                $map[$id] = self::CORE[$role];
            } elseif ($existing = $byName[mb_strtolower($name)] ?? null) {
                $map[$id] = $existing;
            } else {
                $name = mb_substr($name, 0, 100);
                $color = ltrim(trim((string) ($g['color'] ?? '')), '#');
                $color = preg_match('/^[0-9a-f]{3}$/i', $color) ? preg_replace('/(.)/', '$1$1', $color) : $color;
                $map[$id] = $byName[mb_strtolower($name)] = (int) $db->table('groups')->insertGetId([
                    'name_singular' => $name,
                    'name_plural' => $name,
                    'color' => preg_match('/^[0-9a-f]{6}$/i', $color) ? '#'.strtolower($color) : null,
                    'is_hidden' => 0,
                ]);
                $out['groups_created'] = ($out['groups_created'] ?? 0) + 1;

                continue;
            }
            $out['groups_mapped'] = ($out['groups_mapped'] ?? 0) + 1;
        }
        $ctx->mapPut('group', $map);

        return $out;
    }

    /** @param  list<array{0:mixed,1:string}>  $pairs  [source user id, source group id or '@admin' / '@mod'] */
    private static function join(array $pairs, Ctx $ctx): int
    {
        if (! $pairs) {
            return 0;
        }
        $maps = $ctx->mapMany(['user' => array_column($pairs, 0), 'group' => array_column($pairs, 1)]);
        $maps['group'] += ['@admin' => 1, '@mod' => 4];
        $rows = [];
        foreach ($pairs as [$uid, $gid]) {
            $user = $maps['user'][(string) $uid] ?? null;
            $group = $maps['group'][(string) $gid] ?? null;
            if ($user && $group && ! in_array($group, [2, 3], true)) {
                $rows[$user.'-'.$group] = ['user_id' => $user, 'group_id' => $group];
            }
        }
        if (! $rows) {
            return 0;
        }
        $db = Dst::db();
        // Admin and Mod only for a confirmed address, as Dst::assignCoreGroup() rules.
        $staff = array_filter($rows, fn ($r) => in_array($r['group_id'], [1, 4], true));
        if ($staff) {
            $confirmed = array_flip($db->table('users')->whereIn('id', array_unique(array_column($staff, 'user_id')))
                ->where('is_email_confirmed', true)->pluck('id')->map(fn ($id) => (int) $id)->all());
            $rows = array_filter($rows, fn ($r) => ! in_array($r['group_id'], [1, 4], true) || isset($confirmed[$r['user_id']]));
        }
        $have = [];
        foreach ($db->table('group_user')->whereIn('user_id', array_unique(array_column($rows, 'user_id')))->get(['user_id', 'group_id']) as $r) {
            $have[$r->user_id.'-'.$r->group_id] = true;
        }
        $rows = array_values(array_diff_key($rows, $have));
        foreach (array_chunk($rows, 500) as $chunk) {
            $db->table('group_user')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * The members of each group, walked by source user id: a page of members
     * and every group each is in. For the boards that keep a primary group on
     * the member and the rest in a list column, or a pivot table.
     *
     * @param  \Closure  $query  fn (Ctx): Builder over the members, with the id column
     * @param  \Closure  $groupsOf  fn (object $row): list<string> the member's groups
     */
    public static function byMember(Ctx $ctx, mixed $after, int $limit, \Closure $query, string $idCol, \Closure $groupsOf): array
    {
        $rows = $query($ctx)->where($idCol, '>', (int) $after)->orderBy($idCol)->limit($limit)->get();
        $pairs = [];
        foreach ($rows as $r) {
            $after = $r->$idCol;
            foreach (array_unique(array_filter(array_map('strval', $groupsOf($r)), fn ($g) => $g !== '' && $g !== '0')) as $g) {
                $pairs[] = [$r->$idCol, $g];
            }
        }

        return ['cursor' => (int) $after, 'rows' => count($rows), 'done' => count($rows) < $limit, 'pairs' => $pairs];
    }

    /**
     * The members of each group, for a board that keeps memberships in a
     * table of their own: a page of members, then their rows in that table.
     *
     * @param  \Closure  $pivot  fn (list<int> $userIds): iterable<array{0:mixed,1:mixed}> [user, group]
     */
    public static function pivot(\Illuminate\Database\Query\Builder $users, string $idCol, mixed $after, int $limit, \Closure $pivot): array
    {
        $ids = $users->where($idCol, '>', (int) $after)->orderBy($idCol)->limit($limit)->pluck($idCol)->all();
        $pairs = [];
        foreach ($ids ? $pivot($ids) : [] as [$u, $g]) {
            $pairs[] = [$u, (string) $g];
        }

        return ['cursor' => $ids ? (int) end($ids) : (int) $after, 'rows' => count($ids), 'done' => count($ids) < $limit, 'pairs' => $pairs];
    }

    /** Ids out of a "3,7,12" list column. */
    public static function ids(?string $list): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $list)), fn ($v) => ctype_digit($v)));
    }
}
