<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Contracts;

use ArtisanBuild\BfcClient\BoundCredentialScope;
use ArtisanBuild\BfcClient\ClaimedHmacCredential;
use ArtisanBuild\BfcClient\IssuerHmacCutoverReceipt;
use ArtisanBuild\BfcClient\SensitiveString;

interface HmacCredentialIssuerClient
{
    public function claim(BoundCredentialScope $expectedScope, SensitiveString $claimCode): ClaimedHmacCredential;

    public function activate(
        BoundCredentialScope $expectedScope,
        ?string $predecessorId,
        string $replacementId,
        string $deliveryFingerprint,
    ): IssuerHmacCutoverReceipt;

    public function cutoverStatus(
        BoundCredentialScope $expectedScope,
        ?string $predecessorId,
        string $replacementId,
    ): IssuerHmacCutoverReceipt;
}
