<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

/**
 * A PHPUnit class rather than a Pest closure: the route registers (or not)
 * while the application boots, so the config must be in place before boot
 * via defineEnvironment() — which needs its own TestCase subclass.
 */
final class ProofOfLifeDisabledTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // mergeConfigFrom() is not recursive, so override the whole array.
        $app['config']->set('bfc-client.proof_of_life', [
            'enabled' => false,
            'path' => 'bfc-client',
        ]);
    }

    public function test_the_route_is_not_registered_when_proof_of_life_is_disabled(): void
    {
        $this->assertFalse(Route::has('bfc-client.proof-of-life'));

        $this->getJson('/bfc-client')->assertNotFound();
    }
}
