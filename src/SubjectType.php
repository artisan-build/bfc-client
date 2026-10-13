<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

enum SubjectType: string
{
    case Application = 'application';
    case Installation = 'installation';
    case UserPrincipal = 'user_principal';
    case ExternalConsumer = 'external_consumer';
    case Operator = 'operator';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
