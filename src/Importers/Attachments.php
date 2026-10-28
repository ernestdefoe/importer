<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Support\Str;

/**
 * Post attachments, for one batch of posts: copied onto this forum and put in
 * the post where the old board showed them.
 *
 * An importer that has attachments passes `files:` to {@see Phases::posts()}
 * (or uses this class itself, where its first posts are written elsewhere):
 *
 *   'fetch'   ?fn (list<string> $postIds, Ctx $ctx): array<string, list<array>>
 *             each post's attachments, in the board's own order:
 *               id      string  unique within the source
 *               name    string  the file name members saw
 *               bytes / path / url   where the file is ({@see Files::bytes()})
 *               keys    list<string>  other ways a post refers to it ('i0' =
 *                       phpBB's [attachment=0], an address, …)
 *               append  bool    shown under the post when the text never
 *                       placed it (default true)
 *   'inline'  ?string  a pattern for addresses on the OLD board's host whose
 *             files are its uploads (`#/uploads/#`): such an image or link in
 *             a post is copied as well, so it does not die with the old site
 *   'lookup'  ?fn (string $ref, Ctx $ctx): ?array  an attachment the text names
 *             that is not one of this post's own (a quote of another post)
 *   'disk'    ?array{0:string,1:string}  [pattern, folder]: where such an
 *             address's file is under `files_path` — the folder, plus the
 *             address's path with the pattern removed
 *
 * A file referred to in the text ([ATTACH]12[/ATTACH], [attachment=0], an
 * `<img>` of an upload) becomes an image or link there; the rest are listed
 * under the post, as the old board listed them. A file that cannot be read
 * keeps its name in the post (Bbcode's label) and is counted, never dropped
 * without a trace.
 *
 * 🚨 Stored by {@see Dst::storeAsset()}: an image keeps the extension its
 * content proves, a document a known safe one, anything else becomes .bin —
 * nothing the old board held can run or render on this forum's origin. With
 * FoF Upload installed the file is one of its files (in its folder and its
 * media manager) and the post is linked to it; otherwise it is a plain link.
 *
 * Each copy is recorded in `importer_files`, so a quote, a resumed step, a
 * sync or a copied edit reuses it rather than storing the file again.
 */
final class Attachments
{
    private const MAX_BYTES = 50_000_000;

    /** @var array<string, list<array>> post id => its attachments */
    private array $byPost = [];

    /** @var array<string, array> any key an attachment answers to => the attachment */
    private array $byKey = [];

    /** @var array<string, array{url:string,name:string,isImage:bool,upload:?int}|false> source id => the copy (false: unreadable) */
    private array $stored = [];

    /** @var array<string,true> source ids already looked up in importer_files */
    private array $looked = [];

    /** @var array<string, array<string,true>> post id => attachment ids it placed */
    private array $used = [];

    /** @var array<string,int> source user id => Flarum user id, for FoF Upload's uploader */
    private array $users = [];

    /** @var array<string, array{0:array,1:?array}> copies made this batch, written by {@see done()}: [importer_files row, FoF Upload row] */
    private array $pending = [];

    /** @var list<array{0:string,1:int,2:bool}> [source post id, Flarum post id, an edit] */
    private array $links = [];

    /** @var array<string,int> */
    public array $summary = [];

    private function __construct(private ?array $def, private Ctx $ctx)
    {
    }

    /** @param  list<string|int>  $postIds */
    public static function batch(?array $def, Ctx $ctx, array $postIds): self
    {
        $a = new self($def, $ctx);
        $ids = array_values(array_unique(array_map('strval', $postIds)));
        if ($ids && ($fetch = $def['fetch'] ?? null)) {
            foreach ($fetch($ids, $ctx) as $pid => $list) {
                foreach ($list as $att) {
                    $att['id'] = (string) $att['id'];
                    $a->byPost[(string) $pid][] = $att;
                    foreach ([$att['id'], ...($att['keys'] ?? [])] as $k) {
                        $a->byKey[(string) $pid.'|'.$k] = $att;
                    }
                }
            }
            // Both looked up once for the batch: the copies made already, and who uploaded what.
            $all = array_merge(...array_values($a->byPost ?: [[]]));
            $a->preload(array_column($all, 'id'));
            $a->users = $ctx->mapGet('user', array_filter(array_column($all, 'uid'), fn ($v) => $v !== null));
        }

        return $a;
    }

    /** Bbcode's 'attachment' option for one post: [ATTACH]n / [attachment=i] → the copy. */
    public function resolver(string|int $postId): \Closure
    {
        $pid = (string) $postId;

        return function (string $ref) use ($pid): ?array {
            $att = $this->byKey[$pid.'|'.$ref] ?? null;
            // Another post's attachment (a quote, a picture shown again): looked up on its own.
            $other = ! $att && ($lookup = $this->def['lookup'] ?? null) ? $lookup($ref, $this->ctx) : null;
            if (! ($att ??= $other) || ! ($copy = $this->store($att))) {
                return null;
            }
            $this->used[$pid][$att['id']] = true;

            return ['url' => $copy['url'], 'filename' => $copy['name'], 'isImage' => $copy['isImage']];
        };
    }

