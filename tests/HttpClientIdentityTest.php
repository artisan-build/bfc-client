<?php

declare(strict_types=1);

use ArtisanBuild\BfcClient\BfcContract;
use ArtisanBuild\BfcClient\BfcHeaders;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('attaches exactly one client identity and canonical contract major', function () {
    config()->set('bfc-client.identity', 'client-abc-123');

    Http::fake();

    Http::withClientIdentity()->get('https://provider.test/api/ping');

    Http::assertSent(fn (Request $request): bool => $request->header(BfcHeaders::CLIENT_ID) === ['client-abc-123']
        && $request->header(BfcHeaders::CONTRACT_VERSION) === [(string) BfcContract::MAJOR]);
});

it('composes with other pending request options', function () {
    config()->set('bfc-client.identity', 'client-abc-123');

    Http::fake();

    Http::withClientIdentity()->withToken('tok')->get('https://provider.test/api/ping');

    Http::assertSent(fn (Request $request): bool => $request->header(BfcHeaders::CLIENT_ID) === ['client-abc-123']
        && $request->header('Authorization') === ['Bearer tok']);
});

it('resolves the identity lazily at call time, not at boot', function () {
    // The application (and the macro) booted in setUp; only now does the
    // identity become known. The header must reflect this late value.
    config()->set('bfc-client.identity', 'set-after-boot');

    Http::fake();

    Http::withClientIdentity()->get('https://provider.test/api/ping');

    Http::assertSent(fn (Request $request): bool => $request->header(BfcHeaders::CLIENT_ID) === ['set-after-boot']);
});

it('replaces conflicting caller defaults with one canonical value each', function () {
    config()->set('bfc-client.identity', 'client-abc-123');

    Http::globalOptions(['headers' => [
        BfcHeaders::CLIENT_ID => ['spoofed-default', 'spoofed-second'],
        BfcHeaders::CONTRACT_VERSION => ['1', '3'],
    ]]);

    Http::fake();

    Http::withClientIdentity()->get('https://provider.test/api/ping');

    Http::assertSent(fn (Request $request): bool => $request->header(BfcHeaders::CLIENT_ID) === ['client-abc-123']
        && $request->header(BfcHeaders::CONTRACT_VERSION) === [(string) BfcContract::MAJOR]);
});

it('rejects invalid identity bytes before send without reflecting them', function (string $identity) {
    config()->set('bfc-client.identity', $identity);
    Http::fake();

    try {
        Http::withClientIdentity()->post('https://provider.test/api/ping');
        $this->fail('Invalid identity metadata was accepted.');
    } catch (InvalidArgumentException $exception) {
        expect($exception->getMessage())->not->toContain($identity);
    }

    Http::assertNothingSent();
})->with([
    'invalid UTF-8' => "client-\xC3\x28-marker",
    'over 255 bytes' => str_repeat('oversized-marker-', 18),
    'carriage return' => "client-\r-marker",
    'line feed' => "client-\n-marker",
    'NUL' => "client-\0-marker",
]);

it('rejects an identity longer than 255 bytes', function () {
    config()->set('bfc-client.identity', str_repeat('a', 256));

    Http::fake();

    Http::withClientIdentity();
})->throws(InvalidArgumentException::class);

it('rejects an identity containing a line break', function () {
    config()->set('bfc-client.identity', "client\nabc");

    Http::fake();

    Http::withClientIdentity();
})->throws(InvalidArgumentException::class);

it('rejects an identity containing a carriage return', function () {
    config()->set('bfc-client.identity', "client\rabc");

    Http::fake();

    Http::withClientIdentity();
})->throws(InvalidArgumentException::class);

// A NUL is valid UTF-8 and is neither CR nor LF, so it clears every other
// limit; it is rejected on its own account because PostgreSQL truncates a
// stored value at the first NUL, collapsing byte-distinct identities.
it('rejects an identity containing a NUL byte', function () {
    config()->set('bfc-client.identity', "client\0one");

    Http::fake();

    Http::withClientIdentity();
})->throws(InvalidArgumentException::class);

it('rejects an identity that is only a NUL byte', function () {
    config()->set('bfc-client.identity', "\0");

    Http::fake();

    Http::withClientIdentity();
})->throws(InvalidArgumentException::class);

