<?php

/*
 * This file is part of ernestdefoe/importer.
 */

namespace ErnestDefoe\Importer\Redirects;

use ErnestDefoe\Importer\Importers\Dst;

/**
 * An address from the old forum, turned into the one that replaced it.
 *
 * 🚨 **Everything needed for this was already in the database.** The importer
 * writes `importer_map` — `kind`, `source_id`, `target_id` — so that a run
 * spread over hundreds of short requests can remember what it has already
 * created. The same three columns are exactly what a redirect needs, and they
 * survive a completed run: only an explicit reset throws them away.
 *
 * Without this, a migration moves every discussion and breaks every link that
 * ever pointed at one. The posts arrive; the audience does not.
 */
final class Resolver
{
    /**
     * Where an old address should send somebody, or null when it means nothing
     * here.
     *
     * 🚨 `$target` is a path with no host. Whoever calls this builds the
     * absolute URL from Flarum's own base — taking a host from the request
     * would let somebody choose where this site redirects to.
     */
    public function resolve(int $runId, string $source, string $pathAndQuery): ?string
    {
        foreach (Patterns::for($source) as $rule) {
            if (preg_match($rule['pattern'], $pathAndQuery, $m) !== 1) {
                continue;
            }

            $target = $this->lookup($runId, $rule['kind'], $m[1]);

            if ($target !== null) {
                return $target;
            }

            /*
             * 🚨 Matched the shape but not the id, and the loop CONTINUES.
             *
             * A vBulletin 4 site serves both `/threads/123-slug` and
             * `showthread.php?t=123`, and an address can satisfy one pattern
             * while the id belongs to another kind entirely. Stopping at the
             * first shape match would answer "no" for a URL a later pattern
             * would have resolved.
             */
        }

        return null;
    }

    /**
     * The Flarum path for one old id.
     *
     * @param string $kind topic | tag | user, as the importer wrote it
     */
    private function lookup(int $runId, string $kind, string $sourceId): ?string
    {
        $row = Dst::db()->table('importer_map')
            ->where('run_id', $runId)
            ->where('kind', $kind)
            ->where('source_id', (string) $sourceId)
            ->first();

        if (! $row) {
            return null;
        }

        $id = (int) $row->target_id;

        if ($id < 1) {
            return null;
        }

        return match ($kind) {
            /*
             * 🚨 `/d/123` and not `/d/123-slug`. Flarum answers the bare id and
             * redirects to the slugged form itself, so building the slug here
             * would mean two places deciding what a discussion is called — and
             * ours would be the one that goes stale the moment a title is
             * edited.
             */
            'topic' => '/d/'.$id,
            'tag' => $this->tagPath($id),
            'user' => $this->userPath($id),
            default => null,
        };
    }

    /**
     * A tag is addressed by SLUG, so the id has to be turned back into one.
     */
    private function tagPath(int $id): ?string
    {
        $tag = Dst::db()->table('tags')->where('id', $id)->first();

        return $tag && $tag->slug !== '' ? '/t/'.$tag->slug : null;
    }

    /** And a user by username. */
    private function userPath(int $id): ?string
    {
        $user = Dst::db()->table('users')->where('id', $id)->first();

        return $user && $user->username !== '' ? '/u/'.$user->username : null;
    }

    /**
     * A handful of real examples for the wizard to show.
     *
     * 🚨 Built from rows that ACTUALLY EXIST in this site's map, not from the
     * examples in the pattern table. A preview made of invented URLs proves the
     * regular expression compiles and nothing else; one made of real ids proves
     * the redirect will work on this forum, which is the only question the
     * person pressing the button has.
     *
     * @return list<array{kind: string, from: string, to: ?string}>
     */
    public function preview(int $runId, string $source, int $limit = 6): array
    {
        $out = [];

        foreach (Patterns::for($source) as $rule) {
            $row = Dst::db()->table('importer_map')
                ->where('run_id', $runId)
                ->where('kind', $rule['kind'])
                ->orderBy('id')
                ->first();

            if (! $row) {
                continue;
            }

            /*
             * Put a real id into the pattern's own example, so what is shown
             * is an address this forum genuinely used to serve.
             */
            $from = $this->exampleWith($rule['example'], (string) $row->source_id);

            $out[] = [
                'kind' => $rule['kind'],
                'from' => $from,
                'to' => $this->resolve($runId, $source, $from),
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Swap the id in a pattern's example for a real one.
     *
     * 🚨 The LAST number in the example is replaced, not the first. Half of
     * these carry two — `/viewtopic.php?f=2&t=1234` — and the one that
     * identifies the topic is always the later.
     */
    private function exampleWith(string $example, string $sourceId): string
    {
        if (! preg_match_all('/\d+/', $example, $m, PREG_OFFSET_CAPTURE)) {
            // A slug-based example: swap the last path segment instead.
            return preg_replace('~[^/]+$~', $sourceId, $example) ?? $example;
        }

        [$digits, $offset] = end($m[0]);

        return substr($example, 0, $offset).$sourceId.substr($example, $offset + strlen($digits));
    }
}
