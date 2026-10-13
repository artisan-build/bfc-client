<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

use ArtisanBuild\BfcClient\Contracts\HmacVerificationConfiguration;

final class ConfiguredHmacVerification implements HmacVerificationConfiguration
{
    public function audience(): ?string
    {
        $audience = config('bfc-client.hmac.audience');

        return is_string($audience) && $audience !== '' ? $audience : null;
    }

    public function timestampToleranceSeconds(): int
    {
        $tolerance = config('bfc-client.hmac.timestamp_tolerance_seconds', 300);

        return is_numeric($tolerance) && (int) $tolerance > 0 ? (int) $tolerance : 300;
    }

    public function verificationRateCeiling(): int
    {
        $ceiling = config('bfc-client.hmac.verification_rate_ceiling', 1000);

        return is_numeric($ceiling) && (int) $ceiling > 0 ? (int) $ceiling : 1000;
    }
}
