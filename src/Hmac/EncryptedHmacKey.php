<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Hmac;

final readonly class EncryptedHmacKey
{
    public function __construct(
        public string $ciphertext,
        public string $keyVersion,
    ) {}
}
