<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Storage;

use ArtisanBuild\BfcClient\BoundCredentialScope;
use ArtisanBuild\BfcClient\Contracts\HmacCredentialStore;
use ArtisanBuild\BfcClient\CredentialMaterialRole;
use ArtisanBuild\BfcClient\StoredHmacCredential;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final class EloquentHmacCredentialStore implements HmacCredentialStore
{
    public function transaction(Closure $operation): mixed
    {
        return DB::transaction($operation);
    }

    public function find(string $credentialId, bool $lockForUpdate = false): ?StoredHmacCredential
    {
        $credentialQuery = HmacCredential::query()->whereKey($credentialId);
        $bindingQuery = HmacCredentialBinding::query()->whereKey($credentialId);

        if ($lockForUpdate) {
            $credentialQuery->lockForUpdate();
            $bindingQuery->lockForUpdate();
        }

        $credential = $credentialQuery->first();

        if (! $credential instanceof HmacCredential) {
            return null;
        }

        $binding = $bindingQuery->first();
        $scope = $binding instanceof HmacCredentialBinding
            ? new BoundCredentialScope(
                $binding->app_purpose,
                $credential->subject(),
                $binding->installation_ref,
                $binding->application_ref,
                $binding->audience,
            )
            : null;

        return new StoredHmacCredential(
            id: $credential->id,
            purpose: $credential->purpose,
            subject: $credential->subject(),
            status: $credential->status,
            secretCiphertext: $credential->secret_ciphertext,
            secretKeyVersion: $credential->secret_key_version,
            deliveredAt: CarbonImmutable::instance($credential->delivered_at),
            deliveryGeneration: $credential->delivered_generation,
            deliveryFingerprint: $credential->delivery_fingerprint,
            predecessorCredentialId: $credential->predecessor_credential_id,
            activatedAt: CarbonImmutable::instance($credential->activated_at),
            expiresAt: $credential->expires_at?->toImmutable(),
            revokedAt: $credential->revoked_at?->toImmutable(),
            scope: $scope,
            materialRole: $binding?->material_role,
            algorithm: $binding instanceof HmacCredentialBinding ? $binding->algorithm : 'hmac-sha256',
            scopeHash: $binding?->scope_hash,
        );
    }

    public function insertVerificationCopy(StoredHmacCredential $credential): void
    {
        if ($credential->scope === null || $credential->materialRole !== CredentialMaterialRole::VerificationCopy) {
            throw new LogicException('A verification copy requires its exact bound scope.');
        }

        $model = new HmacCredential;
        $model->forceFill([
            'id' => $credential->id,
            'purpose' => $credential->purpose,
            'subject_type' => $credential->subject->type,
            'subject_ref' => $credential->subject->ref,
            'status' => $credential->status,
            'secret_ciphertext' => $credential->secretCiphertext,
            'secret_key_version' => $credential->secretKeyVersion,
            'delivered_at' => $credential->deliveredAt,
            'delivered_generation' => $credential->deliveryGeneration,
            'delivery_fingerprint' => $credential->deliveryFingerprint,
            'predecessor_credential_id' => $credential->predecessorCredentialId,
            'activated_at' => $credential->activatedAt,
            'expires_at' => $credential->expiresAt,
            'revoked_at' => $credential->revokedAt,
        ])->save();
        HmacCredentialBinding::createVerificationCopy($model, $credential->scope);
    }

    public function allowsAfterSignature(StoredHmacCredential $credential): bool
    {
        return true;
    }

    public function stampUsage(string $credentialId, CarbonImmutable $usedAt): void
    {
        HmacCredential::query()->whereKey($credentialId)->update(['last_used_at' => $usedAt]);
    }

    public function hasKeyVersionOtherThan(string $writeVersion): bool
    {
        return HmacCredential::query()
            ->where(function ($query) use ($writeVersion): void {
                $query->whereNull('secret_key_version')
                    ->orWhere('secret_key_version', '!=', $writeVersion);
            })
            ->exists();
    }

    public function isConcurrencyConflict(Throwable $failure): bool
    {
        if (! $failure instanceof QueryException) {
            return false;
        }

        $state = (string) ($failure->errorInfo[0] ?? $failure->getCode());

        return in_array($state, ['23000', '23505', '40001', '40P01'], true);
    }
}
