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

## Wire contract

The wire contract between a client app and its BfC provider is documented here as each piece lands:

- **Client identity header** (`X-BfC-Client-Id`) — coming in a later release.
- **Proof-of-life route** — coming in a later release.

## License

MIT. See [LICENSE](LICENSE).
