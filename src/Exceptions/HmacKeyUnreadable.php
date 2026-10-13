<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Exceptions;

use RuntimeException;
use Throwable;

final class HmacKeyUnreadable extends RuntimeException
{
    public static function unknownVersion(string $keyVersion): self
    {
        return new self(sprintf(
            'No key in the read-keyring carries version %s. Restore the previous application key before retrying.',
            $keyVersion,
        ));
    }

    public static function missingVersion(): self
    {
        return new self('This HMAC ciphertext carries no encryption key-version.');
    }

    public static function missingCiphertext(): self
    {
        return new self('This HMAC credential carries no encrypted key material.');
    }

    public static function ringFailure(string $keyVersion, Throwable $previous): self
    {
        return new self(
            sprintf('The HMAC ciphertext under key-version %s could not be read.', $keyVersion),
            previous: $previous,
        );
    }
}
