<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Exceptions;

use RuntimeException;

final class HmacCredentialTransferRefused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The HMAC credential transfer was refused.');
    }
}
