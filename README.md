# artisan-build/bfc-client

A lean client-side package for Built for Cloud source applications. It supplies versioned, non-authoritative installation metadata, a proof-of-life route, and pure local install-file mechanics. It does not contain server authentication, users, credentials, guards, migrations, commands, enrollment, callbacks, or retry policy.

## Installation

```bash
composer require artisan-build/bfc-client
```

Laravel auto-discovers only `ArtisanBuild\BfcClient\BfcClientServiceProvider`. Optionally publish its config:

```bash
php artisan vendor:publish --tag=bfc-client-config
```

## Client Identity

`ArtisanBuild\BfcClient\ClientIdentity::resolve()` returns a stable installation label in this order:

1. A non-empty `bfc-client.identity` / `BFC_CLIENT_IDENTITY` string, byte-exact and never persisted.
2. The non-empty bytes already stored at `storage_path('app/bfc-client/identity')`.
3. One generated UUID persisted under an exclusive target-file lock. Concurrent first resolvers converge on the same bytes.

The label is not a credential and never selects an installation, purpose, audience, role, ability, or other authority. Provision `BFC_CLIENT_IDENTITY` explicitly on ephemeral filesystems; otherwise a storage reset creates a new label.

## Outbound Metadata

The `Http::withClientIdentity()` macro replaces conflicting defaults with exactly one validated client-ID field and one frozen contract-major field. Compose it with the opaque, package-issued, fixed-purpose credential required by the destination:

```php
use Illuminate\Support\Facades\Http;

Http::withClientIdentity()
    ->withToken($credential)
    ->post('https://provider.example/api/things', $payload);
```

The macro adds no retry. Consumers remain responsible for fail-open versus fail-loud behavior, operation-specific retries, `Retry-After`, and idempotency keys. A caller-created credential and idempotency key remain ordinary pending-request options across caller-driven retries.

Invalid client IDs fail before send with a redacted `InvalidArgumentException`. Valid values are opaque UTF-8 strings of 1-255 bytes with no CR, LF, or NUL octet. They are never truncated or normalized.

## Proof Of Life

The optional, unauthenticated `GET /bfc-client` route is limited to 60 requests per minute by request IP. Its exact JSON shape is:

```json
{"contract_major":2,"package":"artisan-build/bfc-client","client_id":"0198c5f2-..."}
```

The response contains no secret or authority material. Move it with `BFC_CLIENT_PROOF_OF_LIFE_PATH`, or disable it with `BFC_CLIENT_PROOF_OF_LIFE=false`.

## Local Install Files

`ArtisanBuild\BfcClient\Install\InstallFiles` applies values a caller has already obtained. It does not mint, fetch, list, rotate, revoke, or print credentials; invoke Artisan or Laravel Cloud; contact Scalpels or another network service; or inherit a Console command.

```php
use ArtisanBuild\BfcClient\Install\InstallFiles;

$result = (new InstallFiles)->install(
    environmentPath: '/absolute/path/.env.source',
    composerPath: '/absolute/path/composer.json',
    environment: ['SOURCE_CREDENTIAL' => $credential],
    composerMajors: ['vendor/source-client' => 2],
);

$result->succeeded();
$result->stages(); // Values are only "unchanged", "replaced", or "failed".
```

Environment keys are exact uppercase names; values are safely quoted and escaped. Composer inputs are package names mapped to positive major integers and become clean caret constraints such as `^2`. Both targets use stable sidecar locks and same-directory atomic replacement, preserve existing file modes and unrelated bytes, and do not rewrite an identical target. The env and Composer stages are independently atomic, so inspect `stages()` for an honest partial result. The selected env file is the only intended secret sink; do not put credentials in command arguments or output.

## Wire Contract

- Contract header: exactly one `BFC-Contract-Version: 2`.
- Client label header: exactly one `X-BfC-Client-Id: <opaque label>`.
- Version is compatibility metadata only. It never selects authority.
- P6 accepts canonical major `2`; missing and malformed values return `missing_contract_major` or `malformed_contract_major` with status 400; another canonical major returns `unsupported_contract_major` with status 426.
- Only public version incompatibility is distinguishable. Credential purpose, audience, installation, expiry, revocation, replay, and spoofing failures remain the provider's uniform authentication refusal.
- Credential authority comes only from the provider's fixed package declaration and credential binding. Client input cannot select purpose, role, or ACL authority.
- Replay, rotation, revocation, credential storage, and transport cryptography belong to `artisan-build/built-for-cloud`, not this package.

This package has no profile-editing or account-deletion feature.

## License

MIT. See [LICENSE](LICENSE).
