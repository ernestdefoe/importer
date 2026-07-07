<?php

namespace ErnestDefoe\Importer\Api\Controller;

use ErnestDefoe\Importer\Importers\MysqlDumpToSqlite;
use ErnestDefoe\Importer\Importers\Registry;
use ErnestDefoe\Importer\Importers\Upload;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Accept an uploaded database dump (.sql / .sql.gz) or a SQLite file, load it
 * into a scratch SQLite DB, and validate it against the chosen platform. Returns
 * row counts + a handle the wizard then passes to /importer/start.
 */
class UploadController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = (array) $request->getParsedBody();
        $source = (string) Arr::get($body, 'source', '');
        $importer = Registry::get($source);
        if (! $importer) {
            return new JsonResponse(['ok' => false, 'error' => 'Unknown import source.'], 422);
        }

        $file = Arr::get($request->getUploadedFiles(), 'file');
        if (! $file || $file->getError() !== UPLOAD_ERR_OK) {
            return new JsonResponse(['ok' => false, 'error' => 'No file was uploaded (or it exceeded the server upload limit).'], 422);
        }

        Upload::sweep();
        $dir = Upload::dir();
        $ext = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        $sqliteName = 'scratch-' . bin2hex(random_bytes(6)) . '.sqlite';
        $sqlitePath = $dir . '/' . $sqliteName;

        try {
            if (in_array($ext, ['sqlite', 'sqlite3', 'db'], true)) {
                $file->moveTo($sqlitePath); // already a SQLite database
            } else {
                $tmp = $dir . '/dump-' . bin2hex(random_bytes(6)) . ($ext === 'gz' ? '.sql.gz' : '.sql');
                $file->moveTo($tmp);
                MysqlDumpToSqlite::convert($tmp, $sqlitePath);
                @unlink($tmp);
            }
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => 'Could not read that file: ' . $e->getMessage()], 422);
        }

        $cfg = Upload::resolve(['file' => $sqliteName, 'prefix' => (string) Arr::get($body, 'prefix', '')]);

        try {
            $res = $importer::test($cfg);
        } catch (\Throwable $e) {
            @unlink($sqlitePath);

            return new JsonResponse(['ok' => false, 'error' => "File loaded, but it doesn't look like a {$source} database: " . $e->getMessage()], 422);
        }

        return new JsonResponse($res + ['handle' => $sqliteName]);
    }
}
