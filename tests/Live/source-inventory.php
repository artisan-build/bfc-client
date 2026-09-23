<?php

declare(strict_types=1);

use ArtisanBuild\BfcClient\ClientIdentity;
use Composer\InstalledVersions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\View\FileViewFinder;

/** @return list<string> */
function b1InventoryFiles(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $entry) {
        if ($entry instanceof SplFileInfo && $entry->isFile()) {
            $files[] = substr($entry->getPathname(), strlen($directory) + 1);
        }
    }

    sort($files);

    return $files;
}

function b1ListenerName(mixed $listener): string
{
    if (is_string($listener)) {
        return $listener;
    }

    if (is_array($listener) && isset($listener[0])) {
        return is_object($listener[0]) ? $listener[0]::class : (string) $listener[0];
    }

    return is_object($listener) ? $listener::class : get_debug_type($listener);
}

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$applicationProviders = array_keys(array_filter(
    $app->getLoadedProviders(),
    static fn (string $provider): bool => str_starts_with($provider, 'App\\')
        || str_starts_with($provider, 'ArtisanBuild\\'),
    ARRAY_FILTER_USE_KEY,
));
sort($applicationProviders);
$providers = array_values(array_filter(
    $applicationProviders,
    static fn (string $provider): bool => str_starts_with($provider, 'ArtisanBuild\\'),
));
$routes = collect(Route::getRoutes()->getRoutes())
    ->filter(static fn ($route): bool => str_starts_with((string) $route->getName(), 'bfc'))
    ->map(static fn ($route): array => [$route->methods(), $route->uri(), $route->getName(), $route->middleware()])
    ->values()
    ->all();
$routeInventory = collect(Route::getRoutes()->getRoutes())
    ->map(static fn ($route): array => [
        'methods' => $route->methods(),
        'uri' => $route->uri(),
        'name' => $route->getName(),
        'middleware' => $route->middleware(),
    ])
    ->values()
    ->all();
usort($routeInventory, static fn (array $left, array $right): int => json_encode($left, JSON_THROW_ON_ERROR)
    <=> json_encode($right, JSON_THROW_ON_ERROR));
$commands = [];
foreach (Artisan::all() as $name => $command) {
    if (str_starts_with($name, 'bfc') || str_starts_with($command::class, 'ArtisanBuild\\')) {
        $commands[] = [$name, $command::class];
    }
}
sort($commands);
$migrationPaths = array_values(array_filter(
    $app->make(Migrator::class)->paths(),
    static fn (string $path): bool => str_contains(strtolower($path), 'artisan-build'),
));
$artisanDependencies = array_values(array_filter(
    InstalledVersions::getInstalledPackages(),
    static fn (string $package): bool => str_starts_with($package, 'artisan-build/'),
));
sort($artisanDependencies);
$viewFinder = $app->make('view')->getFinder();
$packageViewHints = [];
if ($viewFinder instanceof FileViewFinder) {
    foreach ($viewFinder->getHints() as $namespace => $paths) {
        if (str_starts_with($namespace, 'bfc')
            || array_filter($paths, static fn (string $path): bool => str_contains($path, '/artisan-build/')) !== []) {
            $packageViewHints[$namespace] = $paths;
        }
    }
}
$registeredListeners = [];
foreach ($app->make(Dispatcher::class)->getRawListeners() as $event => $listeners) {
    foreach ((array) $listeners as $listener) {
        $listenerName = b1ListenerName($listener);
        if (str_starts_with($event, 'App\\')
            || str_starts_with($event, 'ArtisanBuild\\')
            || str_starts_with($listenerName, 'App\\')
            || str_starts_with($listenerName, 'ArtisanBuild\\')) {
            $registeredListeners[] = [$event, $listenerName];
        }
    }
}
sort($registeredListeners);
$scheduledTasks = array_map(
    static fn ($event): string => $event::class,
    $app->make(Schedule::class)->events(),
);

fwrite(STDOUT, json_encode([
    'providers' => $providers,
    'application_providers' => $applicationProviders,
    'provider_files' => b1InventoryFiles(__DIR__.'/app/Providers'),
    'routes' => $routes,
    'route_inventory' => $routeInventory,
    'commands' => $commands,
    'command_files' => b1InventoryFiles(__DIR__.'/app/Console/Commands'),
    'migration_paths' => $migrationPaths,
    'migration_files' => b1InventoryFiles(__DIR__.'/database/migrations'),
    'guards' => array_keys((array) config('auth.guards', [])),
    'model_files' => b1InventoryFiles(__DIR__.'/app/Models'),
    'factory_files' => b1InventoryFiles(__DIR__.'/database/factories'),
    'controller_files' => b1InventoryFiles(__DIR__.'/app/Http/Controllers'),
    'view_files' => b1InventoryFiles(__DIR__.'/resources/views'),
    'package_view_hints' => $packageViewHints,
    'event_files' => b1InventoryFiles(__DIR__.'/app/Events'),
    'listener_files' => b1InventoryFiles(__DIR__.'/app/Listeners'),
    'registered_listeners' => $registeredListeners,
    'scheduled_tasks' => $scheduledTasks,
    'artisan_dependencies' => $artisanDependencies,
    'identity_bound' => $app->bound(ClientIdentity::class),
    'macro_registered' => Factory::hasMacro('withClientIdentity'),
    'bfc_client_version' => InstalledVersions::getPrettyVersion('artisan-build/bfc-client'),
    'server_package_installed' => InstalledVersions::isInstalled('artisan-build/built-for-cloud'),
], JSON_THROW_ON_ERROR));
