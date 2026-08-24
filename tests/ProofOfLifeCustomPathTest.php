<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Tests;

use Illuminate\Foundation\Application;

/**
 * A PHPUnit class rather than a Pest closure: the route path is read while
 * the application boots, so the config must be in place before boot via
 * defineEnvironment() — which needs its own TestCase subclass.
 */
final class ProofOfLifeCustomPathTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // mergeConfigFrom() is not recursive, so override the whole array.
        $app['config']->set('bfc-client.proof_of_life', [
            'enabled' => true,
            'path' => 'internal/bfc-ping',
        ]);
    }

    public function test_the_route_responds_at_the_custom_path_instead_of_the_default(): void
    {
        config()->set('bfc-client.identity', 'custom-path-identity');

        $this->getJson('/internal/bfc-ping')
            ->assertOk()
            ->assertExactJson([
                'package' => 'artisan-build/bfc-client',
                'client_id' => 'custom-path-identity',
            ]);

        $this->getJson('/bfc-client')->assertNotFound();
    }
}
