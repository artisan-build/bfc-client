<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

enum CredentialStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
}
