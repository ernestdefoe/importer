<?php

/*
 * This file is part of ernestdefoe/importer.
 */

namespace ErnestDefoe\Importer\Redirects;

use ErnestDefoe\Importer\Importers\Dst;
use ErnestDefoe\Importer\Importers\Src;

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

            $target = $this->lookup($runId, $rule['kind'], $m[1] ?? '', (int) ($m[2] ?? 0));

            if ($target !== null) {
                return $target;
            }

            /*
             * 🚨 Matched the shape but not the id, and the loop CONTINUES.
             *
             * A vBulletin 4 site serves both `/threads/123-slug` and
             * `showthread.php?t=123`, and an address can satisfy one pattern
             * while the id belongs to another kind entirely — a Discourse
             * `/t/…/123` is a discussion or a private conversation. Stopping
             * at the first shape match would answer "no" for a URL a later
             * pattern would have resolved.
             */
        }

        return null;
    }

    /**
     * The Flarum path for one old id.
     *
     * @param string $kind see {@see Patterns::all()}
     * @param int $number a post's position in its topic, when the address gives one
     */
    private function lookup(int $runId, string $kind, string $sourceId, int $number = 0): ?string
    {
        if ($kind === 'home') {
            return '/';
        }
        $sourceId = rawurldecode($sourceId);

        return match ($kind) {
            /*
             * 🚨 `/d/123` and not `/d/123-slug`. Flarum answers the bare id and
             * redirects to the slugged form itself, so building the slug here
             * would mean two places deciding what a discussion is called — and
             * ours would be the one that goes stale the moment a title is
             * edited.
             */
            'topic' => ($id = $this->target($runId, 'topic', $sourceId)) ? '/d/'.$id.($number > 1 ? '/'.$number : '') : null,
            'post' => $this->postPath($this->target($runId, 'post', $sourceId)),
            'poll' => $this->postPath((int) Dst::db()->table('polls')->where('id', $this->target($runId, 'poll', $sourceId))->value('post_id')),
            'tag' => $this->tagPath($this->target($runId, 'tag', $sourceId)),
            'tag-slug' => $this->tagPath($this->target($runId, 'tag-slug', [$sourceId, mb_strtolower($sourceId)])
                ?? $this->mapped($runId, 'tag', Dst::db()->table('tags')->where('slug', mb_strtolower($sourceId)))),
            'user' => $this->userPath($this->target($runId, 'user', $sourceId)),
            // The name in the address, as the import turned it into a username.
            'user-name' => $this->userPath($this->mapped($runId, 'user', Dst::db()->table('users')
                ->whereRaw('LOWER(username) = ?', [mb_strtolower(Src::username($sourceId, 0))]))),
            'pm' => $this->messagePath($this->target($runId, 'pm', $sourceId)),
            'pm-copy' => $this->messagePath($this->target($runId, 'pm-copy', $sourceId) ?? $this->target($runId, 'pm', $sourceId)),
            'pm-conv' => ($id = $this->target($runId, 'pm-conv', $sourceId)) ? '/messages/dialog/'.$id : null,
            default => null,
        };
    }

    /** What the import made of a source id (or of the first of several spellings of it). */
    private function target(int $runId, string $kind, string|array $sourceId): ?int
    {
        $id = (int) Dst::db()->table('importer_map')
            ->where('run_id', $runId)
            ->where('kind', $kind)
            ->whereIn('source_id', array_unique((array) $sourceId))
            ->value('target_id');

        return $id > 0 ? $id : null;
    }

    /** The first row of $query that this import made (never somebody's own tag or account of the same name). */
    private function mapped(int $runId, string $kind, \Illuminate\Database\Query\Builder $query): ?int
    {
        $id = (int) $query->whereIn('id', Dst::db()->table('importer_map')->where('run_id', $runId)->where('kind', $kind)->select('target_id'))
            ->value('id');

        return $id > 0 ? $id : null;
    }

    /** A post is found at its number in its discussion. */
    private function postPath(?int $id): ?string
    {
        $post = $id ? Dst::db()->table('posts')->where('id', $id)->first(['discussion_id', 'number']) : null;

        return $post ? '/d/'.$post->discussion_id.'/'.$post->number : null;
    }

    /** A private message, at its place in its conversation (flarum/messages). */
    private function messagePath(?int $id): ?string
    {
        $message = $id ? Dst::db()->table('dialog_messages')->where('id', $id)->first(['dialog_id', 'number']) : null;

        return $message ? '/messages/dialog/'.$message->dialog_id.'/'.$message->number : null;
    }

    /**
     * A tag is addressed by SLUG, so the id has to be turned back into one.
     */
    private function tagPath(?int $id): ?string
    {
        $tag = $id ? Dst::db()->table('tags')->where('id', $id)->first() : null;

        return $tag && $tag->slug !== '' ? '/t/'.rawurlencode($tag->slug) : null;
    }

    /** And a user by username. */
    private function userPath(?int $id): ?string
    {
        $user = $id ? Dst::db()->table('users')->where('id', $id)->first() : null;

        return $user && $user->username !== '' ? '/u/'.rawurlencode($user->username) : null;
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
    public function preview(int $runId, string $source, int $limit = 10): array
    {
        $out = $seen = [];

        foreach (Patterns::for($source) as $rule) {
            // One example a kind; a member's name or the front page has no id to show.
            if (isset($seen[$rule['kind']]) || in_array($rule['kind'], ['home', 'user-name'], true)) {
                continue;
            }
            $seen[$rule['kind']] = true;
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
