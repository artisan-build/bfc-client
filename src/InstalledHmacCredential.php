<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

final readonly class InstalledHmacCredential
{
    public function __construct(
        public string $credentialId,
        public ?string $predecessorCredentialId,
        public BoundCredentialScope $scope,
        public string $algorithm = 'hmac-sha256',
    ) {}
}
