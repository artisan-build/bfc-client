<?php

declare(strict_types=1);

use ArtisanBuild\BfcClient\ClientIdentity;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

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

it('returns an existing persisted identity byte-exact without regenerating it', function () {
    $files = new Filesystem;
    $files->ensureDirectoryExists(storage_path('app/bfc-client'), 0755);
    $files->put(storage_path('app/bfc-client/identity'), "seeded-identity-value\n");

    expect($this->app->make(ClientIdentity::class)->resolve())->toBe("seeded-identity-value\n")
        ->and((string) file_get_contents(storage_path('app/bfc-client/identity')))
        ->toBe("seeded-identity-value\n");
});

it('throws when the identity cannot be persisted', function () {
    $files = new Filesystem;
    $files->ensureDirectoryExists(storage_path('app/bfc-client'), 0755);
    $files->put(storage_path('app/bfc-client/identity'), 'existing-file');
    config()->set('bfc-client.identity', null);
    $files->delete(storage_path('app/bfc-client/identity'));
    $files->makeDirectory(storage_path('app/bfc-client/identity'));

    $identity = new ClientIdentity($this->app->make('config'), $files);

    expect(fn () => $identity->resolve())
        ->toThrow(RuntimeException::class, storage_path('app/bfc-client/identity'));
});

it('regenerates only when the persisted identity file is byte-empty', function () {
    $files = new Filesystem;
    $files->ensureDirectoryExists(storage_path('app/bfc-client'), 0755);
    $files->put(storage_path('app/bfc-client/identity'), '');

    $resolved = $this->app->make(ClientIdentity::class)->resolve();

    expect(Str::isUuid($resolved))->toBeTrue()
        ->and(trim((string) file_get_contents(storage_path('app/bfc-client/identity'))))->toBe($resolved);
});

it('converges concurrent first resolvers on one persisted identity without artifacts', function () {
    $run = storage_path('app/bfc-client-race-'.bin2hex(random_bytes(8)));
    $storage = $run.'/host-storage';
    $files = new Filesystem;
    $files->ensureDirectoryExists($run, 0700);
    $processes = [];
    $workers = [];
    $barrier = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

    if ($barrier === false) {
        throw new RuntimeException('Unable to create the identity fixture barrier.');
    }

    $barrierAddress = stream_socket_get_name($barrier, false);

    if (! is_string($barrierAddress)) {
        fclose($barrier);

        throw new RuntimeException('Unable to resolve the identity fixture barrier.');
    }

    try {
        foreach (range(1, 8) as $_worker) {
            $process = new Process([
                PHP_BINARY,
                __DIR__.'/Fixtures/resolve-client-identity.php',
                dirname(__DIR__),
                $storage,
                $barrierAddress,
            ]);
            $process->start();
            $processes[] = $process;
        }

        foreach ($processes as $_process) {
            $worker = stream_socket_accept($barrier, 10);

            if ($worker === false || fgets($worker) !== "ready\n") {
                throw new RuntimeException('An identity fixture worker did not reach the barrier.');
            }

            $workers[] = $worker;
        }

        expect(is_dir($storage.'/app/bfc-client'))->toBeFalse();

        foreach ($workers as $worker) {
            fwrite($worker, "go\n");
            fclose($worker);
        }
        $workers = [];

        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue();
        }

        $resolved = array_map(static fn (Process $process): string => $process->getOutput(), $processes);
        $path = $storage.'/app/bfc-client/identity';
        $persisted = (string) file_get_contents($path);

        expect(array_values(array_unique($resolved)))->toBe([$persisted])
            ->and(Str::isUuid($persisted))->toBeTrue()
            ->and(array_map('basename', glob(dirname($path).'/*') ?: []))->toBe(['identity']);
    } finally {
        foreach ($workers as $worker) {
            fclose($worker);
        }

        fclose($barrier);

        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }

        $files->deleteDirectory($run);
    }
});

it('ignores a non-string config identity and falls through the chain', function () {
    config()->set('bfc-client.identity', 123);

    $resolved = $this->app->make(ClientIdentity::class)->resolve();

    expect(Str::isUuid($resolved))->toBeTrue()
        ->and(trim((string) file_get_contents(storage_path('app/bfc-client/identity'))))->toBe($resolved);
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
