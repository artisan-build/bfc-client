<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Client Identity
    |--------------------------------------------------------------------------
    |
    | A stable identifier for this client installation, used by a BfC
    | provider to attribute API traffic to a specific client app. This is
    | an IDENTIFIER, never a secret — it grants nothing on its own.
    |
    | Resolution order: when this value is a non-empty string it is used
    | verbatim (and never persisted). Otherwise the identity persisted at
    | storage_path('app/bfc-client/identity') is used. When neither exists,
    | a UUID is generated, persisted to that file, and reused thereafter.
    |
    | Installs on EPHEMERAL filesystems should set BFC_CLIENT_IDENTITY
    | explicitly, or the generated identity will churn on every redeploy.
    |
    | The wire contract caps the identity at 255 bytes with no line breaks.
    | Http::withClientIdentity() never truncates or mutates the value — an
    | identity that violates the cap throws an InvalidArgumentException at
    | the attach point instead of being sent.
    |
    */

    'identity' => env('BFC_CLIENT_IDENTITY'),

];
