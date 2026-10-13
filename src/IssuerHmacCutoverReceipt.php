<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

use Carbon\CarbonImmutable;
use JsonSerializable;
use LogicException;

final readonly class IssuerHmacCutoverReceipt implements JsonSerializable
{
    private function __construct(
        public ?string $predecessorCredentialId,
        public string $replacementCredentialId,
        public BoundCredentialScope $scope,
        public CarbonImmutable $activatedAt,
        public ?CarbonImmutable $predecessorExpiresAt,
        public bool $emergency,
    ) {}

    public static function fromIssuerResponse(
        ?string $predecessorCredentialId,
        string $replacementCredentialId,
        BoundCredentialScope $scope,
        CarbonImmutable $activatedAt,
        ?CarbonImmutable $predecessorExpiresAt,
        bool $emergency,
    ): self {
        return new self(
            $predecessorCredentialId,
            $replacementCredentialId,
            $scope,
            $activatedAt,
            $predecessorExpiresAt,
            $emergency,
        );
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('An issuer HMAC cutover receipt never serializes.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('An issuer HMAC cutover receipt never JSON-encodes.');
    }
}
