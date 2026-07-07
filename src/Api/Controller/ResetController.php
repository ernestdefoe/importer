<?php

namespace ErnestDefoe\Importer\Api\Controller;

use ErnestDefoe\Importer\Runner;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Clear a run (and its id-maps) so the wizard resets. */
class ResetController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $runId = (int) Arr::get((array) $request->getParsedBody(), 'runId', 0);
        if ($runId) {
            Runner::reset($runId);
        }

        return new JsonResponse(['ok' => true]);
    }
}