it('rejects an identity that is not valid UTF-8', function () {
    config()->set('bfc-client.identity', "\xC3\x28");

    Http::fake();

    Http::withClientIdentity();
})->throws(InvalidArgumentException::class);

it('accepts an ASCII identity of exactly 255 bytes', function () {
    $identity = str_repeat('a', 255);

    config()->set('bfc-client.identity', $identity);

    Http::fake();

    Http::withClientIdentity()->get('https://provider.test/api/ping');

    Http::assertSent(fn (Request $request): bool => $request->header(BfcHeaders::CLIENT_ID) === [$identity]);
});

it('accepts a multibyte identity of exactly 255 bytes', function () {
    $identity = str_repeat('é', 127).'a';

    expect(strlen($identity))->toBe(255);

    config()->set('bfc-client.identity', $identity);

    Http::fake();

    Http::withClientIdentity()->get('https://provider.test/api/ping');

    Http::assertSent(fn (Request $request): bool => $request->header(BfcHeaders::CLIENT_ID) === [$identity]);
});

it('falls through an empty-string config identity to a generated one', function () {
    $files = new Filesystem;
    $files->deleteDirectory(storage_path('app/bfc-client'));

    config()->set('bfc-client.identity', '');

    Http::fake();

    Http::withClientIdentity()->get('https://provider.test/api/ping');

    Http::assertSent(function (Request $request): bool {
        $sent = $request->header(BfcHeaders::CLIENT_ID);

        return count($sent) === 1 && is_string($sent[0]) && Str::isUuid($sent[0]);
    });

    $files->deleteDirectory(storage_path('app/bfc-client'));
});

it('drives the supported direct-consumer request compositions', function () {
    $credential = 'fixture_'.bin2hex(random_bytes(24));
    config()->set('bfc-client.identity', 'consumer-fixture');
    Http::fake();

    // Hone macro, Sink explicit metadata, and Matte/Crate fluent bearer shapes.
    Http::withClientIdentity()->post('https://provider.test/hone');
    Http::withHeaders([
        BfcHeaders::CLIENT_ID => 'consumer-fixture',
        BfcHeaders::CONTRACT_VERSION => (string) BfcContract::MAJOR,
    ])->post('https://provider.test/sink');
    Http::withClientIdentity()->withToken($credential)->get('https://provider.test/matte');
    Http::withClientIdentity()->withToken($credential)->delete('https://provider.test/crate');

    Http::assertSentCount(4);
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://provider.test/hone'
        && $request->header(BfcHeaders::CONTRACT_VERSION) === ['2']);
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://provider.test/sink'
        && $request->header(BfcHeaders::CLIENT_ID) === ['consumer-fixture']
        && $request->header(BfcHeaders::CONTRACT_VERSION) === ['2']);
    Http::assertSent(static fn (Request $request): bool => in_array($request->url(), [
        'https://provider.test/matte',
        'https://provider.test/crate',
    ], true) && $request->header('Authorization') === ['Bearer '.$credential]);
});

it('adds no retry and preserves caller credential and idempotency metadata across a driven retry', function () {
    $credential = 'fixture_'.bin2hex(random_bytes(24));
    $idempotencyKey = (string) Str::ulid();
    config()->set('bfc-client.identity', 'retry-fixture');
    Http::fakeSequence()->pushStatus(503)->pushStatus(200)->pushStatus(503);

    Http::withClientIdentity()
        ->withToken($credential)
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->retry(2, 0, throw: false)
        ->post('https://provider.test/retrying-operation');

    Http::assertSentCount(2);
    $attempts = Http::recorded(static fn (Request $request): bool => $request->url() === 'https://provider.test/retrying-operation');
    expect($attempts)->toHaveCount(2);
    foreach ($attempts as [$request]) {
        expect($request->header('Authorization'))->toBe(['Bearer '.$credential])
            ->and($request->header('Idempotency-Key'))->toBe([$idempotencyKey])
            ->and($request->header(BfcHeaders::CLIENT_ID))->toBe(['retry-fixture'])
            ->and($request->header(BfcHeaders::CONTRACT_VERSION))->toBe(['2']);
    }

    Http::withClientIdentity()->post('https://provider.test/no-client-retry');
    Http::assertSentCount(3);
});
