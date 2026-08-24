<?php

declare(strict_types=1);

use ArtisanBuild\BfcClient\BfcHeaders;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('attaches the resolved identity as the X-BfC-Client-Id header', function () {
    config()->set('bfc-client.identity', 'client-abc-123');

    Http::fake();

    Http::withClientIdentity()->get('https://provider.test/api/ping');

    Http::assertSent(fn (Request $request): bool => $request->header(BfcHeaders::CLIENT_ID) === ['client-abc-123']);
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
