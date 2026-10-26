<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use ErnestDefoe\Importer\Tests\integration\MessagesFixture;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NodeBB chats, read from a real Redis store (database 15 of a local server;
 * skipped where there is none, or where that database is in use).
 */
class NodebbMessagesTest extends TestCase
{
    use MessagesFixture;

    private const T = 1600000000;
    private const DB = 15;

    private ?\Redis $redis = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-messages', 'ernestdefoe-group-messages', 'ernestdefoe-importer', 'flarum-markdown');
        $this->prepareDatabase(['users' => $this->members()]);

        if (! class_exists(\Redis::class)) {
            $this->markTestSkipped('No phpredis.');
        }
        $r = new \Redis;
        try {
            $usable = @$r->connect('127.0.0.1', 6379, 1.0) && $r->select(self::DB) && $r->dbSize() === 0;
        } catch (\Throwable) {
            $usable = false; // phpredis throws when nothing is listening
        }
        if (! $usable) {
            $this->markTestSkipped('No empty Redis database '.self::DB.' on 127.0.0.1:6379.');
        }
        $this->redis = $r;
    }

    protected function tearDown(): void
    {
        $this->redis?->flushDB();

        parent::tearDown();
    }

    #[Test]
    public function chats_arrive_as_conversations_with_their_members()
    {
        $r = $this->redis;
        $ms = fn (int $o) => (self::T + $o) * 1000;
        $r->hSet('global', 'nextMid', '7');
        foreach ([1 => [], 2 => ['roomName' => 'Plans'], 3 => [], 4 => [], 5 => ['public' => '1']] as $room => $fields) {
            $r->hMSet("chat:room:{$room}", ['roomId' => (string) $room] + $fields);
        }
        // Who is still in each room: both left room 3; the deleted account left room 4.
        foreach ([1 => [10, 11], 2 => [10, 11, 12], 4 => [10], 5 => [10, 11]] as $room => $uids) {
            foreach ($uids as $uid) {
                $r->zAdd("chat:room:{$room}:uids", self::T, (string) $uid);
            }
        }
        $r->hSet('uid:10:chat:rooms:read', '1', (string) $ms(50));
        foreach ([
            1 => [10, '**Hello** Bob', 0, 1], 2 => [11, 'Hi Ada', 100, 1], 3 => [10, 'Group hello', 200, 2], 4 => [10, 'Gone', 300, 3],
            5 => [99, 'From the departed', 400, 4], 6 => [10, 'In a public channel', 500, 5],
        ] as $mid => [$uid, $text, $o, $room]) {
            $r->hMSet("message:{$mid}", ['mid' => (string) $mid, 'fromuid' => (string) $uid, 'content' => $text, 'timestamp' => (string) $ms($o), 'roomId' => (string) $room]);
        }
        $r->hMSet('message:7', ['fromuid' => '10', 'content' => 'Deleted', 'timestamp' => (string) $ms(600), 'roomId' => '1', 'deleted' => '1']);

        $summary = $this->runMessages('nodebb', ['host' => '127.0.0.1', 'port' => '6379', 'database' => (string) self::DB]);

        $this->assertSame(['Hello Bob', 'Hi Ada'], $this->texts('direct', [3, 4]));
        $this->assertSame(['Group hello'], $this->texts('group', [3, 4, 5]));
        $this->assertSame(['From the departed'], $this->texts('direct', [3]));
        $this->assertStringContainsString('<STRONG>', (string) $this->database()->table('dialog_messages')->where('content', 'like', '%Hello%')->value('content'), 'NodeBB chats are Markdown');
        $this->assertSame(3, $summary['conversations']);
        $this->assertSame(4, $summary['messages']);

        // Ada had read up to just after her own message: Bob's reply is unread.
        $db = $this->database();
        $direct = $db->table('dialogs')->where('type', 'direct')->orderBy('id')->first();
        $first = (int) $db->table('dialog_messages')->where('dialog_id', $direct->id)->where('number', 1)->value('id');
        $this->assertSame($first, (int) $db->table('dialog_user')->where('dialog_id', $direct->id)->where('user_id', 3)->value('last_read_message_id'));
    }
}
