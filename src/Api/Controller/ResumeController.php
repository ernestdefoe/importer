<?php

namespace ErnestDefoe\Importer\Api\Controller;

use ErnestDefoe\Importer\Importers\Src;
use ErnestDefoe\Importer\Runner;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Carry on with a failed run from the step that failed (the database password again if it needs one). */
class ResumeController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = (array) $request->getParsedBody();

        try {
            return new JsonResponse(Runner::resume((int) Arr::get($body, 'runId', 0), ['password' => (string) Arr::get($body, 'password', '')]));
        } catch (\RuntimeException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
