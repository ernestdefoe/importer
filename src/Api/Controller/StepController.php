<?php

namespace ErnestDefoe\Importer\Api\Controller;

use ErnestDefoe\Importer\Runner;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Process ONE bounded batch of a running import. The admin page calls this in a loop. */
class StepController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $runId = (int) Arr::get((array) $request->getParsedBody(), 'runId', 0);
        if (! $runId) {
            return new JsonResponse(['error' => 'Missing run id.'], 422);
        }

        try {
            return new JsonResponse(Runner::step($runId));
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
    }
}
