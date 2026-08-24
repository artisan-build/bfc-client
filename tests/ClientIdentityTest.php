<?php

declare(strict_types=1);

use ArtisanBuild\BfcClient\ClientIdentity;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

beforeEach(function () {
    (new Filesystem)->deleteDirectory(storage_path('app/bfc-client'));
});

afterEach(function () {
    (new Filesystem)->deleteDirectory(storage_path('app/bfc-client'));
});

it('is registered as a container singleton', function () {
    expect($this->app->make(ClientIdentity::class))
        ->toBe($this->app->make(ClientIdentity::class));
});

it('returns an explicit config identity verbatim without writing to storage', function () {
    config()->set('bfc-client.identity', 'client-abc-123');

    expect($this->app->make(ClientIdentity::class)->resolve())->toBe('client-abc-123')
        ->and(is_file(storage_path('app/bfc-client/identity')))->toBeFalse()
        ->and(is_dir(storage_path('app/bfc-client')))->toBeFalse();
});

it('generates and persists an identity that a fresh instance resolves identically', function () {
    $first = $this->app->make(ClientIdentity::class)->resolve();

    $path = storage_path('app/bfc-client/identity');

    expect(is_file($path))->toBeTrue()
        ->and(trim((string) file_get_contents($path)))->toBe($first);

    $fresh = new ClientIdentity($this->app->make('config'), new Filesystem);

    expect($fresh->resolve())->toBe($first);
});

it('returns an existing persisted identity as-is without regenerating it', function () {
    $files = new Filesystem;
    $files->ensureDirectoryExists(storage_path('app/bfc-client'), 0755);
    $files->put(storage_path('app/bfc-client/identity'), "seeded-identity-value\n");

    expect($this->app->make(ClientIdentity::class)->resolve())->toBe('seeded-identity-value')
        ->and(trim((string) file_get_contents(storage_path('app/bfc-client/identity'))))
        ->toBe('seeded-identity-value');
});

it('generates valid uuids that differ between clean installs', function () {
    $config = $this->app->make('config');

    $first = (new ClientIdentity($config, new Filesystem))->resolve();

    (new Filesystem)->deleteDirectory(storage_path('app/bfc-client'));

    $second = (new ClientIdentity($config, new Filesystem))->resolve();

    expect(Str::isUuid($first))->toBeTrue()
        ->and(Str::isUuid($second))->toBeTrue()
        ->and($second)->not->toBe($first);
});
