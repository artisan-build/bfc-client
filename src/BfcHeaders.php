<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

/**
 * The HTTP header names that make up the BfC wire contract.
 */
final class BfcHeaders
{
    public const CONTRACT_VERSION = 'BFC-Contract-Version';

    public const CLIENT_ID = 'X-BfC-Client-Id';
}
