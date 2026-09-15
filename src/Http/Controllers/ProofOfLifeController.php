<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Http\Controllers;

use ArtisanBuild\BfcClient\BfcContract;
use ArtisanBuild\BfcClient\ClientIdentity;
use Illuminate\Http\JsonResponse;

/**
 * The "installed?" signal: a provider hits this read-only endpoint to
 * confirm the package is present and to read the client identity. The
 * payload is exactly the contract major, package name, and identity. The
 * identity is an identifier, never a secret or source of authority.
 */
final class ProofOfLifeController
{
    public function __invoke(ClientIdentity $identity): JsonResponse
    {
        return new JsonResponse([
            'contract_major' => BfcContract::MAJOR,
            'package' => 'artisan-build/bfc-client',
            'client_id' => $identity->validated(),
        ]);
    }
}
