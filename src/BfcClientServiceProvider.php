<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

use Illuminate\Support\ServiceProvider;

final class BfcClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bfc-client.php', 'bfc-client');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/bfc-client.php' => config_path('bfc-client.php'),
        ], 'bfc-client-config');
    }
}
