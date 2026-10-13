<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

final readonly class VerifiedHmacCredential
{
    public function __construct(
        public string $credentialId,
        public ?BoundCredentialScope $scope,
        public string $algorithm = 'hmac-sha256',
    ) {}
}
