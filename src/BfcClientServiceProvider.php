<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

use ArtisanBuild\BfcClient\Http\Controllers\ProofOfLifeController;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

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
            $identity = app(ClientIdentity::class)->resolve();

            if (
                ! mb_check_encoding($identity, 'UTF-8')
                || strlen($identity) > 255
                || strpbrk($identity, "\r\n") !== false
            ) {
                throw new InvalidArgumentException(
                    'The resolved client identity must be valid UTF-8, at most 255 bytes, and contain no CR or LF octets.'
                );
            }

            // replaceHeaders, not withHeaders: the validated identity must be
            // the ONLY value on the wire, even when a conflicting header was
            // pre-set via Http::globalOptions().
            return $this->replaceHeaders([BfcHeaders::CLIENT_ID => $identity]);
        });

        $this->registerProofOfLifeRoute();
    }

    /**
     * The proof-of-life route is unauthenticated by design: the identity it
     * returns is an identifier, never a secret, and providers poll it
     * without credentials. It is throttled and can be disabled or moved
     * via config.
     */
    private function registerProofOfLifeRoute(): void
    {
        if (! config('bfc-client.proof_of_life.enabled') || ! $this->app->bound('router')) {
            return;
        }

        /** @var Router $router */
        $router = $this->app->make('router');

        $router->get((string) config('bfc-client.proof_of_life.path'), ProofOfLifeController::class)
            ->name('bfc-client.proof-of-life')
            ->middleware('throttle:60,1');
    }
}
