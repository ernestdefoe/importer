<?php

namespace ErnestDefoe\Importer\Api\Controller;

use ErnestDefoe\Importer\Runner;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Current progress. With no run id, returns the newest run so the page can resume one in progress. */
class StatusController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $runId = (int) Arr::get($request->getQueryParams(), 'runId', 0);
        $res = $runId ? Runner::status($runId) : (Runner::latest() ?? ['runId' => null, 'running' => false, 'percent' => 0, 'status' => null, 'summary' => []]);

        return new JsonResponse($res);
    }
}
