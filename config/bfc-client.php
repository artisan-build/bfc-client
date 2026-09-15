<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Client Identity
    |--------------------------------------------------------------------------
    |
    | A stable identifier for this client installation, used by a BfC
    | provider to label API traffic from a specific client app. This is an
    | IDENTIFIER, never a secret or authority source; it grants nothing.
    |
    | Resolution order: when this value is a non-empty string it is used
    | verbatim (and never persisted). Otherwise the identity persisted at
    | storage_path('app/bfc-client/identity') is used. When neither exists,
    | a UUID is generated, persisted to that file, and reused thereafter.
    |
    | Installs on EPHEMERAL filesystems should set BFC_CLIENT_IDENTITY
    | explicitly, or the generated identity will churn on every redeploy.
    |
    | The wire contract requires the identity to be valid UTF-8, 1-255
    | bytes, containing no CR (\r), LF (\n), or NUL octets. Persisted and
    | explicit labels are byte-exact. Http::withClientIdentity() never
    | truncates or mutates the value; an identity that violates the
    | contract throws an InvalidArgumentException at the attach point
    | instead of being sent.
    |
    */

    'identity' => env('BFC_CLIENT_IDENTITY'),

    /*
    |--------------------------------------------------------------------------
    | Proof of Life
    |--------------------------------------------------------------------------
    |
    | A read-only route a BfC provider hits to confirm this package is
    | installed and to read the client identity. The response contains the
    | contract major, package name, and resolved identity. It returns no
    | credential, authority, user, role, or application configuration.
    |
    | Disable the route entirely with BFC_CLIENT_PROOF_OF_LIFE=false, or
    | move it with BFC_CLIENT_PROOF_OF_LIFE_PATH.
    |
    */

    'proof_of_life' => [
        'enabled' => env('BFC_CLIENT_PROOF_OF_LIFE', true),
        'path' => env('BFC_CLIENT_PROOF_OF_LIFE_PATH', 'bfc-client'),
    ],

];
