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

## Wire contract

The wire contract between a client app and its BfC provider is documented here as each piece lands:

- **Client identity header** (`X-BfC-Client-Id`)
  - **Header name:** `X-BfC-Client-Id`.
  - **Value:** the resolved client identity — an opaque, stable string of valid UTF-8, 1–255 bytes, containing no CR (`\r`) or LF (`\n`) octets. (Literal CR/LF octets are the header-injection hazard; exotic Unicode separators such as U+2028 are permitted and treated as opaque bytes.) Providers MUST treat it as an opaque identifier: compare byte-wise and store verbatim. Providers MUST NOT treat it as a credential or grant anything based on it alone.
  - **When sent:** on requests the client app makes to its provider using `Http::withClientIdentity()`, typically alongside its normal token auth. The client never sends a value outside the limits above — it fails loudly instead.
  - **Provider behaviour (informative):** a BfC provider records the identity against the API token that authenticated the request (per-token client identity), enabling token→client attribution.
- **Proof-of-life route** — coming in a later release.

## License

MIT. See [LICENSE](LICENSE).
