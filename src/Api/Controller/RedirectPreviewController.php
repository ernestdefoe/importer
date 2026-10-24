<?php

namespace ErnestDefoe\Importer\Api\Controller;

use ErnestDefoe\Importer\Importers\Dst;
use ErnestDefoe\Importer\Importers\Registry;
use ErnestDefoe\Importer\Redirects\Patterns;
use ErnestDefoe\Importer\Redirects\Resolver;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Everything the redirect wizard needs to show: which runs can be used, what
 * they hold, and what a redirect would actually do.
 *
 * 🚨 **The preview is the feature.** Turning redirects on is a promise about
 * every inbound link a forum ever had, and the person making it cannot check it
 * afterwards — a redirect that quietly resolves nothing looks exactly like one
 * that works, until the traffic does not arrive. So this returns real addresses
 * built from real rows in this site's own map, resolved for real, before
 * anybody presses anything.
 */
class RedirectPreviewController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Resolver $resolver,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $runs = $this->runs();

        $savedRun = (int) $this->settings->get('ernestdefoe-importer.redirect_run', 0);
        $savedSource = (string) $this->settings->get('ernestdefoe-importer.redirect_source', '');

        /*
         * Which run the wizard is looking at: the one asked for, else the one
         * already switched on, else the newest that has a map worth using.
         */
        $runId = (int) Arr::get($request->getQueryParams(), 'runId', 0);

        if ($runId < 1) {
            /*
             * 🚨 The newest run that actually MAPPED something, not simply the
             * newest. Re-running an import leaves the earlier attempt sitting
             * there with an empty map, and defaulting to it opens this panel on
             * "nothing to preview" for a forum that has a perfectly good map one
             * row further down — which reads as the feature being broken.
             */
            $runId = $savedRun > 0 ? $savedRun : (int) ($this->firstUsable($runs)['id'] ?? 0);
        }

        $run = null;

        foreach ($runs as $candidate) {
            if ($candidate['id'] === $runId) {
                $run = $candidate;

                break;
            }
        }

        $source = (string) Arr::get($request->getQueryParams(), 'source', '');

        if ($source === '') {
            /*
             * 🚨 Defaults to the run's OWN source, but stays overridable, and
             * that is not a nicety. `vbulletin` imports vBulletin 3, 4 and 5
             * through one importer, and those three serve completely different
             * addresses — `/showthread.php?t=`, `/threads/123-slug` and `/123-slug`.
             * The run says which database was read; only the person who ran the
             * old site knows which URLs are out there in the world.
             */
            $source = $runId === $savedRun && $savedSource !== '' ? $savedSource : (string) ($run['source'] ?? '');
        }

        return new JsonResponse([
            'enabled' => $savedRun > 0 && $savedSource !== '',
            'saved' => ['runId' => $savedRun, 'source' => $savedSource],
            'runs' => $runs,
            'sources' => $this->sources(),
            'selected' => ['runId' => $runId, 'source' => $source],
            'known' => Patterns::known($source),
            'examples' => $runId > 0 && Patterns::known($source)
                ? $this->resolver->preview($runId, $source)
                : [],
        ]);
    }

    /**
     * The newest run holding at least one mapped id.
     *
     * @param  list<array{id: int, counts: array<string, int>}>  $runs
     * @return array{id: int, counts: array<string, int>}|null
     */
    protected function firstUsable(array $runs): ?array
    {
        foreach ($runs as $run) {
            if (array_sum($run['counts']) > 0) {
                return $run;
            }
        }

        return null;
    }

    /**
     * Runs that could drive a redirect, newest first.
     *
     * 🚨 Carries the map counts, because a run with an empty map is the one
     * trap here: it is a perfectly valid row that would send every old address
     * straight back to the 404 it came from. The wizard needs to be able to say
     * so rather than offer it.
     *
     * @return list<array{id: int, source: string, status: string, created_at: ?string, counts: array<string, int>}>
     */
    protected function runs(): array
    {
        // Not syncs: they write into the map of the import they continue.
        $rows = Dst::db()->table('importer_runs')->whereNull('base_run_id')->orderByDesc('id')->get();

        $counts = [];

        // Counted in the database: the map holds a row per imported post.
        foreach (Dst::db()->table('importer_map')->whereIn('kind', ['topic', 'tag', 'user'])
            ->groupBy('run_id', 'kind')->selectRaw('run_id, kind, COUNT(*) n')->get() as $m) {
            $counts[(int) $m->run_id][(string) $m->kind] = (int) $m->n;
        }

        $out = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $mine = $counts[$id] ?? [];

            $out[] = [
                'id' => $id,
                'source' => (string) $row->source,
                'status' => (string) $row->status,
                'created_at' => $row->created_at ? (string) $row->created_at : null,
                'counts' => [
                    'topic' => (int) ($mine['topic'] ?? 0),
                    'tag' => (int) ($mine['tag'] ?? 0),
                    'user' => (int) ($mine['user'] ?? 0),
                ],
            ];
        }

        return $out;
    }

    /**
     * The platforms a redirect can be set up for.
     *
     * @return list<array{key: string, label: string}>
     */
    protected function sources(): array
    {
        $catalog = Registry::catalog();
        $out = [];

        foreach (array_keys(Patterns::all()) as $key) {
            $out[] = [
                'key' => $key,
                // vbulletin5 is in the importer map but not the catalog, having
                // no wizard entry of its own — it is reached by delegation.
                'label' => (string) ($catalog[$key]['label'] ?? $key),
            ];
        }

        return $out;
    }
}
