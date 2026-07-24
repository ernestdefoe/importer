<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * Loads a MySQL dump (`mysqldump` .sql, optionally gzipped) into a scratch
 * SQLite database. Migrations frequently arrive as a database FILE (managed
 * hosts that only hand you a dump) rather than a live connection — once in
 * SQLite, every importer works unchanged (Src supports the sqlite driver).
 *
 * Scope: the common mysqldump shape (CREATE TABLE + extended INSERT). Only the
 * data matters, so keys/constraints are dropped and column types collapse to
 * SQLite affinities. MySQL string escapes (\' \\ \n …) are re-escaped to SQLite
 * form so text/quotes/newlines survive intact. Ported from the Convoro suite.
 *
 * Known limitations — this is a pragmatic tokenizer, not a full MySQL parser:
 *  • Stored routines/triggers using DELIMITER blocks are ignored (harmless: we
 *    only execute CREATE TABLE and INSERT INTO).
 *  • Conditional comments (`/*!40014 … *\/`) are skipped wholesale, so any
 *    statement hidden inside one is not applied.
 *  • Charset introducers (`_binary'…'`, `_utf8mb4'…'`) are stripped; the literal
 *    itself is imported as text.
 *  • Hex/bit literals (`0x…`, `b'…'`) pass through untranslated. SQLite reads
 *    `0x…` as an integer, so binary columns dumped in hex may not round-trip.
 * A row-batch that fails to apply is skipped rather than aborting the run, and
 * the count is returned as `skipped` so callers can warn about partial data.
 */
class MysqlDumpToSqlite
{
    /** Runtime connection name for the scratch SQLite file. */
    public const CONN = 'importer_dump';

    /** @return array{tables:int, rows:int, skipped:int} */
    public static function convert(string $dumpPath, string $sqlitePath): array
    {
        $in = self::open($dumpPath);
        if (! $in) {
            throw new \RuntimeException('Could not open the uploaded dump.');
        }

        @unlink($sqlitePath);
        // Laravel's SQLite connector refuses to open a path that doesn't exist
        // (unlike a bare PDO DSN, which creates it), so seed an empty file first.
        if (@touch($sqlitePath) === false) {
            throw new \RuntimeException('Could not create a scratch database at ' . $sqlitePath);
        }

        // Go through Flarum's DatabaseManager rather than a bare PDO handle, so
        // the connection is managed (and can be purged/closed) like every other.
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = resolve('config');
        $config->set('database.connections.' . self::CONN, [
            'driver' => 'sqlite',
            'database' => $sqlitePath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        /** @var \Illuminate\Database\DatabaseManager $manager */
        $manager = resolve('db');
        $manager->purge(self::CONN);
        $conn = $manager->connection(self::CONN);
        $conn->disableQueryLog(); // a big dump would otherwise balloon memory

        $conn->unprepared('PRAGMA journal_mode=OFF');
        $conn->unprepared('PRAGMA synchronous=OFF');

        $tables = 0;
        $rows = 0;
        $skipped = 0;
        $conn->beginTransaction();
        try {
            foreach (self::statements($in) as $stmt) {
                $head = ltrim($stmt);
                if (stripos($head, 'CREATE TABLE') === 0) {
                    if ($sql = self::translateCreate($stmt)) {
                        $conn->unprepared('DROP TABLE IF EXISTS ' . self::quoteIdent(self::tableOf($stmt)));
                        $conn->unprepared($sql);
                        $tables++;
                    }
                } elseif (stripos($head, 'INSERT INTO') === 0) {
                    try {
                        $conn->unprepared(self::translateInsert($stmt));
                        $rows++;
                    } catch (\Throwable) {
                        // skip a malformed row-batch rather than abort the whole import
                        $skipped++;
                    }
                }
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        } finally {
            if (is_resource($in)) {
                gzclose($in);
            }
            // Release the file handle so the scratch DB can be reopened/removed.
            $manager->purge(self::CONN);
        }

        return ['tables' => $tables, 'rows' => $rows, 'skipped' => $skipped];
    }

    /** gz-aware open (gzopen reads plain files transparently too). */
    private static function open(string $path)
    {
        return gzopen($path, 'rb');
    }

    /** Yield top-level SQL statements, respecting strings/identifiers/comments. */
    private static function statements($fh): \Generator
    {
        $buf = '';
        $inStr = false;
        $inTick = false;
        $esc = false;

        while (($line = gzgets($fh)) !== false) {
            if ($buf === '') {
                $l = ltrim($line);
                if ($l === '' || str_starts_with($l, '--') || str_starts_with($l, '#')) {
                    continue;
                }
                if (str_starts_with($l, '/*')) {
                    while (strpos($line, '*/') === false && ($line = gzgets($fh)) !== false) {
                    }

                    continue;
                }
            }

            $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $ch = $line[$i];
                $buf .= $ch;
                if ($esc) {
                    $esc = false;

                    continue;
                }
                if ($inStr) {
                    if ($ch === '\\') {
                        $esc = true;
                    } elseif ($ch === "'") {
                        $inStr = false;
                    }

                    continue;
                }
                if ($inTick) {
                    if ($ch === '`') {
                        $inTick = false;
                    }

                    continue;
                }
                if ($ch === "'") {
                    $inStr = true;
                } elseif ($ch === '`') {
                    $inTick = true;
                } elseif ($ch === ';') {
                    $s = trim($buf);
                    if ($s !== '') {
                        yield $s;
                    }
                    $buf = '';
                }
            }
        }
        if (trim($buf) !== '') {
            yield trim($buf);
        }
    }

    private static function tableOf(string $stmt): string
    {
        return preg_match('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?([^`\s(]+)`?/i', $stmt, $m) ? $m[1] : '';
    }

    private static function quoteIdent(string $name): string
    {
        return '`' . str_replace('`', '', $name) . '`';
    }

    /** mysqldump CREATE TABLE → minimal SQLite CREATE (columns + affinity only). */
    private static function translateCreate(string $stmt): ?string
    {
        $table = self::tableOf($stmt);
        if ($table === '') {
            return null;
        }
        $open = strpos($stmt, '(');
        if ($open === false) {
            return null;
        }
        $body = self::balanced($stmt, $open);
        if ($body === null) {
            return null;
        }

        $cols = [];
        foreach (self::splitTop($body) as $part) {
            $part = trim($part);
            if ($part === '' || ! str_starts_with($part, '`')) {
                continue;
            }
            $end = strpos($part, '`', 1);
            if ($end === false) {
                continue;
            }
            $name = substr($part, 1, $end - 1);
            $rest = strtolower(substr($part, $end + 1));
            $cols[] = self::quoteIdent($name) . ' ' . self::affinity($rest);
        }
        if (! $cols) {
            return null;
        }

        return 'CREATE TABLE ' . self::quoteIdent($table) . ' (' . implode(', ', $cols) . ')';
    }

    private static function affinity(string $typePart): string
    {
        return match (true) {
            str_contains($typePart, 'int') => 'INTEGER',
            preg_match('/\b(dec|numeric|float|double|real)/', $typePart) === 1 => 'REAL',
            preg_match('/\b(blob|binary)/', $typePart) === 1 => 'BLOB',
            default => 'TEXT',
        };
    }

    /** Re-escape a mysqldump INSERT's string literals to SQLite form. */
    private static function translateInsert(string $stmt): string
    {
        $out = '';
        $len = strlen($stmt);
        $inStr = false;
        $inTick = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $stmt[$i];
            if ($inTick) {
                $out .= $ch;
                if ($ch === '`') {
                    $inTick = false;
                }

                continue;
            }
            if (! $inStr) {
                if ($ch === '`') {
                    $inTick = true;
                    $out .= $ch;
                } elseif ($ch === "'") {
                    $inStr = true;
                    $out .= "'";
                } elseif ($ch === '_' && preg_match('/\G_[a-zA-Z0-9]+(?=\')/', $stmt, $m, 0, $i)) {
                    // Charset introducer (_binary'…', _utf8mb4'…'). SQLite has no
                    // equivalent and would choke, so drop it and keep the literal.
                    $i += strlen($m[0]) - 1;
                } else {
                    $out .= $ch;
                }

                continue;
            }
            if ($ch === '\\' && $i + 1 < $len) {
                $n = $stmt[++$i];
                $out .= match ($n) {
                    "'" => "''",
                    '\\' => '\\',
                    '"' => '"',
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '0' => "\0",
                    'b' => "\x08",
                    'Z' => "\x1a",
                    default => $n,
                };
            } elseif ($ch === "'") {
                if ($i + 1 < $len && $stmt[$i + 1] === "'") {
                    $out .= "''";
                    $i++;
                } else {
                    $out .= "'";
                    $inStr = false;
                }
            } else {
                $out .= $ch;
            }
        }

        return $out;
    }

    private static function balanced(string $s, int $open): ?string
    {
        $depth = 0;
        $inStr = false;
        $inTick = false;
        $esc = false;
        $start = $open + 1;
        for ($i = $open, $len = strlen($s); $i < $len; $i++) {
            $ch = $s[$i];
            if ($esc) {
                $esc = false;

                continue;
            }
            if ($inStr) {
                if ($ch === '\\') {
                    $esc = true;
                } elseif ($ch === "'") {
                    $inStr = false;
                }

                continue;
            }
            if ($inTick) {
                if ($ch === '`') {
                    $inTick = false;
                }

                continue;
            }
            if ($ch === "'") {
                $inStr = true;
            } elseif ($ch === '`') {
                $inTick = true;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                if (--$depth === 0) {
                    return substr($s, $start, $i - $start);
                }
            }
        }

        return null;
    }

    private static function splitTop(string $s): array
    {
        $parts = [];
        $cur = '';
        $depth = 0;
        $inStr = false;
        $inTick = false;
        $esc = false;
        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            $ch = $s[$i];
            if ($esc) {
                $cur .= $ch;
                $esc = false;

                continue;
            }
            if ($inStr) {
                $cur .= $ch;
                if ($ch === '\\') {
                    $esc = true;
                } elseif ($ch === "'") {
                    $inStr = false;
                }

                continue;
            }
            if ($inTick) {
                $cur .= $ch;
                if ($ch === '`') {
                    $inTick = false;
                }

                continue;
            }
            if ($ch === "'") {
                $inStr = true;
                $cur .= $ch;
            } elseif ($ch === '`') {
                $inTick = true;
                $cur .= $ch;
            } elseif ($ch === '(') {
                $depth++;
                $cur .= $ch;
            } elseif ($ch === ')') {
                $depth--;
                $cur .= $ch;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = $cur;
                $cur = '';
            } else {
                $cur .= $ch;
            }
        }
        if (trim($cur) !== '') {
            $parts[] = $cur;
        }

        return $parts;
    }
}
