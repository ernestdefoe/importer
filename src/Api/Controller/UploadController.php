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
    /**
     * Hard cap on an accepted dump, so a stray multi-gigabyte upload can't fill
     * the disk before sweep() gets a chance to clean up. Generous enough for a
     * large forum's gzipped dump.
     */
    private const MAX_BYTES = 500 * 1024 * 1024;

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

        $size = (int) $file->getSize();
        if ($size > self::MAX_BYTES) {
            return new JsonResponse([
                'ok' => false,
                'error' => sprintf(
                    'That file is %s — the limit is %s. Gzip the dump (.sql.gz) or import from a live database connection instead.',
                    self::humanBytes($size),
                    self::humanBytes(self::MAX_BYTES)
                ),
            ], 422);
        }

        if ($why = self::rejectContent($file)) {
            return new JsonResponse(['ok' => false, 'error' => $why], 422);
        }

        Upload::sweep();
        $dir = Upload::dir();
        $ext = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        $sqliteName = 'scratch-' . bin2hex(random_bytes(6)) . '.sqlite';
        $sqlitePath = $dir . '/' . $sqliteName;

        $conversion = null;
        try {
            if (in_array($ext, ['sqlite', 'sqlite3', 'db'], true)) {
                $file->moveTo($sqlitePath); // already a SQLite database
            } else {
                $tmp = $dir . '/dump-' . bin2hex(random_bytes(6)) . ($ext === 'gz' ? '.sql.gz' : '.sql');
                $file->moveTo($tmp);
                $conversion = MysqlDumpToSqlite::convert($tmp, $sqlitePath);
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

        // A dump can parse "successfully" while individual row-batches were
        // unusable — say so rather than letting the admin discover missing
        // content after the import finishes.
        if ($conversion && ($conversion['skipped'] ?? 0) > 0) {
            $res['warning'] = sprintf(
                '%d of %d row batches in this dump could not be read and were skipped, so some content may be missing. Importing from a live database connection avoids this.',
                $conversion['skipped'],
                $conversion['skipped'] + $conversion['rows']
            );
        }

        return new JsonResponse($res + ['handle' => $sqliteName]);
    }

    /**
     * Peek at the first bytes to confirm this looks like a SQLite database, a
     * gzip stream, or SQL text — so an obviously wrong file (a photo, a zip, a
     * PDF) is rejected before we write it to disk and try to parse it.
     * Returns an error message, or null when the file looks acceptable.
     */
    private static function rejectContent($file): ?string
    {
        try {
            $stream = $file->getStream();
            $stream->rewind();
            $head = $stream->read(512);
            $stream->rewind();
        } catch (\Throwable) {
            return null; // can't inspect it — let the parser be the judge
        }

        if ($head === '') {
            return 'That file is empty.';
        }
        // SQLite database, or gzip (a .sql.gz dump).
        if (str_starts_with($head, "SQLite format 3\0") || str_starts_with($head, "\x1f\x8b")) {
            return null;
        }
        // Plain SQL: dumps open with comments, directives or statements. Binary
        // content is the giveaway for everything we can't use.
        if (preg_match('/^\s*(--|#|\/\*|SET\b|\/\*!|CREATE\b|INSERT\b|DROP\b|USE\b|START\b|LOCK\b|BEGIN\b)/i', $head)) {
            return null;
        }
        if (str_contains($head, "\0")) {
            return 'That file doesn\'t look like a database dump — it appears to be binary. Upload a .sql, .sql.gz or SQLite file.';
        }

        return null; // text of some other shape — let the parser decide
    }

    private static function humanBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024 * 1024
            ? round($bytes / 1024 / 1024 / 1024, 1) . ' GB'
            : round($bytes / 1024 / 1024) . ' MB';
    }
}
