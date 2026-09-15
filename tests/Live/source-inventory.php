<?php

declare(strict_types=1);

use ArtisanBuild\BfcClient\ClientIdentity;
use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$providers = array_keys(array_filter(
    $app->getLoadedProviders(),
    static fn (string $provider): bool => str_starts_with($provider, 'ArtisanBuild\\'),
    ARRAY_FILTER_USE_KEY,
));
$routes = collect(Route::getRoutes()->getRoutes())
    ->filter(static fn ($route): bool => str_starts_with((string) $route->getName(), 'bfc'))
    ->map(static fn ($route): array => [$route->methods(), $route->uri(), $route->getName(), $route->middleware()])
    ->values()
    ->all();
$commands = array_values(array_filter(
    array_keys(Artisan::all()),
    static fn (string $command): bool => str_starts_with($command, 'bfc'),
));
$migrationPaths = array_values(array_filter(
    $app->make(Migrator::class)->paths(),
    static fn (string $path): bool => str_contains(strtolower($path), 'artisanbuild'),
));

fwrite(STDOUT, json_encode([
    'providers' => $providers,
    'routes' => $routes,
    'commands' => $commands,
    'migration_paths' => $migrationPaths,
    'guards' => array_keys((array) config('auth.guards', [])),
    'identity_bound' => $app->bound(ClientIdentity::class),
    'macro_registered' => Factory::hasMacro('withClientIdentity'),
    'bfc_client_version' => InstalledVersions::getPrettyVersion('artisan-build/bfc-client'),
    'server_package_installed' => InstalledVersions::isInstalled('artisan-build/built-for-cloud'),
], JSON_THROW_ON_ERROR));
