<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;

/**
 * A package-private HTTP factory for credential-bearing outbound requests.
 *
 * Laravel's shared factory — the one behind the `Http` facade — is built
 * with the application's event dispatcher and carries the application's
 * global HTTP middleware and options. Every request made through it is
 * published, whole, to `RequestSending` and `ResponseReceived` listeners and
 * is handed to app-global middleware, so benign host instrumentation
 * (Telescope, Nightwatch, error trackers) sees any bearer token or one-time
 * code a package sends through it.
 *
 * This factory is constructed with no dispatcher, which is what makes those
 * events undispatchable rather than merely unsubscribed, and it is a
 * separate instance, so it holds none of the host's global middleware or
 * options. `Factory::macro` is static, so `withClientIdentity()` works on it
 * unchanged.
 *
 * The trade is symmetric and deliberate: a host's `Http::fake()` does not
 * reach this factory either. Fake it on its own account with
 * `BfcHttp::factory()->fake()`.
 */
final class BfcHttp
{
    /**
     * The package-private factory: one instance per application, never the
     * instance behind the `Http` facade.
     */
    public static function factory(): Factory
    {
        return app(BfcHttpFactory::class);
    }

    /**
     * A pending request on the private factory carrying exactly one
     * validated client-ID field and one frozen contract-major field.
     */
    public static function withClientIdentity(): PendingRequest
    {
        return self::factory()->withClientIdentity();
    }
}
