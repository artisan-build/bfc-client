<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

use ArtisanBuild\BfcClient\Hmac\HmacEnvelope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use JsonSerializable;
use LogicException;

final readonly class StoredHmacCredential implements JsonSerializable
{
    public function __construct(
        public string $id,
        public CredentialPurpose $purpose,
        public Subject $subject,
        public CredentialStatus $status,
        public ?string $secretCiphertext,
        public ?string $secretKeyVersion,
        public CarbonImmutable $deliveredAt,
        public int $deliveryGeneration,
        public string $deliveryFingerprint,
        public ?string $predecessorCredentialId,
        public CarbonImmutable $activatedAt,
        public ?CarbonImmutable $expiresAt,
        public ?CarbonImmutable $revokedAt,
        public ?BoundCredentialScope $scope,
        public ?CredentialMaterialRole $materialRole,
        public string $algorithm = HmacEnvelope::ALGORITHM,
        public ?string $scopeHash = null,
    ) {}

    public function isActiveAt(CarbonInterface $at): bool
    {
        return $this->status === CredentialStatus::Active
            && $this->revokedAt === null
            && ($this->expiresAt === null || $this->expiresAt->greaterThan($at));
    }

    public function exactlyMatchesBoundScope(BoundCredentialScope $scope, CredentialMaterialRole $role): bool
    {
        return $this->scope !== null
            && $this->materialRole === $role
            && $this->purpose === CredentialPurpose::Signing
            && $this->subject->type === $scope->subject->type
            && hash_equals($this->subject->ref, $scope->subject->ref)
            && $this->scope->equals($scope)
            && hash_equals($this->algorithm, HmacEnvelope::ALGORITHM)
            && $this->scopeHash !== null
            && hash_equals($this->scopeHash, self::scopeHash($scope, $role));
    }

    public function exactlyMatchesUnboundSubject(Subject $subject): bool
    {
        return $this->scope === null
            && $this->materialRole === null
            && $this->purpose === CredentialPurpose::Signing
            && $this->subject->type === $subject->type
            && hash_equals($this->subject->ref, $subject->ref);
    }

    public static function scopeHash(BoundCredentialScope $scope, CredentialMaterialRole $role): string
    {
        $preimage = "bfc-scope-v1\0";

        foreach ([
            $scope->subject->type->value,
            $scope->subject->ref,
            $scope->appPurpose,
            CredentialPurpose::Signing->value,
            $scope->installation,
            $scope->application,
            $scope->audience,
            HmacEnvelope::ALGORITHM,
            $role->value,
        ] as $field) {
            $preimage .= pack('N', strlen($field)).$field;
        }

        return hash('sha256', $preimage);
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('A stored HMAC credential never serializes.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('A stored HMAC credential never JSON-encodes.');
    }
}