    /**
     * Copy the old board's uploads the posts link to, before they are written:
     * one look-up for the whole batch, then the files not copied yet.
     *
     * @param  array<string,string>  $htmls  post id => canonical HTML
     * @return array<string,string>
     */
    public function finish(array $htmls): array
    {
        $base = rtrim(trim((string) ($this->ctx->cfg['assets_base'] ?? '')), '/');
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        $pattern = $this->def['inline'] ?? null;
        $found = [];
        if ($host !== '' || $this->byKey) {
            foreach ($htmls as $pid => $html) {
                preg_match_all('/\b(src|href)="([^"]+)"/i', (string) $html, $m, PREG_SET_ORDER);
                foreach ($m as [$whole, , $raw]) {
                    $url = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $abs = $this->absolute($url, $base);
                    if ($att = $this->byKey[$pid.'|'.$url] ?? $this->byKey[$pid.'|'.$abs] ?? $this->byPath((string) $pid, $this->tail($abs ?? $url))) {
                        $found[$pid][$raw] = $att;
                    } elseif ($pattern && $abs !== null && strtolower((string) parse_url($abs, PHP_URL_HOST)) === $host && preg_match($pattern, (string) parse_url($abs, PHP_URL_PATH))) {
                        $path = (string) parse_url($abs, PHP_URL_PATH);
                        $found[$pid][$raw] = ['id' => 'url:'.sha1($abs), 'name' => rawurldecode(basename($path)) ?: 'file', 'url' => $abs, 'append' => false]
                            + (isset($this->def['disk']) && preg_match($this->def['disk'][0], $path) ? ['root' => $this->def['disk'][1], 'path' => rawurldecode((string) preg_replace($this->def['disk'][0], '', $path))] : []);
                    }
                }
            }
        }
        $this->preload(array_merge(...array_map(fn ($l) => array_column($l, 'id'), array_values($found ?: [[]]))));

        foreach ($htmls as $pid => $html) {
            foreach ($found[$pid] ?? [] as $raw => $att) {
                if ($copy = $this->store($att)) {
                    $html = str_replace('"'.$raw.'"', '"'.htmlspecialchars($copy['url'], ENT_QUOTES, 'UTF-8').'"', $html);
                    $this->used[$pid][$att['id']] = true;
                }
            }
            $rest = '';
            foreach ($this->byPost[$pid] ?? [] as $att) {
                if (($att['append'] ?? true) && empty($this->used[$pid][$att['id']])) {
                    $rest .= $this->html($att);
                    $this->used[$pid][$att['id']] = true;
                }
            }
            $htmls[$pid] = $rest === '' ? $html : $html.$rest;
        }

        return $htmls;
    }

    /** Tie a written post to the FoF Upload files it holds, as FoF Upload does when a post is saved ({@see done()}). */
    public function link(string|int $postId, int $flarumPostId, bool $edited = false): void
    {
        if (! empty($this->used[(string) $postId])) {
            $this->links[] = [(string) $postId, $flarumPostId, $edited];
        }
    }

    /**
     * Write what the batch made — its FoF Upload files, the record of each
     * copy, which post holds which file — a few statements for the whole
     * batch, and add its counts to the phase's summary.
     */
    public function done(array $summary): array
    {
        $db = Dst::db();
        $fof = array_filter(array_map(fn ($p) => $p[1], $this->pending));
        if ($fof) {
            foreach (array_chunk(array_values($fof), 200) as $chunk) {
                $db->table('fof_upload_files')->insert($chunk);
            }
            $ids = $db->table('fof_upload_files')->whereIn('uuid', array_column($fof, 'uuid'))->pluck('id', 'uuid')->all();
            foreach ($fof as $id => $row) {
                $this->stored[$id]['upload'] = isset($ids[$row['uuid']]) ? (int) $ids[$row['uuid']] : null;
            }
        }
        $rows = [];
        foreach ($this->pending as $id => [$row]) {
            $rows[] = $row + ['upload_id' => $this->stored[$id]['upload'] ?? null];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            $db->table('importer_files')->insert($chunk);
        }
        $this->pending = [];

        $pairs = [];
        foreach ($this->links as [$pid, $post]) {
            foreach (array_keys($this->used[$pid] ?? []) as $id) {
                if ($upload = ($this->stored[$id] ?: [])['upload'] ?? null) {
                    $pairs[$post.'-'.$upload] = ['file_id' => $upload, 'post_id' => $post];
                }
            }
        }
        // An edit copied across adds only the files its post did not hold already.
        $edited = array_column(array_filter($this->links, fn ($l) => $l[2]), 1);
        if ($pairs && $edited) {
            foreach ($db->table('fof_upload_file_posts')->whereIn('post_id', $edited)->get(['file_id', 'post_id']) as $r) {
                unset($pairs[$r->post_id.'-'.$r->file_id]);
            }
        }
        if ($pairs && Dst::hasTable('fof_upload_file_posts')) {
            foreach (array_chunk(array_values($pairs), 500) as $chunk) {
                $db->table('fof_upload_file_posts')->insert($chunk);
            }
        }
        $this->links = [];

        foreach ($this->summary as $k => $v) {
            $summary[$k] = ($summary[$k] ?? 0) + $v;
        }
        $this->summary = [];

        return $summary;
    }

