# artisan-build/bfc-client

The client-side Built for Cloud package. A BfC client application carries this lean library so its provider can attribute an API token to a specific client installation and confirm the client is actually installed — a stable client identity and a proof-of-life signal, without the control plane ever reading customer environment variables.

## Installation

```bash
composer require artisan-build/bfc-client
```

The service provider is auto-discovered. Optionally publish the config:

```bash
php artisan vendor:publish --tag=bfc-client-config
```

## Client identity

`ArtisanBuild\BfcClient\ClientIdentity` (a container singleton) resolves a stable identifier for the installation via `resolve()`:

1. **Explicit config** — when `bfc-client.identity` (the `BFC_CLIENT_IDENTITY` env var) is a non-empty string, it is returned verbatim and never persisted.
2. **Persisted file** — otherwise the identity stored at `storage_path('app/bfc-client/identity')` is returned (the value is read back trimmed).
3. **Generated** — otherwise a UUID is generated, persisted to that file, and reused on subsequent resolutions.

The identity is an identifier, never a secret. Installs on ephemeral filesystems should set `BFC_CLIENT_IDENTITY` explicitly — otherwise the generated identity will churn on every redeploy.

## Attaching the identity to requests

The package registers an HTTP client macro, `Http::withClientIdentity()`, that returns a pending request carrying the identity header. It composes with every other pending-request option:

```php
use Illuminate\Support\Facades\Http;

Http::withClientIdentity()
    ->withToken($token)
    ->post('https://provider.example/api/things', [...]);
```

The identity is resolved lazily, at call time. The macro never truncates or mutates the identity: a resolved identity that violates the wire contract below (not valid UTF-8, over 255 bytes, or containing a CR or LF octet) throws an `InvalidArgumentException` instead of being sent. The macro also replaces any pre-existing `X-BfC-Client-Id` header (for example one set via `Http::globalOptions()`), so the validated identity is the only value on the wire.

## Proof of life

The package registers a read-only route, `GET /bfc-client` by default, that a BfC provider polls to confirm the package is installed in the client app and to read its client identity:

```bash
curl https://client-app.example/bfc-client
# {"package":"artisan-build/bfc-client","client_id":"0198c5f2-..."}
```

The route is unauthenticated (the identity is an identifier, never a secret), throttled to 60 requests per minute, and returns nothing beyond the package name and the resolved identity; an identity that violates the wire contract's limits fails loudly (a 500) rather than being served. Move it with `BFC_CLIENT_PROOF_OF_LIFE_PATH`, or disable it entirely with `BFC_CLIENT_PROOF_OF_LIFE=false`.

## Wire contract

The wire contract between a client app and its BfC provider is documented here as each piece lands:

- **Client identity header** (`X-BfC-Client-Id`)
  - **Header name:** `X-BfC-Client-Id`.
  - **Value:** the resolved client identity — an opaque, stable string of valid UTF-8, 1–255 bytes, containing no CR (`\r`) or LF (`\n`) octets. (Literal CR/LF octets are the header-injection hazard; exotic Unicode separators such as U+2028 are permitted and treated as opaque bytes.) Providers MUST treat it as an opaque identifier: compare byte-wise and store verbatim. Providers MUST NOT treat it as a credential or grant anything based on it alone.
  - **When sent:** on requests the client app makes to its provider using `Http::withClientIdentity()`, typically alongside its normal token auth. The client never sends a value outside the limits above — it fails loudly instead.
  - **Provider behaviour (informative):** a BfC provider records the identity against the API token that authenticated the request (per-token client identity), enabling token→client attribution.
- **Proof-of-life route** (`GET /bfc-client`)
  - **Endpoint:** `GET /bfc-client` on the client app. The path is configurable via `BFC_CLIENT_PROOF_OF_LIFE_PATH`; the route can be disabled entirely via `BFC_CLIENT_PROOF_OF_LIFE=false`.
  - **Response:** `200` with `Content-Type: application/json` and the JSON body `{"package": "artisan-build/bfc-client", "client_id": "<client identity>"}` — those two keys and nothing else.
  - **Semantics:** unauthenticated, read-only, throttled (60 requests per minute). The response never contains secrets; `client_id` is the same opaque identifier the `X-BfC-Client-Id` header carries, subject to the same limits, and grants nothing on its own.
  - **Provider behaviour (informative):** a `200` with a parseable body confirms the package is installed; the returned `client_id` lets the provider key this installation to its records. A `404` means the package is not installed, or the route is disabled or moved.

## License

MIT. See [LICENSE](LICENSE).
