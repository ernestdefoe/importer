<?php

namespace ErnestDefoe\Importer\Tests\integration;

use ErnestDefoe\Importer\Importers\Ctx;
use ErnestDefoe\Importer\Importers\Dst;
use ErnestDefoe\Importer\Importers\Registry;

/**
 * What the private-message tests share: four members on this forum standing
 * for four on the old board (10 Ada, 11 Bob, 12 Cy, 13 Dee; 99 is an account
 * the old board deleted), and a readable picture of the Messages that landed.
 */
trait MessagesFixture
{
    /** Flarum user id for each source user id. */
    protected array $people = ['10' => 3, '11' => 4, '12' => 5, '13' => 6];

    protected function members(): array
    {
        return array_map(fn ($id, $name) => ['id' => $id, 'username' => $name, 'email' => $name.'@forum.example', 'is_email_confirmed' => 1, 'password' => ''],
            [3, 4, 5, 6], ['ada', 'bob', 'cy', 'dee']);
    }

    /**
     * Run only the private-message phases of a source against the fixture,
     * as a sync of an import that mapped the four members would.
     *
     * @return array<string,int> the summary
     */
    protected function runMessages(string $source, array $cfg, ?int $runId = null): array
    {
        $this->app();
        $db = $this->database();
        if (! $runId) {
            $runId = (int) $db->table('importer_runs')->insertGetId(['source' => $source, 'config' => json_encode($cfg), 'state' => '{}', 'status' => 'done']);
            foreach ($this->people as $src => $target) {
                $db->table('importer_map')->insert(['run_id' => $runId, 'kind' => 'user', 'source_id' => (string) $src, 'target_id' => $target]);
            }
        }
        Dst::reset();
        $ctx = new Ctx($runId, $cfg);
        $summary = [];
        foreach (Registry::phases($source, $cfg, ['sync' => ['only' => ['pms', 'pms-finish']]]) as $phase) {
            $cursor = null;
            for ($i = 0; $i < 100; $i++) {
                $res = ($phase->batch)($cursor, 2, $ctx); // tiny batches: every boundary gets crossed
                foreach ($res['summary'] as $k => $v) {
                    $summary[$k] = ($summary[$k] ?? 0) + $v;
                }
                $cursor = $res['cursor'];
                if ($res['done']) {
                    break;
                }
            }
        }

        return $summary;
    }

    /**
     * Every dialog, as [type, sorted member ids, title, [[author, text, created_at], …] in number order].
     *
     * @return list<array{0:string,1:list<int>,2:?string,3:list<array{0:?int,1:string,2:string}>}>
     */
    protected function dialogs(): array
    {
        $db = $this->database();
        $out = [];
        foreach ($db->table('dialogs')->orderBy('id')->get() as $d) {
            $members = $db->table('dialog_user')->where('dialog_id', $d->id)->orderBy('user_id')->pluck('user_id')->map(fn ($u) => (int) $u)->all();
            $title = $db->getSchemaBuilder()->hasTable('group_dialogs') ? $db->table('group_dialogs')->where('dialog_id', $d->id)->value('title') : null;
            $messages = $db->table('dialog_messages')->where('dialog_id', $d->id)->orderBy('number')->get()
                ->map(fn ($m) => [$m->user_id === null ? null : (int) $m->user_id, trim(strip_tags((string) preg_replace('#<([se])>.*?</\1>#s', '', (string) $m->content))), substr((string) $m->created_at, 0, 19)])->all();
            $out[] = [$d->type, $members, $title, $messages];
        }

        return $out;
    }

    /** The texts of the dialog of a type with exactly these members. */
    protected function texts(string $type, array $members): ?array
    {
        sort($members);
        foreach ($this->dialogs() as [$t, $m, , $messages]) {
            if ($t === $type && $m === $members) {
                return array_map(fn ($x) => $x[1], $messages);
            }
        }

        return null;
    }

    protected function at(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }
}
