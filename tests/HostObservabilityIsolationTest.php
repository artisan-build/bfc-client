<?php

declare(strict_types=1);

use ArtisanBuild\BfcClient\BfcContract;
use ArtisanBuild\BfcClient\BfcHeaders;
use ArtisanBuild\BfcClient\BfcHttp;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Stands in for every benign host observer of outbound HTTP: the
 * request/response events Laravel publishes from the shared factory
 * (Telescope, Nightwatch, error trackers) and app-global HTTP middleware.
 *
 * Each observer records the channel it observed on plus the full headers it
 * was handed, so the assertions below can be made about the WHOLE record
 * rather than about one named leak: a future channel that starts carrying
 * the request lands in the same array and fails the same expectation.
 *
 * @param  ArrayObject<int, array{channel: string, payload: string}>  $observed
 */
function bfcObserveAsHost(ArrayObject $observed): void
{
    foreach ([RequestSending::class, ResponseReceived::class, ConnectionFailed::class] as $event) {
        Event::listen($event, static function (object $payload) use ($observed, $event): void {
            /** @var object{request: \Illuminate\Http\Client\Request} $payload */
            $observed[] = [
                'channel' => 'listener:'.$event,
                'payload' => (string) json_encode($payload->request->headers()),
            ];
        });
    }

    Http::globalRequestMiddleware(static function (RequestInterface $request) use ($observed): RequestInterface {
        $observed[] = [
            'channel' => 'global-request-middleware',
            'payload' => (string) json_encode($request->getHeaders()),
        ];

        return $request->withHeader('X-Host-Global-Middleware', 'applied');
    });

    Http::globalResponseMiddleware(static function (ResponseInterface $response) use ($observed): ResponseInterface {
        $observed[] = [
            'channel' => 'global-response-middleware',
            'payload' => (string) json_encode($response->getHeaders()),
        ];

        return $response;
    });
}

/**
 * The channels on which the host actually saw the credential.
 *
 * @param  ArrayObject<int, array{channel: string, payload: string}>  $observed
 * @return list<string>
 */
function bfcChannelsExposing(ArrayObject $observed, string $credential): array
{
    $channels = [];

    foreach ($observed as $observation) {
        if (str_contains($observation['payload'], $credential)) {
            $channels[] = $observation['channel'];
        }
    }

    return array_values(array_unique($channels));
}

/**
 * @param  ArrayObject<int, array{channel: string, payload: string}>  $observed
 * @return list<string>
 */
function bfcChannelsObserved(ArrayObject $observed): array
{
    return array_values(array_unique(array_map(
        static fn (array $observation): string => $observation['channel'],
        iterator_to_array($observed),
    )));
}

/**
 * The premise of issue #8: the app singleton behind the `Http` facade
 * carries the host's dispatcher and the host's global middleware, so a
 * credential composed onto it is handed to every host observer in full.
 *
 * This is framework behaviour, not ours. It is asserted here because it is
 * the entire reason the package offers a private factory: if Laravel ever
 * stops publishing these, this test is the signal that the advice below
 * can be relaxed.
 */
it('exposes a credential sent through the host shared factory to host observers', function () {
    $observed = new ArrayObject;
    bfcObserveAsHost($observed);

    config()->set('bfc-client.identity', 'client-abc-123');
    $credential = 'fixture_'.bin2hex(random_bytes(24));
    $history = [];

    Http::withClientIdentity()
        ->withToken($credential)
        ->withMiddleware(Middleware::history($history))
        ->setHandler(new MockHandler([new GuzzleResponse]))
        ->post('https://provider.test/api/things');

    expect(bfcChannelsExposing($observed, $credential))->toEqualCanonicalizing([
        'global-request-middleware',
        'listener:'.RequestSending::class,
        'listener:'.ResponseReceived::class,
    ]);
});

/**
 * The invariant this issue asks for: a credential-bearing request composed
 * through the package's own outbound path is published to NO host observer
 * and passes through NO host-global middleware — while still carrying the
 * identity, the contract major and the credential itself to the wire.
 */
it('publishes nothing about a credential-bearing request to any host observer', function () {
    $observed = new ArrayObject;
    bfcObserveAsHost($observed);

    config()->set('bfc-client.identity', 'client-abc-123');
    $credential = 'fixture_'.bin2hex(random_bytes(24));
    $history = [];

    BfcHttp::withClientIdentity()
        ->withToken($credential)
        ->withMiddleware(Middleware::history($history))
        ->setHandler(new MockHandler([new GuzzleResponse]))
        ->post('https://provider.test/api/things');

    expect(bfcChannelsObserved($observed))->toBe([])
        ->and(bfcChannelsExposing($observed, $credential))->toBe([]);

    expect($history)->toHaveCount(1);

    /** @var RequestInterface $wireRequest */
    $wireRequest = $history[0]['request'];

    expect($wireRequest->getHeader('Authorization'))->toBe(['Bearer '.$credential])
        ->and($wireRequest->getHeader(BfcHeaders::CLIENT_ID))->toBe(['client-abc-123'])
        ->and($wireRequest->getHeader(BfcHeaders::CONTRACT_VERSION))->toBe([(string) BfcContract::MAJOR])
        ->and($wireRequest->hasHeader('X-Host-Global-Middleware'))->toBeFalse();
});

/**
 * Host-global OPTIONS are the second half of the shared singleton's state.
 * They are not middleware, and a factory that dropped the dispatcher but
 * inherited the options would still let a host reshape package traffic.
 */
it('inherits no host global options', function () {
    config()->set('bfc-client.identity', 'client-abc-123');

    Http::globalOptions(['headers' => ['X-Host-Global-Option' => 'applied']]);

    $history = [];

    BfcHttp::withClientIdentity()
        ->withMiddleware(Middleware::history($history))
        ->setHandler(new MockHandler([new GuzzleResponse]))
        ->post('https://provider.test/api/things');

    expect($history)->toHaveCount(1);

    /** @var RequestInterface $wireRequest */
    $wireRequest = $history[0]['request'];

    expect($wireRequest->hasHeader('X-Host-Global-Option'))->toBeFalse();
});

it('resolves one private factory that is not the host shared factory', function () {
    expect(BfcHttp::factory())->toBeInstanceOf(Factory::class)
        ->and(BfcHttp::factory())->toBe(BfcHttp::factory())
        ->and(BfcHttp::factory())->not->toBe(app(Factory::class))
        ->and(BfcHttp::factory()->getDispatcher())->toBeNull()
        ->and(BfcHttp::withClientIdentity())->toBeInstanceOf(PendingRequest::class);
});

/**
 * The private factory is fakeable on its own account, so a consumer's test
 * suite is not forced back onto the shared factory to assert package
 * traffic. The host's `Http::fake()` does not reach it, which is the same
 * fact stated from the other side.
 */
it('fakes independently of the host shared factory', function () {
    config()->set('bfc-client.identity', 'client-abc-123');

    Http::fake();
    BfcHttp::factory()->fake();

    BfcHttp::withClientIdentity()->post('https://provider.test/private');

    BfcHttp::factory()->assertSent(
        static fn (Illuminate\Http\Client\Request $request): bool => $request->url() === 'https://provider.test/private'
            && $request->header(BfcHeaders::CLIENT_ID) === ['client-abc-123'],
    );

    Http::assertNothingSent();
});
