<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Contracts;

use ArtisanBuild\BfcClient\CredentialPurpose;

interface HmacAppPurposeRegistry
{
    public function purpose(string $appPurpose): ?CredentialPurpose;
}
