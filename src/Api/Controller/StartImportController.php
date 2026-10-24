<?php

namespace ErnestDefoe\Importer\Api\Controller;

use ErnestDefoe\Importer\Importers\Registry;
use ErnestDefoe\Importer\Importers\Src;
use ErnestDefoe\Importer\Runner;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Create an import run + prime the state machine. The admin page then drives it with step(). */
class StartImportController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = (array) $request->getParsedBody();
        $source = (string) Arr::get($body, 'source', '');
        $cfg = (array) Arr::get($body, 'config', []);

        if (Arr::get($body, 'sync')) {
            // Continues the last finished import; its source is not the caller's to choose.
            try {
                return new JsonResponse(Runner::sync(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $cfg)));
            } catch (\Throwable $e) {
                return new JsonResponse(['error' => Src::explain($e)], 422);
            }
        }

        if (! Registry::get($source)) {
            return new JsonResponse(['error' => Src::t('unknown_source')], 422);
        }

        try {
            return new JsonResponse(Runner::start($source, $cfg));
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
    }
}
