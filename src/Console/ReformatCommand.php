<?php

namespace ErnestDefoe\Importer\Console;

use ErnestDefoe\Importer\Importers\Src;
use ErnestDefoe\Importer\Markup\FromXml;
use ErnestDefoe\Importer\Markup\Target;
use ErnestDefoe\Importer\Markup\Writer;
use Flarum\Console\AbstractCommand;
use Flarum\Formatter\Formatter;
use Flarum\Http\UrlGenerator;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Rewrite imported posts and messages for the editor the forum uses NOW.
 *
 * An import writes every post in the syntax of the editor enabled at the
 * time. Switch from Markdown to Scribe, or turn BBCode on or off, and the
 * imported posts still hold the old syntax — mostly harmless, since Flarum
 * stores what each piece IS, but a construct the new editor cannot open (a
 * Markdown spoiler in Scribe, a Scribe table on a Markdown forum) edits badly.
 *
 * This reads each stored post back to what it means ({@see FromXml}) and
 * writes it again for the current editor ({@see Writer}), exactly as a fresh
 * import would. The old board is not needed. Chunked; safe to run again, as an
 * unchanged post is left alone.
 */
class ReformatCommand extends AbstractCommand
{
    public function __construct(private ConnectionInterface $db, private Formatter $formatter, private UrlGenerator $url)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('importer:reformat')
            ->setDescription('Rewrite imported posts and private messages for the editor this forum uses now (after switching between Markdown, BBCode and Scribe).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count and preview the changes without saving any.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every post on the forum, not only imported ones.')
            ->addOption('no-messages', null, InputOption::VALUE_NONE, 'Leave private messages alone.')
            ->addOption('markdown-text', null, InputOption::VALUE_NONE, 'Also read posts that show Markdown as text (**bold**, > quotes) as Markdown: what imports before 0.6.0 left on a forum without flarum/markdown.')
            ->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'Posts per batch', '500');
    }

    protected function fire(): int
    {
        $dry = (bool) $this->input->getOption('dry-run');
        $mdText = (bool) $this->input->getOption('markdown-text');
        $all = (bool) $this->input->getOption('all');
        $chunk = max(50, (int) $this->input->getOption('chunk'));
        $target = Target::current();
        $this->info('Writing for: '.$target->label().($target->mentions ? ', with mentions' : '').'.');

        $schema = $this->db->getSchemaBuilder();
        if (! $all && ! $schema->hasTable('importer_map')) {
            $this->info('Nothing was imported here.');

            return 0;
        }

        $topicUrl = fn (int $id) => $this->url->to('forum')->route('discussion', ['id' => $id]);
        $seen = $changed = 0;
        $preview = 0;
        if ($mdText && $target->mode === Target::MARKDOWN) {
            $this->info('This forum reads Markdown already, so --markdown-text has nothing to do.');
            $mdText = false;
        }
        $rewrite = function (iterable $rows, string $table) use ($dry, $mdText, $target, $topicUrl, &$seen, &$changed, &$preview) {
            foreach ($rows as $row) {
                $seen++;
                $old = (string) $row->content;
                try {
                    $html = $mdText && self::markdownAsText($old)
                        ? Src::markdown((string) $this->formatter->unparse($old, null))
                        : FromXml::toHtml($old);
                    $text = Writer::write($html, $target, [], $topicUrl);
                    $new = $this->formatter->parse($text !== '' ? $text : "\u{200B}", null);
                } catch (\Throwable $e) {
                    $this->error("{$table} {$row->id}: ".$e->getMessage());

                    continue;
                }
                if ($new === $old) {
                    continue;
                }
                $changed++;
                if ($dry) {
                    if ($preview++ < 3) {
                        $this->info("{$table} {$row->id}:\n".mb_substr($text, 0, 400)."\n");
                    }

                    continue;
                }
                $this->db->table($table)->where('id', $row->id)->update(['content' => $new]);
                if ($table === 'posts') {
                    // A sync only refreshes posts nobody changed here; this change is the importer's own.
                    $this->db->table('importer_map')->where('kind', 'post')->where('target_id', $row->id)
                        ->where('hash', sha1($old))->update(['hash' => sha1($new)]);
                }
            }
        };

        $posts = $this->db->table('posts')->where('type', 'comment')->select('posts.id', 'posts.content');
        if (! $all) {
            $posts->whereIn('posts.discussion_id', $this->db->table('importer_map')->where('kind', 'topic')->select('target_id'));
        }
        $posts->orderBy('posts.id')->chunkById($chunk, fn ($rows) => $rewrite($rows, 'posts'), 'posts.id', 'id');
        $posts = $seen;

        $messages = 0;
        if (! $this->input->getOption('no-messages') && $schema->hasTable('dialog_messages')) {
            $q = $this->db->table('dialog_messages')->select('dialog_messages.id', 'dialog_messages.content');
            if (! $all) {
                $q->whereIn('dialog_messages.id', $this->db->table('importer_map')->where('kind', 'pm')->select('target_id'));
            }
            $q->orderBy('dialog_messages.id')->chunkById($chunk, fn ($rows) => $rewrite($rows, 'dialog_messages'), 'dialog_messages.id', 'id');
            $messages = $seen - $posts;
        }

        $this->info(sprintf(
            '%s %d of %d posts and messages (%d posts, %d messages scanned).%s',
            $dry ? 'Would rewrite' : 'Rewrote',
            $changed,
            $seen,
            $posts,
            $messages,
            $dry ? ' Nothing was saved; run again without --dry-run.' : ''
        ));

        return 0;
    }

    /**
     * A post the formatter stored as plain text although it is Markdown: no
     * structure was parsed, yet the text has Markdown's marks in it.
     */
    private static function markdownAsText(string $xml): bool
    {
        if (preg_match('#<(?:STRONG|EM|QUOTE|LIST|CODE|H[1-6]|P|B|I|U|TABLE|SPOILER|SCRIBESPOILER)[\s>]#', $xml)) {
            return false;
        }
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');

        return (bool) preg_match('/\*\*\S|(?:^|\n)(?:>|[-*+] |\d+\. |#{1,6} |```)|\]\(https?:\/\/|!\[/', $text);
    }
}
