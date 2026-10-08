<?php

namespace ErnestDefoe\Importer\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Connecting to another database, uploading a dump and rewriting the forum
 * are an admin's alone.
 */
class AdminRoutesTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-importer');
        $this->prepareDatabase(['users' => [$this->normalUser()]]);
    }

    public static function routes(): array
    {
        return [
            'test connection' => ['POST', '/api/importer/test'],
            'upload' => ['POST', '/api/importer/upload'],
            'start' => ['POST', '/api/importer/start'],
            'step' => ['POST', '/api/importer/step'],
            'reset' => ['POST', '/api/importer/reset'],
            'status' => ['GET', '/api/importer/status'],
            'redirect preview' => ['GET', '/api/importer/redirects'],
            'redirect save' => ['POST', '/api/importer/redirects'],
        ];
    }

    #[Test]
    #[DataProvider('routes')]
    public function a_member_is_refused(string $method, string $path)
    {
        $response = $this->send($this->request($method, $path, [
            'authenticatedAs' => 2,
            'json' => $method === 'GET' ? null : ['source' => 'phpbb', 'config' => ['driver' => 'sqlite', 'database' => '/nonexistent'], 'runId' => 1, 'enabled' => true],
        ]));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $this->database()->table('importer_runs')->count());
    }

    #[Test]
    public function an_unknown_source_is_refused()
    {
        $response = $this->send($this->request('POST', '/api/importer/start', ['authenticatedAs' => 1, 'json' => ['source' => 'nonsense']]));

        $this->assertSame(422, $response->getStatusCode());
    }
}
