<?php

namespace ErnestDefoe\Importer\Api\Controller;

use ErnestDefoe\Importer\Importers\PrivateMessages;
use ErnestDefoe\Importer\Importers\Registry;
use ErnestDefoe\Importer\Importers\Src;
use ErnestDefoe\Importer\Importers\Upload;
use ErnestDefoe\Importer\Markup\Target;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Validate the source connection and return row counts (the wizard's "Test" step). */
class TestConnectionController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = (array) $request->getParsedBody();
        $source = (string) Arr::get($body, 'source', '');
        $cfg = Upload::resolve((array) Arr::get($body, 'config', []));

        $importer = Registry::get($source);
        if (! $importer) {
            return new JsonResponse(['ok' => false, 'error' => Src::t('unknown_source')], 422);
        }

        try {
            $res = $importer::test($cfg);
            // Private messages too, where the source keeps them.
            if (($pms = PrivateMessages::count($importer, $cfg)) !== null && isset($res['counts'])) {
                $res['counts']['messages'] = $pms;
            }
            $target = Target::current();
            $res['editor'] = $target->mode === Target::TEXT ? Src::t('editor_plain') : $target->label();

            return new JsonResponse($res);
        } catch (\Throwable $e) {
            // 200 with ok=false so the wizard shows the message inline.
            return new JsonResponse(['ok' => false, 'error' => Src::explain($e)]);
        }
    }
}