    /** An attachment as the old board listed it under a post. */
    private function html(array $att): string
    {
        $copy = $this->store($att);
        if (! $copy) {
            return '<p><span data-attachment="1">['.htmlspecialchars(\ErnestDefoe\Importer\Markup\Html::attachmentLabel((string) $att['name']), ENT_QUOTES, 'UTF-8').']</span></p>';
        }
        $url = htmlspecialchars($copy['url'], ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars($copy['name'], ENT_QUOTES, 'UTF-8');

        return $copy['isImage'] ? '<p><img src="'.$url.'" alt="'.$name.'"></p>' : '<p><a href="'.$url.'">'.$name.'</a></p>';
    }

    /** An address's path, with its query (attachment.php?id=12). */
    private function tail(string $url): string
    {
        $q = parse_url($url, PHP_URL_QUERY);

        return (string) parse_url($url, PHP_URL_PATH).($q ? '?'.$q : '');
    }

    /** An attachment named by the end of an address's path ("uploads/ab/c.png" in "/forum/uploads/ab/c.png"). */
    private function byPath(string $pid, string $path): ?array
    {
        $parts = array_values(array_filter(explode('/', $path), 'strlen'));
        for ($i = 0; $i < count($parts); $i++) {
            if ($att = $this->byKey[$pid.'|'.implode('/', array_slice($parts, $i))] ?? null) {
                return $att;
            }
        }

        return null;
    }

    private function absolute(string $url, string $base): ?string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }
        if ($base === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) || str_starts_with($url, '#')) {
            return null;
        }
        if (str_starts_with($url, '/')) {
            $p = parse_url($base);

            return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '').$url;
        }

        return $base.'/'.ltrim($url, './');
    }

    /** @param  list<string>  $ids */
    private function preload(array $ids): void
    {
        $ids = array_values(array_diff(array_unique($ids), array_keys($this->stored), array_keys($this->looked)));
        $this->looked += array_fill_keys($ids, true);
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (Dst::db()->table('importer_files')->where('run_id', $this->ctx->runId)->whereIn('source_id', $chunk)->get() as $f) {
                $this->stored[(string) $f->source_id] = ['url' => (string) $f->url, 'name' => (string) $f->name, 'isImage' => (bool) $f->is_image, 'upload' => $f->upload_id ? (int) $f->upload_id : null];
            }
        }
    }

    /** The copy of an attachment, made now if it is not made yet; null when the file cannot be read. */
    private function store(array $att): ?array
    {
        $id = (string) $att['id'];
        if (! array_key_exists($id, $this->stored) && ! isset($this->looked[$id])) {
            $this->preload([$id]);
        }
        if (array_key_exists($id, $this->stored)) {
            return $this->stored[$id] ?: null;
        }
        if (Dst::$dryRun) {
            return null; // a dry run writes no files
        }
        $name = trim((string) ($att['name'] ?? '')) ?: 'file';
        $bytes = Files::bytes($att, $this->ctx->cfg, self::MAX_BYTES, 30);
        $copy = $bytes !== null ? Dst::storeAsset($bytes, $name, $this->users[(string) ($att['uid'] ?? '')] ?? null) : null;
        if (! $copy) {
            $this->summary['attachments_missing'] = ($this->summary['attachments_missing'] ?? 0) + 1;
            $this->stored[$id] = false;

            return null;
        }
        $this->stored[$id] = ['url' => $copy['url'], 'name' => $name, 'isImage' => $copy['isImage'], 'upload' => null];
        $this->pending[$id] = [[
            'run_id' => $this->ctx->runId, 'source_id' => Str::limit($id, 80, ''), 'url' => Str::limit($copy['url'], 2000, ''),
            'name' => Str::limit($name, 255, ''), 'is_image' => $copy['isImage'],
        ], $copy['upload'] ?? null];
        $this->summary['attachments'] = ($this->summary['attachments'] ?? 0) + 1;

        return $this->stored[$id];
    }

    /** Discourse's short upload address (upload://abc.png, /uploads/short-url/abc.png) for a sha1. */
    public static function base62(string $sha1): string
    {
        $digits = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $n = array_map('hexdec', str_split(strtolower($sha1)));
        $out = '';
        while ($n) {
            $rem = 0;
            $next = [];
            foreach ($n as $d) {
                $acc = $rem * 16 + $d;
                $q = intdiv($acc, 62);
                $rem = $acc % 62;
                if ($next || $q) {
                    $next[] = $q;
                }
            }
            $out = $digits[$rem].$out;
            $n = $next;
        }

        return $out === '' ? '0' : $out;
    }
}
