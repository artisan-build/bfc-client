<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Actions;

use ArtisanBuild\BfcClient\AppPurposeRegistry;
use ArtisanBuild\BfcClient\BoundCredentialScope;
use ArtisanBuild\BfcClient\ClaimedHmacCredential;
use ArtisanBuild\BfcClient\Contracts\HmacCredentialIssuerClient;
use ArtisanBuild\BfcClient\CredentialPurpose;
use ArtisanBuild\BfcClient\CredentialStatus;
use ArtisanBuild\BfcClient\Exceptions\HmacCredentialTransferRefused;
use ArtisanBuild\BfcClient\Hmac\HmacEnvelope;
use ArtisanBuild\BfcClient\Hmac\HmacKeyring;
use ArtisanBuild\BfcClient\HmacMaterialRole;
use ArtisanBuild\BfcClient\InstalledHmacCredential;
use ArtisanBuild\BfcClient\SensitiveString;
use ArtisanBuild\BfcClient\Storage\HmacCredential;
use ArtisanBuild\BfcClient\Storage\HmacCredentialBinding;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

final class InstallHmacCredentialFromClaim
{
    public function __construct(
        private readonly AppPurposeRegistry $purposes,
        private readonly HmacKeyring $keyring,
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
            return DB::transaction(function () use ($expectedScope, $claimed, &$deliveryValidated): InstalledHmacCredential {
                return $this->install($expectedScope, $claimed, $deliveryValidated);
            });
        } catch (QueryException $failure) {
            $state = (string) ($failure->errorInfo[0] ?? $failure->getCode());

            if (! $deliveryValidated || ! in_array($state, ['23000', '23505', '40001', '40P01'], true)) {
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
            $locked[$id] = HmacCredential::query()->whereKey($id)->lockForUpdate()->first();
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

        if ($existing instanceof HmacCredential) {
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

        if (($transfer->predecessorCredentialId !== null && ! $predecessor instanceof HmacCredential)
            || ($predecessor instanceof HmacCredential
                && ! $this->validPredecessor($predecessor, $scope, $transfer->deliveryGeneration))) {
            unset($plaintext);
            throw new HmacCredentialTransferRefused;
        }

        $encrypted = $this->keyring->encrypt($plaintext);
        unset($plaintext);

        $credential = new HmacCredential;
        $credential->forceFill([
            'id' => $transfer->issuerCredentialId,
            'purpose' => CredentialPurpose::Signing,
            'subject_type' => $scope->subject->type,
            'subject_ref' => $scope->subject->ref,
            'status' => CredentialStatus::Active,
            'secret_ciphertext' => $encrypted->ciphertext,
            'secret_key_version' => $encrypted->keyVersion,
            'delivered_at' => $transfer->deliveredAt,
            'delivered_generation' => $transfer->deliveryGeneration,
            'delivery_fingerprint' => $transfer->deliveryFingerprint,
            'predecessor_credential_id' => $transfer->predecessorCredentialId,
            'activated_at' => now(),
            'expires_at' => $transfer->credentialExpiresAt,
        ])->save();
        HmacCredentialBinding::createVerificationCopy($credential, $scope);

        return new InstalledHmacCredential($credential->id, $predecessor?->id, $scope);
    }

    private function validPredecessor(
        HmacCredential $credential,
        BoundCredentialScope $scope,
        int $generation,
    ): bool {
        $binding = HmacCredentialBinding::query()->whereKey($credential->id)->lockForUpdate()->first();

        return $credential->purpose === CredentialPurpose::Signing
            && $credential->status === CredentialStatus::Active
            && $credential->revoked_at === null
            && ($credential->expires_at === null || $credential->expires_at->isFuture())
            && $credential->delivered_generation <= $generation
            && $binding instanceof HmacCredentialBinding
            && $binding->exactlyMatches($credential, $scope, HmacMaterialRole::VerificationCopy);
    }

    private function idempotentResult(
        BoundCredentialScope $scope,
        string $credentialId,
        string $fingerprint,
        ?string $predecessorId,
    ): ?InstalledHmacCredential {
        $credential = HmacCredential::query()->whereKey($credentialId)->first();
        $binding = HmacCredentialBinding::query()->whereKey($credentialId)->first();

        if (! $credential instanceof HmacCredential
            || ! $binding instanceof HmacCredentialBinding
            || $credential->purpose !== CredentialPurpose::Signing
            || $credential->status !== CredentialStatus::Active
            || ! hash_equals($credential->delivery_fingerprint, $fingerprint)
            || $credential->predecessor_credential_id !== $predecessorId
            || ! $binding->exactlyMatches($credential, $scope, HmacMaterialRole::VerificationCopy)) {
            return null;
        }

        return new InstalledHmacCredential($credentialId, $predecessorId, $scope);
    }
}
