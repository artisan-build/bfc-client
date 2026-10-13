<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Hmac;

use ArtisanBuild\BfcClient\AppPurposeRegistry;
use ArtisanBuild\BfcClient\BoundCredentialScope;
use ArtisanBuild\BfcClient\CredentialPurpose;
use ArtisanBuild\BfcClient\Exceptions\HmacKeyUnreadable;
use ArtisanBuild\BfcClient\Exceptions\HmacVerificationFailed;
use ArtisanBuild\BfcClient\HmacMaterialRole;
use ArtisanBuild\BfcClient\Storage\HmacCredential;
use ArtisanBuild\BfcClient\Storage\HmacCredentialBinding;
use ArtisanBuild\BfcClient\VerifiedHmacCredential;
use Illuminate\Support\Facades\Cache;

final class HmacVerifier
{
    public function __construct(
        private readonly HmacKeyring $keyring,
        private readonly AppPurposeRegistry $appPurposes,
    ) {}

    public function verifyBound(
        BoundCredentialScope $scope,
        string $header,
        string $body,
    ): VerifiedHmacCredential {
        [$envelope, $claimedAlgorithm, $signature] = HmacEnvelope::parse($header);

        if ($claimedAlgorithm !== HmacEnvelope::ALGORITHM) {
            throw HmacVerificationFailed::algorithmRejected($claimedAlgorithm);
        }

        if (! hash_equals($scope->audience, $envelope->audience)) {
            throw HmacVerificationFailed::wrongAudience();
        }

        $tolerance = $this->timestampTolerance();

        if (abs(now()->getTimestamp() - $envelope->timestamp) > $tolerance) {
            throw HmacVerificationFailed::staleTimestamp($tolerance);
        }

        if ($this->appPurposes->purpose($scope->appPurpose) !== CredentialPurpose::Signing) {
            throw HmacVerificationFailed::unusableKey();
        }

        $credential = HmacCredential::query()
            ->whereKey($envelope->keyId)
            ->where('purpose', CredentialPurpose::Signing->value)
            ->active()
            ->first();
        $binding = $credential instanceof HmacCredential
            ? HmacCredentialBinding::query()->whereKey($credential->id)->first()
            : null;
        $role = $binding?->material_role;

        if (! $credential instanceof HmacCredential
            || ! $binding instanceof HmacCredentialBinding
            || ! $role instanceof HmacMaterialRole
            || ! in_array($role, [HmacMaterialRole::Originator, HmacMaterialRole::VerificationCopy], true)
            || ! $binding->exactlyMatches($credential, $scope, $role)) {
            throw HmacVerificationFailed::unusableKey();
        }

        if ($credential->secret_ciphertext === null) {
            throw HmacKeyUnreadable::missingCiphertext();
        }

        $expected = hash_hmac(
            'sha256',
            $envelope->canonical($body),
            $this->keyring->decrypt($credential->secret_ciphertext, $credential->secret_key_version),
        );

        if (! hash_equals($expected, $signature)) {
            throw HmacVerificationFailed::invalidSignature();
        }

        $this->consumeReplayRateAndUsage($credential, $envelope, $tolerance);

        return new VerifiedHmacCredential($credential->id, $scope);
    }

    private function consumeReplayRateAndUsage(
        HmacCredential $credential,
        HmacEnvelope $envelope,
        int $tolerance,
    ): void {
        $windowSeconds = $tolerance * 2 + 60;
        $nonceKey = 'bfc-client:hmac:nonce:'.hash('sha256', $envelope->keyId.'|'.$envelope->nonce);

        if (Cache::has($nonceKey)) {
            throw HmacVerificationFailed::replayedNonce();
        }

        $ceiling = $this->rateCeiling();
        $rateKey = 'bfc-client:hmac:rate:'.$envelope->keyId;
        Cache::add($rateKey, 0, $windowSeconds);

        if ((int) Cache::increment($rateKey) > $ceiling) {
            throw HmacVerificationFailed::rateLimited($ceiling);
        }

        if (! Cache::add($nonceKey, 1, $windowSeconds)) {
            Cache::decrement($rateKey);
            throw HmacVerificationFailed::replayedNonce();
        }

        HmacCredential::query()->whereKey($credential->id)->update(['last_used_at' => now()]);
    }

    private function rateCeiling(): int
    {
        $ceiling = config('bfc-client.hmac.verification_rate_ceiling', 1000);

        return is_numeric($ceiling) && (int) $ceiling > 0 ? (int) $ceiling : 1000;
    }

    private function timestampTolerance(): int
    {
        $tolerance = config('bfc-client.hmac.timestamp_tolerance_seconds', 300);

        return is_numeric($tolerance) && (int) $tolerance > 0 ? (int) $tolerance : 300;
    }
}
