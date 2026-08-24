<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Http\Controllers;

use ArtisanBuild\BfcClient\ClientIdentity;
use Illuminate\Http\JsonResponse;

/**
 * The "installed?" signal: a provider hits this read-only endpoint to
 * confirm the package is present and to read the client identity. The
 * payload is exactly the package name and the identity — an identifier,
 * never a secret — and MUST NOT grow env details, versions, or config.
 */
final class ProofOfLifeController
{
    public function __invoke(ClientIdentity $identity): JsonResponse
    {
        return new JsonResponse([
            'package' => 'artisan-build/bfc-client',
            'client_id' => $identity->validated(),
        ]);
    }
}
