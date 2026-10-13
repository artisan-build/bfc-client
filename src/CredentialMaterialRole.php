<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

enum CredentialMaterialRole: string
{
    case Originator = 'originator';
    case VerificationCopy = 'verification_copy';
}
