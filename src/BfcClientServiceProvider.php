<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

use ArtisanBuild\BfcClient\Http\Controllers\ProofOfLifeController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class BfcClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bfc-client.php', 'bfc-client');

        $this->app->singleton(ClientIdentity::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/bfc-client.php' => config_path('bfc-client.php'),
        ], 'bfc-client-config');

        Factory::macro('withClientIdentity', function (): PendingRequest {
            /** @var Factory $this */
            // One replacement applies the complete metadata contract so
            // conflicting defaults cannot create duplicate field values.
            return $this->replaceHeaders([
                BfcHeaders::CONTRACT_VERSION => (string) BfcContract::MAJOR,
                BfcHeaders::CLIENT_ID => app(ClientIdentity::class)->validated(),
            ]);
        });

        $this->registerProofOfLifeRoute();
    }

    /**
     * The proof-of-life route is unauthenticated by design: the identity it
     * returns is an identifier, never a secret, and providers poll it
     * without credentials. It is throttled and can be disabled or moved
     * via config.
     *
     * The limiter is named and keyed on the IP rather than inline
     * (`throttle:60,1`): the inline form builds its signature via
     * `$request->user()`, which throws on a headless app with no auth
     * guard at all, 500ing every request to the route.
     */
    private function registerProofOfLifeRoute(): void
    {
        if (! config('bfc-client.proof_of_life.enabled') || ! $this->app->bound('router')) {
            return;
        }

        RateLimiter::for('bfc-client', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip() ?? 'unknown'));

        /** @var Router $router */
        $router = $this->app->make('router');

        $router->get((string) config('bfc-client.proof_of_life.path'), ProofOfLifeController::class)
            ->name('bfc-client.proof-of-life')
            ->middleware('throttle:bfc-client');
    }
}
