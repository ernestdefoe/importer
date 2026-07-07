<?php

namespace ErnestDefoe\Importer\Api\Controller;

use ErnestDefoe\Importer\Importers\Registry;
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

        if (! Registry::get($source)) {
            return new JsonResponse(['error' => 'Unknown import source.'], 422);
        }

        try {
            return new JsonResponse(Runner::start($source, $cfg));
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
    }
}
