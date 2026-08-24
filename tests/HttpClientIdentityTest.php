<?php

declare(strict_types=1);

use ArtisanBuild\BfcClient\BfcHeaders;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

it('replaces a conflicting pre-set X-BfC-Client-Id with the single validated identity', function () {
    config()->set('bfc-client.identity', 'client-abc-123');

    Http::globalOptions(['headers' => [BfcHeaders::CLIENT_ID => 'spoofed-value']]);

    Http::fake();

    Http::withClientIdentity()->get('https://provider.test/api/ping');

    Http::assertSent(fn (Request $request): bool => $request->header(BfcHeaders::CLIENT_ID) === ['client-abc-123']);
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
