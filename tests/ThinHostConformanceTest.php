<?php

declare(strict_types=1);

use ArtisanBuild\BfcClient\BfcClientServiceProvider;
use ArtisanBuild\BfcClient\ClientIdentity;
use ArtisanBuild\BfcClient\Install\InstallFiles;
use ArtisanBuild\BuiltForCloud\BuiltForCloudServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\View\FileViewFinder;

it('boots only the declared client package surface', function (): void {
    $providers = array_keys(array_filter(
        $this->app->getLoadedProviders(),
        static fn (string $provider): bool => str_starts_with($provider, 'ArtisanBuild\\'),
        ARRAY_FILTER_USE_KEY,
    ));
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn ($route): bool => str_starts_with((string) $route->getName(), 'bfc'))
        ->map(static fn ($route): array => [$route->methods(), $route->uri(), $route->getName(), $route->middleware()])
        ->values()
        ->all();
    $commands = array_keys(Artisan::all());
    $migrationPaths = $this->app->make(Migrator::class)->paths();
    $auth = config('auth');
    $views = $this->app->make('view.finder');
    $composer = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($providers)->toBe([BfcClientServiceProvider::class])
        ->and($this->app->bound(ClientIdentity::class))->toBeTrue()
        ->and($this->app->make(ClientIdentity::class))->toBe($this->app->make(ClientIdentity::class))
        ->and(Factory::hasMacro('withClientIdentity'))->toBeTrue()
        ->and(RateLimiter::limiter('bfc-client'))->toBeInstanceOf(Closure::class)
        ->and((RateLimiter::limiter('bfc-client'))(request()))->toBeInstanceOf(Limit::class)
        ->and(array_keys((array) config('bfc-client')))->toBe(['identity', 'proof_of_life'])
        ->and($routes)->toBe([[['GET', 'HEAD'], 'bfc-client', 'bfc-client.proof-of-life', ['throttle:bfc-client']]])
        ->and(array_values(array_filter($commands, static fn (string $command): bool => str_starts_with($command, 'bfc'))))->toBe([])
        ->and(array_values(array_filter($migrationPaths, static fn (string $path): bool => str_contains($path, 'BfcClient'))))->toBe([])
        ->and(array_keys(is_array($auth['guards'] ?? null) ? $auth['guards'] : []))->not->toContain('bfc')
        ->and(array_keys(is_array($auth['guards'] ?? null) ? $auth['guards'] : []))->not->toContain('bfc-console')
        ->and($views)->toBeInstanceOf(FileViewFinder::class)
        ->and($views->getHints())->not->toHaveKeys(['bfc', 'bfc-client'])
        ->and($composer['require'])->not->toHaveKey('artisan-build/built-for-cloud')
        ->and($composer['require-dev']['artisan-build/built-for-cloud'])->toBe('^0.12.2')
        ->and($composer['extra']['laravel'])->not->toHaveKey('dont-discover')
        ->and($this->app->getProviders(BuiltForCloudServiceProvider::class))->toBe([]);
});

it('does not suppress a co-installed package from Laravel discovery', function (): void {
    $files = new Filesystem;
    $base = storage_path('framework/testing/bfc-client-manifest-'.bin2hex(random_bytes(8)));
    $vendor = $base.'/vendor';
    $manifestPath = $base.'/bootstrap/cache/packages.php';

    try {
        $files->ensureDirectoryExists($vendor.'/composer');
        $files->ensureDirectoryExists(dirname($manifestPath));

        $clientMetadata = json_decode($files->get(dirname(__DIR__).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $serverMetadata = json_decode($files->get(dirname(__DIR__).'/vendor/artisan-build/built-for-cloud/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $files->put($base.'/composer.json', json_encode($clientMetadata, JSON_THROW_ON_ERROR));
        $files->put($vendor.'/composer/installed.json', json_encode([
            'packages' => [$clientMetadata, $serverMetadata],
        ], JSON_THROW_ON_ERROR));

        $manifest = new PackageManifest($files, $base, $manifestPath);
        $manifest->vendorPath = $vendor;
        $manifest->build();

        expect($manifest->providers())->toBe([
            BfcClientServiceProvider::class,
            BuiltForCloudServiceProvider::class,
        ]);
    } finally {
        $files->deleteDirectory($base);
    }
});

it('uses the install helper without a console command or application container', function (): void {
    $reflection = new ReflectionClass(InstallFiles::class);

    expect($reflection->getParentClass())->toBeFalse()
        ->and($reflection->getConstructor())->toBeNull()
        ->and($reflection->getInterfaceNames())->toBe([]);
});
