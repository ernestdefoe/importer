<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * Invision Community 4.x / 5.x (IP.Board) → Flarum.
 *   forums_forums → tags (titles live in core_sys_lang_words: forums_forum_{id})
 *   core_members  → users (bcrypt copies straight; legacy md5 → reset)
 *   forums_topics → discussions · forums_posts → posts
 * IPS post content is HTML (not BBCode); ipsHtml() cleans the IPS-specific markup.
 */
class InvisionImporter
{
    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $sb = $conn->getSchemaBuilder();
        foreach (['core_members', 'forums_forums', 'forums_topics', 'forums_posts'] as $req) {
            if (! $sb->hasTable($req)) {
                throw new \RuntimeException("This doesn't look like an Invision Community database (missing “{$req}”).");
            }
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table('core_members')->where('member_id', '>', 0)->count(),
            'categories' => (int) $conn->table('forums_forums')->count(),
            'topics' => (int) $conn->table('forums_topics')->where('approved', 1)->count(),
            'posts' => (int) $conn->table('forums_posts')->where('queued', 0)->count(),
        ]];
    }

    /** @return Phase[] */
    public static function phases(array $cfg): array
    {
        $hasTags = Dst::hasTags();

        return array_merge([
            new Phase('tags', 'Importing categories…',
                fn () => $hasTags ? (int) Src::connect($cfg)->table('forums_forums')->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($hasTags) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $conn = $ctx->src();
                    $rows = $conn->table('forums_forums')->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get();
                    [$names, $descs] = self::forumWords($conn, $rows->pluck('id')->all());
                    $map = [];
                    $n = 0;
                    foreach ($rows as $f) {
                        $cursor = $f->id;
                        if ((int) ($f->redirect_on ?? 0) === 1 && trim((string) ($f->redirect_url ?? '')) !== '') {
                            continue; // redirect-only forum — no content
                        }
                        $name = $names[(int) $f->id] ?? (($f->name_seo ?? '') ? \Illuminate\Support\Str::headline((string) $f->name_seo) : ('Forum ' . $f->id));
                        $map[$f->id] = Dst::tag($name, Src::tagSlug($name, (int) $f->id), $descs[(int) $f->id] ?? null, $f->feature_color ?? null, (int) ($f->position ?? 0));
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase('users', 'Importing members…',
                fn () => (int) Src::connect($cfg)->table('core_members')->where('member_id', '>', 0)->count(),
                function ($cursor, $limit, Ctx $ctx) {
                    $rows = $ctx->src()->table('core_members')->where('member_id', '>', (int) $cursor)->orderBy('member_id')->limit($limit)->get();
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->member_id;
                        $email = trim((string) ($u->email ?? ''));
                        if ($email === '') {
                            $skip++;

                            continue;
                        }
                        try {
                            $map[$u->member_id] = Dst::user(Src::username($u->name ?? null, (int) $u->member_id), $email, $u->members_pass_hash ?? null, Src::ts($u->joined ?? null));
                            $n++;
                        } catch (\Throwable) {
                            $skip++;
                        }
                    }
                    $ctx->mapPut('user', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['users' => $n, 'skipped' => $skip]];
                }
            ),

            new Phase('topics', 'Importing topics…',
                fn () => (int) Src::connect($cfg)->table('forums_topics')->where('approved', 1)->count(),
                function ($cursor, $limit, Ctx $ctx) use ($hasTags) {
                    $rows = $ctx->src()->table('forums_topics')->where('approved', 1)->where('tid', '>', (int) $cursor)->orderBy('tid')->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('starter_id')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('forum_id')->all()) : [];
                    $map = [];
                    $n = 0;
                    foreach ($rows as $t) {
                        $cursor = $t->tid;
                        if (($t->state ?? 'open') === 'link' || trim((string) ($t->moved_to ?? '')) !== '') {
                            continue; // moved/redirect pointer
                        }
                        $did = Dst::discussion($t->title ?: 'Untitled', $userMap[(string) $t->starter_id] ?? null, Src::ts($t->start_date ?? null), (bool) ($t->pinned ?? false), ($t->state ?? 'open') === 'closed');
                        $map[$t->tid] = $did;
                        if ($hasTags && isset($tagMap[(string) $t->forum_id])) {
                            Dst::attachTag($did, $tagMap[(string) $t->forum_id]);
                        }
                        $n++;
                    }
                    $ctx->mapPut('topic', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['topics' => $n]];
                }
            ),

            new Phase('posts', 'Importing posts…',
                fn () => (int) Src::connect($cfg)->table('forums_posts')->where('queued', 0)->count(),
                fn ($cursor, $limit, Ctx $ctx) => Phases::postsBatch($cursor, $limit, $ctx,
                    fn ($conn, $cur, $lim) => $conn->table('forums_posts')->where('queued', 0)
                        ->where(fn ($q) => $q->where('topic_id', '>', (int) $cur['tid'])
                            ->orWhere(fn ($q2) => $q2->where('topic_id', (int) $cur['tid'])->where('pid', '>', (int) $cur['pid'])))
                        ->orderBy('topic_id')->orderBy('pid')->limit($lim)->get(),
                    fn ($post) => [
                        'tid' => (int) $post->topic_id, 'pid' => (int) $post->pid, 'uid' => $post->author_id,
                        'html' => self::ipsHtml($post->post ?? ''), 'at' => Src::ts($post->post_date ?? null),
                        'ok' => (int) ($post->pdelete_time ?? 0) === 0,
                    ]
                )
            ),
        ], Phases::tail());
    }

    /**
     * Forum titles/descriptions are translatable strings in core_sys_lang_words,
     * keyed forums_forum_{id} / forums_forum_{id}_desc.
     *
     * @return array{0:array<int,string>,1:array<int,string>} [names, descs]
     */
    private static function forumWords($conn, array $ids): array
    {
        $names = $descs = [];
        if (! $ids || ! $conn->getSchemaBuilder()->hasTable('core_sys_lang_words')) {
            return [$names, $descs];
        }
        $keys = [];
        foreach ($ids as $id) {
            $keys[] = 'forums_forum_' . (int) $id;
            $keys[] = 'forums_forum_' . (int) $id . '_desc';
        }
        $words = $conn->table('core_sys_lang_words')->where('word_app', 'forums')->whereIn('word_key', $keys)
            ->orderBy('lang_id')->get(['word_key', 'word_default', 'word_custom']);
        foreach ($words as $w) {
            $val = ($w->word_custom !== null && $w->word_custom !== '') ? $w->word_custom : $w->word_default;
            if ($val === null || $val === '') {
                continue;
            }
            if (preg_match('/^forums_forum_(\d+)_desc$/', $w->word_key, $m)) {
                $descs[(int) $m[1]] ??= $val;
            } elseif (preg_match('/^forums_forum_(\d+)$/', $w->word_key, $m)) {
                $names[(int) $m[1]] ??= $val;
            }
        }

        return [$names, $descs];
    }

    /** Convert IPS post HTML into clean HTML (mentions→text, ipsQuote→blockquote, emoticons→alt), sanitised. */
    public static function ipsHtml(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }
        $html = Src::sanitizeHtml($html);

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $ok = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="ips-root">' . $html . '</div>',
            LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        if (! $ok) {
            return $html;
        }
        $root = $dom->getElementById('ips-root');
        if (! $root) {
            return $html;
        }

        // Mentions → plain text.
        foreach (iterator_to_array($dom->getElementsByTagName('a')) as $a) {
            if ($a->hasAttribute('data-mentionid') || str_contains($a->getAttribute('class'), 'ipsMention')) {
                $a->parentNode?->replaceChild($dom->createTextNode($a->textContent), $a);
            }
        }

        // Emoticon images → their alt text.
        foreach (iterator_to_array($dom->getElementsByTagName('img')) as $img) {
            if ($img->hasAttribute('data-emoticon') || str_contains($img->getAttribute('class'), 'ipsEmoji')) {
                $img->parentNode?->replaceChild($dom->createTextNode($img->getAttribute('alt')), $img);
            } elseif (! $img->getAttribute('src') && $img->getAttribute('data-src')) {
                $img->setAttribute('src', $img->getAttribute('data-src'));
            }
        }

        // Quotes → clean blockquote with attribution.
        foreach (iterator_to_array($dom->getElementsByTagName('blockquote')) as $bq) {
            if (! str_contains($bq->getAttribute('class'), 'ipsQuote') && ! $bq->hasAttribute('data-ipsquote')) {
                continue;
            }
            $user = trim($bq->getAttribute('data-ipsquote-username'));
            $contents = null;
            foreach (iterator_to_array($bq->childNodes) as $child) {
                if ($child instanceof \DOMElement && str_contains($child->getAttribute('class'), 'ipsQuote_citation')) {
                    $bq->removeChild($child);

                    continue;
                }
                if ($child instanceof \DOMElement && str_contains($child->getAttribute('class'), 'ipsQuote_contents')) {
                    $contents = $child;
                }
            }
            if ($contents) {
                while ($contents->firstChild) {
                    $bq->insertBefore($contents->firstChild, $contents);
                }
                $bq->removeChild($contents);
            }
            foreach (iterator_to_array($bq->attributes) as $attr) {
                $bq->removeAttribute($attr->nodeName);
            }
            if ($user !== '') {
                $cite = $dom->createElement('p');
                $cite->appendChild($dom->createElement('strong', $user . ' wrote:'));
                $bq->insertBefore($cite, $bq->firstChild);
            }
        }

        // Strip IPS styling hooks from every element (keep href/src/alt/title).
        foreach (iterator_to_array($dom->getElementsByTagName('*')) as $el) {
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = $attr->nodeName;
                if ($name === 'class' || $name === 'style' || str_starts_with($name, 'data-')) {
                    $el->removeAttribute($name);
                }
            }
        }

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }
}
