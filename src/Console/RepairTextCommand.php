<?php

namespace ErnestDefoe\Importer\Console;

use ErnestDefoe\Importer\Importers\PostText;
use ErnestDefoe\Importer\Importers\Src;
use Flarum\Console\AbstractCommand;
use Flarum\Formatter\Formatter;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Fix posts imported before 0.3.7, in place:
 *
 *  - phpBB 3.0 markup that arrived as visible text (`<!-- m --><a class=
 *    "postlink" …>`, smilies, `[/*:m]` list closers);
 *  - `&amp;`, `&lt;`, `&gt;` showing literally in imported posts from every
 *    source;
 *  - phpBB 3.2+ posts whose stored XML (`<r><QUOTE author="…"><s>`) arrived
 *    as visible text, including ones an earlier run of this command marked
 *    done.
 *
 * Works from what is in the forum — the old board is not needed. Each post's
 * stored content is unparsed, fixed, and parsed again by the same formatter
 * that saves an edit. Chunked, and safe to run twice: a repaired post is
 * recorded and skipped.
 */
class RepairTextCommand extends AbstractCommand
{
    private const MARK = 'text-repaired';

    public function __construct(private ConnectionInterface $db, private Formatter $formatter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('importer:repair-text')
            ->setAliases(['importer:repair-phpbb-links'])
            ->setDescription('Fix links, smilies, phpBB tags and &amp; that show as text in posts imported by an earlier version.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count and preview the changes without saving any.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Scan every post, not only imported ones (for a run that was reset). Only phpBB 3.0 markup is repaired outside an import.')
            ->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'Posts per batch', '500');
    }

    protected function fire(): int
    {
        $dry = (bool) $this->input->getOption('dry-run');
        $all = (bool) $this->input->getOption('all');
        $chunk = max(50, (int) $this->input->getOption('chunk'));

        // Imported posts: in a discussion an import created, dated before that
        // run finished. Replies written on the forum since are left alone.
        // Runs started on 0.3.7+ already wrote fixed text; leave them out.
        $runs = $this->db->table('importer_runs')->get(['id', 'updated_at', 'state'])
            ->reject(fn ($r) => (json_decode((string) $r->state, true)['textFixed'] ?? false))
            ->pluck('updated_at', 'id');
        $imported = $this->db->table('importer_map')->where('kind', 'topic')->exists();
        if (! $all && ! $imported) {
            $this->info('No import map was found (the run was reset). Re-run with --all to repair phpBB 3.0 markup in any post.');

            return 0;
        }

        $done = array_flip($this->db->table('importer_map')->where('kind', self::MARK)->pluck('target_id')->all());
        $q = $this->db->table('posts')->where('type', 'comment')->select('posts.id', 'posts.content', 'posts.discussion_id', 'posts.created_at');
        if (! $all) {
            $q->whereIn('posts.discussion_id', $this->db->table('importer_map')->where('kind', 'topic')->select('target_id'));
        }

        // Which run made each imported discussion, for the cutoff.
        $runOf = fn (array $dids) => $this->db->table('importer_map')->where('kind', 'topic')->whereIn('target_id', $dids)->pluck('run_id', 'target_id')->all();

        // In the forum's language, as a fresh import writes it. Flarum hands
        // back the key itself when the strings are not loaded, as in a console
        // run, and that must never land in somebody's post.
        $wrote = function (string $name): string {
            $t = Src::t('quote_wrote', ['name' => $name]);

            return str_contains($t, 'quote_wrote') ? $name.' wrote:' : $t;
        };

        $seen = $changed = $links = 0;
        $q->orderBy('posts.id')->chunkById($chunk, function ($posts) use ($dry, $all, $runs, $runOf, $wrote, &$done, &$seen, &$changed, &$links) {
            $owner = $all ? [] : $runOf($posts->pluck('discussion_id')->unique()->all());
            $marks = [];
            foreach ($posts as $post) {
                $seen++;
                // 🚨 Done posts are revisited ONLY for phpBB 3.2+ XML: 0.4.1's
                // repair marked them done without knowing that format, and a
                // full rescan took one board a day. The string test is cheap.
                $wasDone = isset($done[$post->id]);
                if ($wasDone && ! PostText::mayHoldPhpbbXml((string) $post->content)) {
                    continue;
                }
                $run = $owner[$post->discussion_id] ?? null;
                $isImported = $run !== null && $post->created_at <= ($runs[$run] ?? null);
                if (! $all && ! $isImported) {
                    continue;
                }

                $md = $this->formatter->unparse($post->content, null);
                $xml = PostText::hasPhpbbXml($md);
                $legacy = ! $xml && PostText::hasPhpbbLegacy($md);
                if (! $isImported && ! $legacy && ! $xml) {
                    continue; // --all: only posts that are visibly from a phpBB import
                }
                if ($wasDone && ! $xml) {
                    continue;
                }
                $fixed = PostText::fixEntities(match (true) {
                    $xml => PostText::repairPhpbbXml($md, $wrote),
                    $legacy => PostText::repairPhpbb($md),
                    default => $md,
                });
                if (! $wasDone) {
                    $marks[] = ['run_id' => 0, 'kind' => self::MARK, 'source_id' => (string) $post->id, 'target_id' => $post->id];
                }
                if ($fixed === $md) {
                    continue;
                }

                $changed++;
                $links += $legacy ? substr_count($md, '&lt;a ') : 0;
                if ($dry) {
                    if ($changed <= 3) {
                        $this->info("Post {$post->id}:\n- ".mb_substr($md, 0, 300)."\n+ ".mb_substr($fixed, 0, 300)."\n");
                    }
                    continue;
                }
                $this->db->table('posts')->where('id', $post->id)->update(['content' => $this->formatter->parse($fixed, null)]);
            }
            if (! $dry && $marks) {
                $this->db->table('importer_map')->insert($marks);
                foreach ($marks as $m) {
                    $done[$m['target_id']] = true;
                }
            }
        }, 'posts.id', 'id');

        $this->info(sprintf(
            '%s %d of %d posts scanned (%d phpBB links rebuilt).%s',
            $dry ? 'Would repair' : 'Repaired',
            $changed,
            $seen,
            $links,
            $dry ? ' Nothing was saved; run again without --dry-run.' : ''
        ));

        return 0;
    }
}
