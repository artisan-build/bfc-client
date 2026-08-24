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

## Wire contract

The wire contract between a client app and its BfC provider is documented here as each piece lands:

- **Client identity header** (`X-BfC-Client-Id`) — coming in a later release.
- **Proof-of-life route** — coming in a later release.

## License

MIT. See [LICENSE](LICENSE).
