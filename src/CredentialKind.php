<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

enum CredentialKind: string
{
    case Bearer = 'bearer';
    case Basic = 'basic';
    case Asymmetric = 'asymmetric';
    case Hmac = 'hmac';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
