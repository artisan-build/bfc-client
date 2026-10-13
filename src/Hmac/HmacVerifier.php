<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Hmac;

use ArtisanBuild\BfcClient\BoundCredentialScope;
use ArtisanBuild\BfcClient\Contracts\HmacAppPurposeRegistry;
use ArtisanBuild\BfcClient\Contracts\HmacCredentialStore;
use ArtisanBuild\BfcClient\Contracts\HmacVerificationConfiguration;
use ArtisanBuild\BfcClient\CredentialMaterialRole;
use ArtisanBuild\BfcClient\CredentialPurpose;
use ArtisanBuild\BfcClient\Exceptions\HmacKeyUnreadable;
use ArtisanBuild\BfcClient\Exceptions\HmacVerificationFailed;
use ArtisanBuild\BfcClient\StoredHmacCredential;
use ArtisanBuild\BfcClient\Subject;
use ArtisanBuild\BfcClient\VerifiedHmacCredential;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class HmacVerifier
{
    public function __construct(
        private readonly HmacKeyring $keyring,
        private readonly HmacAppPurposeRegistry $appPurposes,
        private readonly HmacCredentialStore $credentials,
        private readonly HmacVerificationConfiguration $configuration,
    ) {}

    public function verify(
        Subject $subject,
        string $header,
        string $body,
        ?string $audience = null,
    ): VerifiedHmacCredential {
        [$envelope, $signature] = $this->parseAndPin($header);
        $expectedAudience = $this->configuredAudience($audience);
        $tolerance = $this->validateAudienceAndTimestamp($envelope, $expectedAudience);
        $credential = $this->credentials->find($envelope->keyId);

        if (! $credential instanceof StoredHmacCredential
            || ! hash_equals($credential->id, $envelope->keyId)
            || ! $credential->isActiveAt(now())
            || ! $credential->exactlyMatchesUnboundSubject($subject)) {
            throw HmacVerificationFailed::unusableKey();
        }

        $this->verifySignatureAndConsume($credential, $envelope, $signature, $body, $tolerance);

        return new VerifiedHmacCredential($credential->id, null);
    }

    public function verifyBound(
        BoundCredentialScope $scope,
        string $header,
        string $body,
    ): VerifiedHmacCredential {
        [$envelope, $signature] = $this->parseAndPin($header);
        $tolerance = $this->validateAudienceAndTimestamp($envelope, $scope->audience);

        if ($this->appPurposes->purpose($scope->appPurpose) !== CredentialPurpose::Signing) {
            throw HmacVerificationFailed::unusableKey();
        }

        $credential = $this->credentials->find($envelope->keyId);
        $role = $credential?->materialRole;

        if (! $credential instanceof StoredHmacCredential
            || ! hash_equals($credential->id, $envelope->keyId)
            || ! $credential->isActiveAt(now())
            || ! $role instanceof CredentialMaterialRole
            || ! in_array($role, [CredentialMaterialRole::Originator, CredentialMaterialRole::VerificationCopy], true)
            || ! $credential->exactlyMatchesBoundScope($scope, $role)) {
            throw HmacVerificationFailed::unusableKey();
        }

        $this->verifySignatureAndConsume($credential, $envelope, $signature, $body, $tolerance);

        return new VerifiedHmacCredential($credential->id, $scope);
    }

    /** @return array{HmacEnvelope, string} */
    private function parseAndPin(string $header): array
    {
        [$envelope, $claimedAlgorithm, $signature] = HmacEnvelope::parse($header);

        if ($claimedAlgorithm !== HmacEnvelope::ALGORITHM) {
            throw HmacVerificationFailed::algorithmRejected($claimedAlgorithm);
        }

        return [$envelope, $signature];
    }

    private function validateAudienceAndTimestamp(HmacEnvelope $envelope, string $expectedAudience): int
    {
        if (! hash_equals($expectedAudience, $envelope->audience)) {
            throw HmacVerificationFailed::wrongAudience();
        }

        $tolerance = $this->configuration->timestampToleranceSeconds();

        if (abs(now()->getTimestamp() - $envelope->timestamp) > $tolerance) {
            throw HmacVerificationFailed::staleTimestamp($tolerance);
        }

        return $tolerance;
    }

    private function verifySignatureAndConsume(
        StoredHmacCredential $credential,
        HmacEnvelope $envelope,
        string $signature,
        string $body,
        int $tolerance,
    ): void {
        if ($credential->secretCiphertext === null) {
            throw HmacKeyUnreadable::missingCiphertext();
        }

        $expected = hash_hmac(
            'sha256',
            $envelope->canonical($body),
            $this->keyring->decrypt($credential->secretCiphertext, $credential->secretKeyVersion),
        );

        if (! hash_equals($expected, $signature)) {
            throw HmacVerificationFailed::invalidSignature();
        }

        if (! $this->credentials->allowsAfterSignature($credential)) {
            throw HmacVerificationFailed::unusableKey();
        }

        $this->consumeReplayRateAndUsage($credential, $envelope, $tolerance);
    }

    private function consumeReplayRateAndUsage(
        StoredHmacCredential $credential,
        HmacEnvelope $envelope,
        int $tolerance,
    ): void {
        $windowSeconds = $tolerance * 2 + 60;
        $nonceKey = 'bfc:hmac:nonce:'.hash('sha256', $credential->id.'|'.$envelope->nonce);

        if (Cache::has($nonceKey)) {
            throw HmacVerificationFailed::replayedNonce();
        }

        $ceiling = $this->configuration->verificationRateCeiling();
        $rateKey = 'bfc:hmac:rate:'.$credential->id;
        Cache::add($rateKey, 0, $windowSeconds);

        if ((int) Cache::increment($rateKey) > $ceiling) {
            Cache::decrement($rateKey);
            throw HmacVerificationFailed::rateLimited($ceiling);
        }

        if (! Cache::add($nonceKey, 1, $windowSeconds)) {
            Cache::decrement($rateKey);
            throw HmacVerificationFailed::replayedNonce();
        }

        $this->credentials->stampUsage($credential->id, CarbonImmutable::instance(now()));
    }

    private function configuredAudience(?string $supplied): string
    {
        $configured = $this->configuration->audience();

        if ($configured === null) {
            throw HmacVerificationFailed::audienceNotConfigured();
        }

        if ($supplied !== null && ! hash_equals($configured, $supplied)) {
            throw HmacVerificationFailed::wrongAudience();
        }

        return $configured;
    }
}
