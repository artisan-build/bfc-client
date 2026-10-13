<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Actions;

use ArtisanBuild\BfcClient\BoundCredentialScope;
use ArtisanBuild\BfcClient\ClaimedHmacCredential;
use ArtisanBuild\BfcClient\Contracts\HmacAppPurposeRegistry;
use ArtisanBuild\BfcClient\Contracts\HmacCredentialIssuerClient;
use ArtisanBuild\BfcClient\Contracts\HmacCredentialStore;
use ArtisanBuild\BfcClient\CredentialMaterialRole;
use ArtisanBuild\BfcClient\CredentialPurpose;
use ArtisanBuild\BfcClient\CredentialStatus;
use ArtisanBuild\BfcClient\Exceptions\HmacCredentialTransferRefused;
use ArtisanBuild\BfcClient\Hmac\HmacEnvelope;
use ArtisanBuild\BfcClient\Hmac\HmacKeyring;
use ArtisanBuild\BfcClient\InstalledHmacCredential;
use ArtisanBuild\BfcClient\SensitiveString;
use ArtisanBuild\BfcClient\StoredHmacCredential;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

final class InstallHmacCredentialFromClaim
{
    public function __construct(
        private readonly HmacAppPurposeRegistry $purposes,
        private readonly HmacKeyring $keyring,
        private readonly HmacCredentialStore $credentials,
    ) {}

    public function __invoke(
        BoundCredentialScope $expectedScope,
        HmacCredentialIssuerClient $trustedIssuer,
        #[SensitiveParameter] string $claimCode,
    ): InstalledHmacCredential {
        if ($this->purposes->purpose($expectedScope->appPurpose) !== CredentialPurpose::Signing) {
            throw new HmacCredentialTransferRefused;
        }

        $claimed = $trustedIssuer->claim($expectedScope, new SensitiveString($claimCode));
        $transfer = $claimed->transfer;

        if (! $expectedScope->equals($transfer->scope)
            || $transfer->algorithm !== HmacEnvelope::ALGORITHM
            || $transfer->sourceStatus !== CredentialStatus::Pending
            || ! Str::isUuid($transfer->issuerCredentialId)
            || ($transfer->predecessorCredentialId !== null && ! Str::isUuid($transfer->predecessorCredentialId))
            || $transfer->deliveryGeneration < 1
            || preg_match('/\A[0-9a-f]{16}\z/D', $transfer->deliveryFingerprint) !== 1
            || $transfer->deliveredAt->isFuture()
            || $transfer->transferExpiresAt->lessThan($transfer->deliveredAt)
            || now()->greaterThan($transfer->transferExpiresAt)
            || $transfer->transferExpiresAt->greaterThan($transfer->deliveredAt->addSeconds(60))
            || ($transfer->credentialExpiresAt !== null && ! $transfer->credentialExpiresAt->isFuture())) {
            throw new HmacCredentialTransferRefused;
        }

        $deliveryValidated = false;

        try {
            return $this->credentials->transaction(function () use ($expectedScope, $claimed, &$deliveryValidated): InstalledHmacCredential {
                return $this->install($expectedScope, $claimed, $deliveryValidated);
            });
        } catch (Throwable $failure) {
            if (! $deliveryValidated || ! $this->credentials->isConcurrencyConflict($failure)) {
                throw $failure;
            }

            return $this->idempotentResult(
                $expectedScope,
                $transfer->issuerCredentialId,
                $transfer->deliveryFingerprint,
                $transfer->predecessorCredentialId,
            ) ?? throw new HmacCredentialTransferRefused;
        }
    }

