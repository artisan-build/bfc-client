<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Client Identity
    |--------------------------------------------------------------------------
    |
    | An explicit, stable identifier for this client installation, used by a
    | BfC provider to attribute API traffic to a specific client app. This is
    | an IDENTIFIER, never a secret — it grants nothing on its own.
    |
    | When null, no explicit identity is configured. Automatic resolution and
    | generation of a stable identity lands in a later release.
    |
    */

    'identity' => env('BFC_CLIENT_IDENTITY'),

];
