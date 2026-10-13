<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Contracts;

use ArtisanBuild\BfcClient\StoredHmacCredential;
use Carbon\CarbonImmutable;
use Closure;
use Throwable;

interface HmacCredentialStore
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function transaction(Closure $operation): mixed;

    public function find(string $credentialId, bool $lockForUpdate = false): ?StoredHmacCredential;

    public function insertVerificationCopy(StoredHmacCredential $credential): void;

    public function allowsAfterSignature(StoredHmacCredential $credential): bool;

    public function stampUsage(string $credentialId, CarbonImmutable $usedAt): void;

    public function hasKeyVersionOtherThan(string $writeVersion): bool;

    public function isConcurrencyConflict(Throwable $failure): bool;
}