    private function install(
        BoundCredentialScope $scope,
        ClaimedHmacCredential $claimed,
        bool &$deliveryValidated,
    ): InstalledHmacCredential {
        $transfer = $claimed->transfer;
        $ids = array_values(array_filter([$transfer->predecessorCredentialId, $transfer->issuerCredentialId]));
        sort($ids, SORT_STRING);
        $locked = [];

        foreach ($ids as $id) {
            $credential = $this->credentials->find($id, lockForUpdate: true);

            if ($credential !== null && ! hash_equals($credential->id, $id)) {
                throw new HmacCredentialTransferRefused;
            }

            $locked[$id] = $credential;
        }

        $plaintext = $claimed->secret->reveal();

        if (preg_match('/\A[0-9a-f]{64}\z/D', $plaintext) !== 1
            || ! hash_equals(
                $transfer->deliveryFingerprint,
                $this->keyring->deliveryFingerprint($plaintext, $transfer->deliveryGeneration),
            )) {
            throw new HmacCredentialTransferRefused;
        }

        $deliveryValidated = true;
        $existing = $locked[$transfer->issuerCredentialId] ?? null;

        if ($existing instanceof StoredHmacCredential) {
            unset($plaintext);

            return $this->idempotentResult(
                $scope,
                $transfer->issuerCredentialId,
                $transfer->deliveryFingerprint,
                $transfer->predecessorCredentialId,
            ) ?? throw new HmacCredentialTransferRefused;
        }

        $predecessor = $transfer->predecessorCredentialId === null
            ? null
            : ($locked[$transfer->predecessorCredentialId] ?? null);

        if (($transfer->predecessorCredentialId !== null && ! $predecessor instanceof StoredHmacCredential)
            || ($predecessor instanceof StoredHmacCredential
                && ! $this->validPredecessor($predecessor, $scope, $transfer->deliveryGeneration))) {
            unset($plaintext);
            throw new HmacCredentialTransferRefused;
        }

        $encrypted = $this->keyring->encrypt($plaintext);
        unset($plaintext);

        $credential = new StoredHmacCredential(
            id: $transfer->issuerCredentialId,
            purpose: CredentialPurpose::Signing,
            subject: $scope->subject,
            status: CredentialStatus::Active,
            secretCiphertext: $encrypted->ciphertext,
            secretKeyVersion: $encrypted->keyVersion,
            deliveredAt: $transfer->deliveredAt,
            deliveryGeneration: $transfer->deliveryGeneration,
            deliveryFingerprint: $transfer->deliveryFingerprint,
            predecessorCredentialId: $transfer->predecessorCredentialId,
            activatedAt: CarbonImmutable::instance(now()),
            expiresAt: $transfer->credentialExpiresAt,
            revokedAt: null,
            scope: $scope,
            materialRole: CredentialMaterialRole::VerificationCopy,
            scopeHash: StoredHmacCredential::scopeHash($scope, CredentialMaterialRole::VerificationCopy),
        );
        $this->credentials->insertVerificationCopy($credential);

        return new InstalledHmacCredential($credential->id, $predecessor?->id, $scope);
    }

    private function validPredecessor(
        StoredHmacCredential $credential,
        BoundCredentialScope $scope,
        int $generation,
    ): bool {
        return $credential->purpose === CredentialPurpose::Signing
            && $credential->isActiveAt(now())
            && $credential->deliveryGeneration <= $generation
            && $credential->exactlyMatchesBoundScope($scope, CredentialMaterialRole::VerificationCopy);
    }

    private function idempotentResult(
        BoundCredentialScope $scope,
        string $credentialId,
        string $fingerprint,
        ?string $predecessorId,
    ): ?InstalledHmacCredential {
        $credential = $this->credentials->find($credentialId);

        if (! $credential instanceof StoredHmacCredential
            || ! hash_equals($credential->id, $credentialId)
            || $credential->purpose !== CredentialPurpose::Signing
            || $credential->status !== CredentialStatus::Active
            || ! hash_equals($credential->deliveryFingerprint, $fingerprint)
            || $credential->predecessorCredentialId !== $predecessorId
            || ! $credential->exactlyMatchesBoundScope($scope, CredentialMaterialRole::VerificationCopy)) {
            return null;
        }

        return new InstalledHmacCredential($credentialId, $predecessorId, $scope);
    }
}
