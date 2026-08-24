<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
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

            if (strlen($identity) > 255 || strpbrk($identity, "\r\n") !== false) {
                throw new InvalidArgumentException(
                    'The resolved client identity must be at most 255 bytes and contain no line breaks.'
                );
            }

            return $this->withHeaders([BfcHeaders::CLIENT_ID => $identity]);
        });
    }
}
