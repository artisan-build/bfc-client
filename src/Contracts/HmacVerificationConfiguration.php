<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Contracts;

interface HmacVerificationConfiguration
{
    public function audience(): ?string;

    public function timestampToleranceSeconds(): int;

    public function verificationRateCeiling(): int;
}
