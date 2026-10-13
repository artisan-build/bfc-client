<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Exceptions;

use ArtisanBuild\BfcClient\Hmac\HmacEnvelope;
use RuntimeException;

final class HmacVerificationFailed extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function malformedHeader(): self
    {
        return new self('The signature header does not fit the documented wire form.', 'malformed_header');
    }

    public static function algorithmRejected(string $claimed): self
    {
        return new self(sprintf(
            'The signature claims algorithm "%s"; this verifier pins %s and negotiates nothing.',
            $claimed,
            HmacEnvelope::ALGORITHM,
        ), 'algorithm_rejected');
    }

    public static function wrongAudience(): self
    {
        return new self('The signed audience is not this verifier\'s audience.', 'wrong_audience');
    }

    public static function audienceNotConfigured(): self
    {
        return new self('HMAC verification requires an explicitly configured audience.', 'audience_not_configured');
    }

    public static function staleTimestamp(int $toleranceSeconds): self
    {
        return new self(sprintf(
            'The signed timestamp is outside the ±%d second acceptance bound.',
            $toleranceSeconds,
        ), 'stale_timestamp');
    }

    public static function unusableKey(): self
    {
        return new self('No usable signing key matches the presented key id for this scope.', 'unusable_key');
    }

    public static function invalidSignature(): self
    {
        return new self('The signature does not match the canonical envelope.', 'invalid_signature');
    }

    public static function replayedNonce(): self
    {
        return new self('The signed nonce was already consumed inside the replay window.', 'replayed_nonce');
    }

    public static function rateLimited(int $ceiling): self
    {
        return new self(sprintf(
            'This key exceeded the ceiling of %d accepted verifications inside the replay window.',
            $ceiling,
        ), 'rate_limited');
    }
}
