<?php

declare(strict_types=1);

use ArtisanBuild\BfcClient\Actions\InstallHmacCredentialFromClaim;
use ArtisanBuild\BfcClient\BoundCredentialScope;
use ArtisanBuild\BfcClient\ClaimedHmacCredential;
use ArtisanBuild\BfcClient\Contracts\HmacCredentialIssuerClient;
use ArtisanBuild\BfcClient\Contracts\HmacCredentialStore;
use ArtisanBuild\BfcClient\CredentialKind;
use ArtisanBuild\BfcClient\CredentialMaterialRole;
use ArtisanBuild\BfcClient\CredentialPurpose;
use ArtisanBuild\BfcClient\CredentialStatus;
use ArtisanBuild\BfcClient\Exceptions\HmacCredentialTransferRefused;
use ArtisanBuild\BfcClient\Exceptions\HmacKeyUnreadable;
use ArtisanBuild\BfcClient\Exceptions\HmacVerificationFailed;
use ArtisanBuild\BfcClient\Hmac\HmacEnvelope;
use ArtisanBuild\BfcClient\Hmac\HmacKeyring;
use ArtisanBuild\BfcClient\Hmac\HmacVerifier;
use ArtisanBuild\BfcClient\HmacCredentialTransfer;
use ArtisanBuild\BfcClient\ImportedHmacSecret;
use ArtisanBuild\BfcClient\IssuerHmacCutoverReceipt;
use ArtisanBuild\BfcClient\SensitiveString;
use ArtisanBuild\BfcClient\Storage\HmacCredential;
use ArtisanBuild\BfcClient\Storage\HmacCredentialBinding;
use ArtisanBuild\BfcClient\StoredHmacCredential;
use ArtisanBuild\BfcClient\Subject;
use ArtisanBuild\BfcClient\SubjectType;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('app.previous_keys', []);
    config()->set('cache.default', 'array');
    config()->set('bfc-client.credentials.app_purposes', [
        'matte.callback' => CredentialPurpose::Signing->value,
    ]);
    config()->set('bfc-client.hmac.timestamp_tolerance_seconds', 300);
    config()->set('bfc-client.hmac.verification_rate_ceiling', 1000);
});

function clientHmacScope(
    string $appPurpose = 'matte.callback',
    string $subjectRef = 'installation-subject',
    string $installation = 'installation-1',
    string $application = 'matte',
    string $audience = 'https://client.example/callback',
): BoundCredentialScope {
    return new BoundCredentialScope(
        $appPurpose,
        new Subject(SubjectType::Installation, $subjectRef),
        $installation,
        $application,
        $audience,
    );
}

/** @param array<string, mixed> $changes */
function clientClaimed(BoundCredentialScope $scope, string $key, array $changes = []): ClaimedHmacCredential
{
    $generation = (int) ($changes['generation'] ?? 3);
    $deliveredAt = $changes['delivered_at'] ?? CarbonImmutable::now()->startOfSecond();
    $facts = array_merge([
        'id' => (string) Str::uuid(),
        'scope' => $scope,
        'algorithm' => HmacEnvelope::ALGORITHM,
        'credential_expires_at' => CarbonImmutable::now()->addDay(),
        'generation' => $generation,
        'fingerprint' => app(HmacKeyring::class)->deliveryFingerprint($key, $generation),
        'predecessor' => null,
        'status' => CredentialStatus::Pending,
        'delivered_at' => $deliveredAt,
        'transfer_expires_at' => $deliveredAt->addSeconds(60),
        'key' => $key,
    ], $changes);

    return ClaimedHmacCredential::fromIssuerResponse(
        HmacCredentialTransfer::fromIssuerResponse(
            $facts['id'],
            $facts['scope'],
            $facts['algorithm'],
            $facts['credential_expires_at'],
            $facts['generation'],
            $facts['fingerprint'],
            $facts['predecessor'],
            $facts['status'],
            $facts['delivered_at'],
            $facts['transfer_expires_at'],
        ),
        ImportedHmacSecret::fromIssuerResponse($facts['key']),
    );
}

function clientIssuer(ClaimedHmacCredential $claimed): HmacCredentialIssuerClient
{
    return new class($claimed) implements HmacCredentialIssuerClient
    {
        public function __construct(private readonly ClaimedHmacCredential $claimed) {}

        public function claim(BoundCredentialScope $expectedScope, SensitiveString $claimCode): ClaimedHmacCredential
        {
            $claimCode->reveal();

            return $this->claimed;
        }

        public function activate(BoundCredentialScope $expectedScope, ?string $predecessorId, string $replacementId, string $deliveryFingerprint): IssuerHmacCutoverReceipt
        {
            throw new LogicException('Not used by this receiver test.');
        }

        public function cutoverStatus(BoundCredentialScope $expectedScope, ?string $predecessorId, string $replacementId): IssuerHmacCutoverReceipt
        {
            throw new LogicException('Not used by this receiver test.');
        }
    };
}

function storedClientHmac(
    BoundCredentialScope $scope,
    string $key,
    ?CredentialMaterialRole $role = CredentialMaterialRole::VerificationCopy,
    CredentialStatus $status = CredentialStatus::Active,
    ?string $id = null,
    int $generation = 1,
): HmacCredential {
    $encrypted = app(HmacKeyring::class)->encrypt($key);
    $credential = new HmacCredential;
    $credential->forceFill([
        'id' => $id ?? (string) Str::uuid(),
        'purpose' => CredentialPurpose::Signing,
        'subject_type' => $scope->subject->type,
        'subject_ref' => $scope->subject->ref,
        'status' => $status,
        'secret_ciphertext' => $encrypted->ciphertext,
        'secret_key_version' => $encrypted->keyVersion,
        'delivered_at' => now(),
        'delivered_generation' => $generation,
        'delivery_fingerprint' => app(HmacKeyring::class)->deliveryFingerprint($key, $generation),
        'activated_at' => now(),
    ])->save();

    match ($role) {
        CredentialMaterialRole::Originator => HmacCredentialBinding::createOriginator($credential, $scope),
        CredentialMaterialRole::VerificationCopy => HmacCredentialBinding::createVerificationCopy($credential, $scope),
        null => null,
    };

    return $credential;
}

function signedClientHeader(
    HmacCredential $credential,
    string $key,
    BoundCredentialScope $scope,
    string $body,
    ?int $timestamp = null,
    ?string $nonce = null,
    ?string $audience = null,
): string {
    $envelope = new HmacEnvelope(
        $credential->id,
        'matte.completed',
        $timestamp ?? now()->getTimestamp(),
        $nonce ?? bin2hex(random_bytes(16)),
        $audience ?? $scope->audience,
    );

    return $envelope->headerValue(hash_hmac('sha256', $envelope->canonical($body), $key));
}

function hmacFailureReason(Closure $operation): string
{
    try {
        $operation();
    } catch (HmacVerificationFailed $failure) {
        return $failure->reason;
    }

    throw new LogicException('The operation did not fail verification.');
}

final class RecordingHmacCredentialStore implements HmacCredentialStore
{
    public int $transactions = 0;

    public int $finds = 0;

    public int $inserts = 0;

    public int $usageStamps = 0;

    public int $admissions = 0;

    public int $versionScans = 0;

    public bool $allowAfterSignature = true;

    public bool $caseInsensitiveFind = false;

    public function __construct(private readonly HmacCredentialStore $delegate) {}

    public function transaction(Closure $operation): mixed
    {
        $this->transactions++;

        return $this->delegate->transaction($operation);
    }

    public function find(string $credentialId, bool $lockForUpdate = false): ?StoredHmacCredential
    {
        $this->finds++;

        $credential = $this->delegate->find($credentialId, $lockForUpdate);

        if ($credential === null && $this->caseInsensitiveFind) {
            return $this->delegate->find(strtolower($credentialId), $lockForUpdate);
        }

        return $credential;
    }

    public function insertVerificationCopy(StoredHmacCredential $credential): void
    {
        $this->inserts++;
        $this->delegate->insertVerificationCopy($credential);
    }

    public function allowsAfterSignature(StoredHmacCredential $credential): bool
    {
        $this->admissions++;

        return $this->allowAfterSignature && $this->delegate->allowsAfterSignature($credential);
    }

    public function stampUsage(string $credentialId, CarbonImmutable $usedAt): void
    {
        $this->usageStamps++;
        $this->delegate->stampUsage($credentialId, $usedAt);
    }

    public function hasKeyVersionOtherThan(string $writeVersion): bool
    {
        $this->versionScans++;

        return $this->delegate->hasKeyVersionOtherThan($writeVersion);
    }

    public function isConcurrencyConflict(Throwable $failure): bool
    {
        return $this->delegate->isConcurrencyConflict($failure);
    }
}

final class ClassifiedStoreConflict extends RuntimeException {}

final class ConflictAfterInsertHmacCredentialStore implements HmacCredentialStore
{
    public function __construct(
        private readonly HmacCredentialStore $delegate,
        private readonly bool $changeFingerprint = false,
        private readonly bool $lowercaseId = false,
    ) {}

    public function transaction(Closure $operation): mixed
    {
        return $operation();
    }

    public function find(string $credentialId, bool $lockForUpdate = false): ?StoredHmacCredential
    {
        return $this->delegate->find($credentialId, $lockForUpdate)
            ?? $this->delegate->find(strtolower($credentialId), $lockForUpdate);
    }

    public function insertVerificationCopy(StoredHmacCredential $credential): void
    {
        $this->delegate->insertVerificationCopy(copyStoredHmacCredential(
            $credential,
            id: $this->lowercaseId ? strtolower($credential->id) : $credential->id,
            fingerprint: $this->changeFingerprint ? str_repeat('0', 16) : $credential->deliveryFingerprint,
        ));

        throw new ClassifiedStoreConflict('classified conflict');
    }

    public function allowsAfterSignature(StoredHmacCredential $credential): bool
    {
        return $this->delegate->allowsAfterSignature($credential);
    }

    public function stampUsage(string $credentialId, CarbonImmutable $usedAt): void
    {
        $this->delegate->stampUsage($credentialId, $usedAt);
    }

    public function hasKeyVersionOtherThan(string $writeVersion): bool
    {
        return $this->delegate->hasKeyVersionOtherThan($writeVersion);
    }

    public function isConcurrencyConflict(Throwable $failure): bool
    {
        return $failure instanceof ClassifiedStoreConflict;
    }
}

final class ReentrantRaceArrayStore extends ArrayStore
{
    public ?Closure $afterIncrement = null;

    public function increment($key, $value = 1)
    {
        $result = parent::increment($key, $value);

        if ($value === 1 && $this->afterIncrement !== null) {
            ($this->afterIncrement)($key);
        }

        return $result;
    }
}

function copyStoredHmacCredential(
    StoredHmacCredential $credential,
    ?string $id = null,
    ?string $fingerprint = null,
): StoredHmacCredential {
    return new StoredHmacCredential(
        id: $id ?? $credential->id,
        purpose: $credential->purpose,
        subject: $credential->subject,
        status: $credential->status,
        secretCiphertext: $credential->secretCiphertext,
        secretKeyVersion: $credential->secretKeyVersion,
        deliveredAt: $credential->deliveredAt,
        deliveryGeneration: $credential->deliveryGeneration,
        deliveryFingerprint: $fingerprint ?? $credential->deliveryFingerprint,
        predecessorCredentialId: $credential->predecessorCredentialId,
        activatedAt: $credential->activatedAt,
        expiresAt: $credential->expiresAt,
        revokedAt: $credential->revokedAt,
        scope: $credential->scope,
        materialRole: $credential->materialRole,
        algorithm: $credential->algorithm,
        scopeHash: $credential->scopeHash,
    );
}

it('discovers the receiver migrations and resolves its public services', function (): void {
    expect(Schema::hasTable('bfc_client_hmac_credentials'))->toBeTrue()
        ->and(Schema::hasTable('bfc_client_hmac_bindings'))->toBeTrue()
        ->and(app(InstallHmacCredentialFromClaim::class))->toBeInstanceOf(InstallHmacCredentialFromClaim::class)
        ->and(app(HmacVerifier::class))->toBeInstanceOf(HmacVerifier::class)
        ->and(app(HmacKeyring::class))->toBeInstanceOf(HmacKeyring::class);
});

it('installs an exact bound signing transfer as a versioned encrypted verification copy', function (): void {
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $claimCode = bin2hex(random_bytes(32));
    $claimed = clientClaimed($scope, $key);

    $result = app(InstallHmacCredentialFromClaim::class)($scope, clientIssuer($claimed), $claimCode);
    $credential = HmacCredential::query()->findOrFail($result->credentialId);
    $binding = HmacCredentialBinding::query()->findOrFail($result->credentialId);
    $row = DB::table('bfc_client_hmac_credentials')->where('id', $result->credentialId)->first();

    expect($result->scope)->toBe($scope)
        ->and($result->algorithm)->toBe(HmacEnvelope::ALGORITHM)
        ->and($credential->purpose)->toBe(CredentialPurpose::Signing)
        ->and($credential->status)->toBe(CredentialStatus::Active)
        ->and($binding->material_role)->toBe(CredentialMaterialRole::VerificationCopy)
        ->and($binding->exactlyMatches($credential, $scope, CredentialMaterialRole::VerificationCopy))->toBeTrue()
        ->and($row->secret_ciphertext)->not->toBe($key)
        ->and($row->secret_ciphertext)->not->toContain($key)
        ->and($row->secret_key_version)->toBe(app(HmacKeyring::class)->writeVersion())
        ->and(app(HmacKeyring::class)->decrypt($row->secret_ciphertext, $row->secret_key_version))->toBe($key)
        ->and(json_encode($credential, JSON_THROW_ON_ERROR))->not->toContain((string) $row->secret_ciphertext, $key);
});

it('refuses every malformed transfer fact and every inexact scope without partial state', function (): void {
    $this->freezeTime();
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $delivered = CarbonImmutable::now()->startOfSecond();
    $wrongScopes = [
        'app purpose' => clientHmacScope('other.callback'),
        'subject type' => new BoundCredentialScope($scope->appPurpose, new Subject(SubjectType::Application, $scope->subject->ref), $scope->installation, $scope->application, $scope->audience),
        'subject ref' => clientHmacScope(subjectRef: 'other-subject'),
        'installation' => clientHmacScope(installation: 'other-installation'),
        'application' => clientHmacScope(application: 'other-application'),
        'audience' => clientHmacScope(audience: 'https://other.example/callback'),
    ];
    $cases = [
        ...array_map(static fn (BoundCredentialScope $wrong): array => ['scope' => $wrong], $wrongScopes),
        'algorithm' => ['algorithm' => 'hmac-sha1'],
        'issuer id' => ['id' => 'not-a-uuid'],
        'predecessor id' => ['predecessor' => 'not-a-uuid'],
        'generation' => ['generation' => 0, 'fingerprint' => str_repeat('0', 16)],
        'fingerprint shape' => ['fingerprint' => str_repeat('0', 15)],
        'fingerprint value' => ['fingerprint' => str_repeat('0', 16)],
        'future delivery' => ['delivered_at' => $delivered->addSecond(), 'transfer_expires_at' => $delivered->addSeconds(60)],
        'expired transfer' => ['delivered_at' => $delivered->subMinutes(2), 'transfer_expires_at' => $delivered->subMinute()],
        'reversed transfer window' => ['delivered_at' => $delivered, 'transfer_expires_at' => $delivered->subSecond()],
        'overlong transfer window' => ['delivered_at' => $delivered, 'transfer_expires_at' => $delivered->addSeconds(61)],
        'expired credential' => ['credential_expires_at' => $delivered->subSecond()],
        'source status' => ['status' => CredentialStatus::Active],
        'key shape' => ['key' => str_repeat('g', 64)],
    ];

    foreach ($cases as $label => $changes) {
        expect(fn () => app(InstallHmacCredentialFromClaim::class)(
            $scope,
            clientIssuer(clientClaimed($scope, $key, $changes)),
            bin2hex(random_bytes(32)),
        ))->toThrow(HmacCredentialTransferRefused::class);

        expect(HmacCredential::query()->count())->toBe(0, (string) $label)
            ->and(HmacCredentialBinding::query()->count())->toBe(0, (string) $label);
    }
});

it('accepts only a registered signing app purpose', function (): void {
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));

    foreach ([[], [$scope->appPurpose => 'consumption'], [$scope->appPurpose => 'unknown']] as $purposes) {
        config()->set('bfc-client.credentials.app_purposes', $purposes);

        expect(fn () => app(InstallHmacCredentialFromClaim::class)(
            $scope,
            clientIssuer(clientClaimed($scope, $key)),
            bin2hex(random_bytes(32)),
        ))->toThrow(HmacCredentialTransferRefused::class);
    }

    expect(HmacCredential::query()->count())->toBe(0);
});

it('makes exact redelivery idempotent and refuses conflicting redelivery', function (): void {
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $id = (string) Str::uuid();
    $install = app(InstallHmacCredentialFromClaim::class);

    $first = $install($scope, clientIssuer(clientClaimed($scope, $key, ['id' => $id])), bin2hex(random_bytes(32)));
    $ciphertext = HmacCredential::query()->findOrFail($id)->secret_ciphertext;
    $again = $install($scope, clientIssuer(clientClaimed($scope, $key, ['id' => $id])), bin2hex(random_bytes(32)));

    expect($again)->toEqual($first)
        ->and(HmacCredential::query()->count())->toBe(1)
        ->and(HmacCredentialBinding::query()->count())->toBe(1)
        ->and(HmacCredential::query()->findOrFail($id)->secret_ciphertext)->toBe($ciphertext);

    $changedKey = bin2hex(random_bytes(32));
    $storedFingerprint = HmacCredential::query()->findOrFail($id)->delivery_fingerprint;

    foreach ([
        ['key' => $changedKey, 'fingerprint' => $storedFingerprint],
        ['fingerprint' => str_repeat('0', 16)],
        ['predecessor' => (string) Str::uuid()],
    ] as $changes) {
        expect(fn () => $install(
            $scope,
            clientIssuer(clientClaimed($scope, $key, ['id' => $id, ...$changes])),
            bin2hex(random_bytes(32)),
        ))->toThrow(HmacCredentialTransferRefused::class);
    }

    expect(HmacCredential::query()->findOrFail($id)->secret_ciphertext)->toBe($ciphertext);
});

it('refuses missing, backwards, and scope-mismatched predecessor lineage', function (): void {
    $scope = clientHmacScope();
    $predecessorKey = bin2hex(random_bytes(32));
    $predecessor = storedClientHmac($scope, $predecessorKey, generation: 4);
    $replacementKey = bin2hex(random_bytes(32));
    $install = app(InstallHmacCredentialFromClaim::class);
    $before = HmacCredential::query()->count();

    foreach ([
        'missing' => ['predecessor' => (string) Str::uuid()],
        'backwards generation' => ['predecessor' => $predecessor->id, 'generation' => 3],
    ] as $label => $changes) {
        expect(fn () => $install(
            $scope,
            clientIssuer(clientClaimed($scope, $replacementKey, $changes)),
            bin2hex(random_bytes(32)),
        ))->toThrow(HmacCredentialTransferRefused::class);
        expect(HmacCredential::query()->count())->toBe($before, $label);
    }

    DB::table('bfc_client_hmac_bindings')->where('credential_id', $predecessor->id)->update([
        'application_ref' => 'wrong-application',
    ]);

    expect(fn () => $install(
        $scope,
        clientIssuer(clientClaimed($scope, $replacementKey, ['predecessor' => $predecessor->id, 'generation' => 5])),
        bin2hex(random_bytes(32)),
    ))->toThrow(HmacCredentialTransferRefused::class)
        ->and(HmacCredential::query()->count())->toBe($before);
});

it('keeps claim and key material out of transfer failures and serialization', function (): void {
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $claimCode = bin2hex(random_bytes(32));
    $fingerprint = str_repeat('f', 16);
    $claimed = clientClaimed($scope, $key, ['fingerprint' => $fingerprint]);

    try {
        app(InstallHmacCredentialFromClaim::class)($scope, clientIssuer($claimed), $claimCode);
        $this->fail('The transfer should have been refused.');
    } catch (HmacCredentialTransferRefused $failure) {
        expect($failure->getMessage())->not->toContain($key, $claimCode, $fingerprint);
    }

    $sealed = clientClaimed($scope, bin2hex(random_bytes(32)));

    expect(fn () => serialize($sealed))->toThrow(LogicException::class)
        ->and(fn () => json_encode($sealed, JSON_THROW_ON_ERROR))->toThrow(LogicException::class)
        ->and(fn () => serialize($sealed->transfer))->toThrow(LogicException::class)
        ->and(fn () => json_encode($sealed->secret, JSON_THROW_ON_ERROR))->toThrow(LogicException::class);
});

it('verifies a canonical body-bound signature for either allowed bound material role', function (CredentialMaterialRole $role): void {
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $credential = storedClientHmac($scope, $key, $role);
    $body = json_encode(['job' => (string) Str::uuid()], JSON_THROW_ON_ERROR);
    $header = signedClientHeader($credential, $key, $scope, $body);

    $verified = app(HmacVerifier::class)->verifyBound($scope, $header, $body);

    expect($verified->credentialId)->toBe($credential->id)
        ->and($verified->scope)->toBe($scope)
        ->and($verified->algorithm)->toBe(HmacEnvelope::ALGORITHM)
        ->and($credential->refresh()->last_used_at)->not->toBeNull();
})->with([
    'originator' => CredentialMaterialRole::Originator,
    'verification copy' => CredentialMaterialRole::VerificationCopy,
]);

it('pins canonical wire form, algorithm, body, signature, and audience to stable reasons', function (): void {
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $credential = storedClientHmac($scope, $key);
    $body = json_encode(['value' => random_int(1, PHP_INT_MAX)], JSON_THROW_ON_ERROR);
    $header = signedClientHeader($credential, $key, $scope, $body);
    preg_match('/ts=(\d+)/', $header, $timestamp);

    $cases = [
        'empty' => ['', 'malformed_header', $body],
        'trailing bytes' => [$header.'x', 'malformed_header', $body],
        'reordered fields' => [str_replace('alg=hmac-sha256,key=', 'key=x,alg=hmac-sha256,key=', $header), 'malformed_header', $body],
        'leading-zero timestamp' => [str_replace('ts='.$timestamp[1], 'ts=0'.$timestamp[1], $header), 'malformed_header', $body],
        'algorithm substitution' => [str_replace('alg=hmac-sha256', 'alg=hmac-sha1', $header), 'algorithm_rejected', $body],
        'audience substitution' => [str_replace('aud='.$scope->audience, 'aud=https://other.example/callback', $header), 'wrong_audience', $body],
        'signature substitution' => [substr($header, 0, -1).($header[-1] === '0' ? '1' : '0'), 'invalid_signature', $body],
        'body substitution' => [$header, 'invalid_signature', $body.'x'],
    ];

    foreach ($cases as $label => [$candidate, $reason, $candidateBody]) {
        expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verifyBound($scope, $candidate, $candidateBody)))
            ->toBe($reason, $label);
    }

    expect($credential->refresh()->last_used_at)->toBeNull();
});

it('enforces both inclusive timestamp boundaries and rejects one second beyond each', function (): void {
    $this->freezeTime();
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $credential = storedClientHmac($scope, $key);
    $now = now()->getTimestamp();

    foreach ([$now - 300, $now + 300] as $boundary) {
        $header = signedClientHeader($credential, $key, $scope, 'body', $boundary);
        expect(app(HmacVerifier::class)->verifyBound($scope, $header, 'body')->credentialId)->toBe($credential->id);
    }

    foreach ([$now - 301, $now + 301] as $outside) {
        $header = signedClientHeader($credential, $key, $scope, 'body', $outside);
        expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verifyBound($scope, $header, 'body')))
            ->toBe('stale_timestamp');
    }
});

it('selects a key only by exact active scope and refuses before decrypt, replay, rate, or usage effects', function (): void {
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $credential = storedClientHmac($scope, $key);
    $header = signedClientHeader($credential, $key, $scope, 'body');
    DB::table('bfc_client_hmac_credentials')->where('id', $credential->id)->update([
        'secret_key_version' => 'must-not-be-read',
    ]);
    $wrongScopes = [
        clientHmacScope('other.callback'),
        new BoundCredentialScope($scope->appPurpose, new Subject(SubjectType::Application, $scope->subject->ref), $scope->installation, $scope->application, $scope->audience),
        clientHmacScope(subjectRef: 'other-subject'),
        clientHmacScope(installation: 'other-installation'),
        clientHmacScope(application: 'other-application'),
    ];

    config()->set('bfc-client.credentials.app_purposes', [
        $scope->appPurpose => CredentialPurpose::Signing->value,
        'other.callback' => CredentialPurpose::Signing->value,
    ]);

    foreach ($wrongScopes as $wrongScope) {
        expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verifyBound($wrongScope, $header, 'body')))
            ->toBe('unusable_key');
    }

    expect(Cache::has('bfc:hmac:rate:'.$credential->id))->toBeFalse()
        ->and($credential->refresh()->last_used_at)->toBeNull();
});

it('refuses unknown, pending, revoked, expired, unbound, and unregistered keys uniformly', function (): void {
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $credential = storedClientHmac($scope, $key);
    $valid = signedClientHeader($credential, $key, $scope, 'body');
    $ghost = new HmacCredential;
    $ghost->forceFill(['id' => (string) Str::uuid()]);
    $unknown = signedClientHeader($ghost, $key, $scope, 'body');

    expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verifyBound($scope, $unknown, 'body')))->toBe('unusable_key');

    foreach ([
        ['status' => CredentialStatus::Pending->value, 'revoked_at' => null, 'expires_at' => null],
        ['status' => CredentialStatus::Active->value, 'revoked_at' => now(), 'expires_at' => null],
        ['status' => CredentialStatus::Active->value, 'revoked_at' => null, 'expires_at' => now()->subSecond()],
    ] as $state) {
        DB::table('bfc_client_hmac_credentials')->where('id', $credential->id)->update($state);
        expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verifyBound($scope, $valid, 'body')))->toBe('unusable_key');
    }

    DB::table('bfc_client_hmac_credentials')->where('id', $credential->id)->update([
        'status' => CredentialStatus::Active->value,
        'revoked_at' => null,
        'expires_at' => null,
    ]);
    DB::table('bfc_client_hmac_bindings')->where('credential_id', $credential->id)->delete();
    expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verifyBound($scope, $valid, 'body')))->toBe('unusable_key');

    config()->set('bfc-client.credentials.app_purposes', []);
    expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verifyBound($scope, $valid, 'body')))->toBe('unusable_key');
});

it('checks replay only after signature validity and spends no new rate budget on replays', function (): void {
    config()->set('bfc-client.hmac.verification_rate_ceiling', 3);
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $credential = storedClientHmac($scope, $key);
    $rateKey = 'bfc:hmac:rate:'.$credential->id;
    $invalidNonce = bin2hex(random_bytes(16));
    $invalid = signedClientHeader($credential, $key, $scope, 'body', nonce: $invalidNonce);
    $invalid = substr($invalid, 0, -1).($invalid[-1] === '0' ? '1' : '0');

    expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verifyBound($scope, $invalid, 'body')))->toBe('invalid_signature')
        ->and(Cache::has($rateKey))->toBeFalse()
        ->and(Cache::has('bfc:hmac:nonce:'.hash('sha256', $credential->id.'|'.$invalidNonce)))->toBeFalse();

    $captured = signedClientHeader($credential, $key, $scope, 'one');
    app(HmacVerifier::class)->verifyBound($scope, $captured, 'one');

    foreach (range(1, 5) as $attempt) {
        expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verifyBound($scope, $captured, 'one')))
            ->toBe('replayed_nonce', (string) $attempt);
    }

    expect(Cache::get($rateKey))->toBe(1);

    foreach (['two', 'three'] as $body) {
        app(HmacVerifier::class)->verifyBound($scope, signedClientHeader($credential, $key, $scope, $body), $body);
    }

    $overflowNonce = bin2hex(random_bytes(16));
    $overflow = signedClientHeader($credential, $key, $scope, 'four', nonce: $overflowNonce);
    expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verifyBound($scope, $overflow, 'four')))->toBe('rate_limited')
        ->and(Cache::has('bfc:hmac:nonce:'.hash('sha256', $credential->id.'|'.$overflowNonce)))->toBeFalse();
});

it('keeps nonce coverage through the last valid replay instant', function (): void {
    $this->freezeTime();
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $credential = storedClientHmac($scope, $key);
    $header = signedClientHeader($credential, $key, $scope, 'body', now()->getTimestamp() + 300);

    app(HmacVerifier::class)->verifyBound($scope, $header, 'body');
    $this->travel(600)->seconds();

    expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verifyBound($scope, $header, 'body')))
        ->toBe('replayed_nonce');
});

it('normalizes missing, wrong-version, and corrupt stored key material without exposing it', function (): void {
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $credential = storedClientHmac($scope, $key);
    $header = signedClientHeader($credential, $key, $scope, 'body');
    $original = $credential->getRawOriginal();
    $cases = [
        'missing ciphertext' => ['secret_ciphertext' => null, 'secret_key_version' => $original['secret_key_version']],
        'missing version' => ['secret_ciphertext' => $original['secret_ciphertext'], 'secret_key_version' => null],
        'wrong version' => ['secret_ciphertext' => $original['secret_ciphertext'], 'secret_key_version' => str_repeat('0', 16)],
        'corrupt ciphertext' => ['secret_ciphertext' => 'not-ciphertext', 'secret_key_version' => $original['secret_key_version']],
    ];

    foreach ($cases as $label => $material) {
        DB::table('bfc_client_hmac_credentials')->where('id', $credential->id)->update($material);

        try {
            app(HmacVerifier::class)->verifyBound($scope, $header, 'body');
            $this->fail($label.' should have failed.');
        } catch (HmacKeyUnreadable $failure) {
            expect($failure->getMessage())->not->toContain($key, (string) $material['secret_ciphertext']);
        }
    }

    expect($credential->refresh()->last_used_at)->toBeNull();
});

it('round-trips versioned encryption and reads previous application keys after rotation', function (): void {
    $keyring = app(HmacKeyring::class);
    $plaintext = bin2hex(random_bytes(32));
    $oldAppKey = (string) config('app.key');
    $encrypted = $keyring->encrypt($plaintext);

    expect($encrypted->ciphertext)->not->toContain($plaintext)
        ->and($encrypted->keyVersion)->toBe($keyring->writeVersion())
        ->and($keyring->decrypt($encrypted->ciphertext, $encrypted->keyVersion))->toBe($plaintext);

    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('app.previous_keys', [$oldAppKey]);

    expect($keyring->writeVersion())->not->toBe($encrypted->keyVersion)
        ->and($keyring->decrypt($encrypted->ciphertext, $encrypted->keyVersion))->toBe($plaintext);
});

it('preserves the shared credential purpose vocabulary and behavior', function (): void {
    expect(CredentialPurpose::values())->toBe([
        'operator_management',
        'dashboard_metadata',
        'consumption',
        'mcp',
        'signing',
        'signing_root',
        'enrollment',
        'system_deployment',
    ])->and(CredentialPurpose::Signing->allowedFor(CredentialKind::Hmac, SubjectType::Installation))->toBeTrue()
        ->and(CredentialPurpose::Consumption->allowedFor(CredentialKind::Hmac, SubjectType::Installation))->toBeFalse()
        ->and(CredentialPurpose::SigningRoot->validForStorage(
            CredentialKind::Hmac,
            SubjectType::Installation,
            CredentialPurpose::SIGNING_ROOT_SUBJECT_REF,
        ))->toBeTrue();
});

it('runs install verification usage and cutover algorithms through the credential store port', function (): void {
    $recording = new RecordingHmacCredentialStore(app(HmacCredentialStore::class));
    app()->instance(HmacCredentialStore::class, $recording);
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $claimed = clientClaimed($scope, $key);

    $installed = app(InstallHmacCredentialFromClaim::class)(
        $scope,
        clientIssuer($claimed),
        bin2hex(random_bytes(32)),
    );
    $model = HmacCredential::query()->findOrFail($installed->credentialId);
    $header = signedClientHeader($model, $key, $scope, 'body');
    app(HmacVerifier::class)->verifyBound($scope, $header, 'body');
    app(HmacKeyring::class)->cutoverInProgress();

    expect($recording->transactions)->toBe(1)
        ->and($recording->finds)->toBeGreaterThanOrEqual(2)
        ->and($recording->inserts)->toBe(1)
        ->and($recording->admissions)->toBe(1)
        ->and($recording->usageStamps)->toBe(1)
        ->and($recording->versionScans)->toBe(1);
});

it('supports post-signature admission without spending replay or rate state', function (): void {
    $recording = new RecordingHmacCredentialStore(app(HmacCredentialStore::class));
    $recording->allowAfterSignature = false;
    app()->instance(HmacCredentialStore::class, $recording);
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $credential = storedClientHmac($scope, $key);
    $nonce = bin2hex(random_bytes(16));
    $header = signedClientHeader($credential, $key, $scope, 'body', nonce: $nonce);

    expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verifyBound($scope, $header, 'body')))
        ->toBe('unusable_key')
        ->and($recording->admissions)->toBe(1)
        ->and($recording->usageStamps)->toBe(0)
        ->and(Cache::has('bfc:hmac:rate:'.$credential->id))->toBeFalse()
        ->and(Cache::has('bfc:hmac:nonce:'.hash('sha256', $credential->id.'|'.$nonce)))->toBeFalse();
});

it('shares unbound verification without a table-specific verifier branch', function (): void {
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $credential = storedClientHmac($scope, $key, role: null);
    $header = signedClientHeader($credential, $key, $scope, 'body');
    config()->set('bfc-client.hmac.audience', $scope->audience);

    expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verify(
        new Subject($scope->subject->type, 'other-subject'),
        $header,
        'body',
    )))->toBe('unusable_key');

    $verified = app(HmacVerifier::class)->verify($scope->subject, $header, 'body', $scope->audience);

    expect($verified->credentialId)->toBe($credential->id)
        ->and($verified->scope)->toBeNull()
        ->and($credential->refresh()->last_used_at)->not->toBeNull();
});

it('reports keyring cutover for null and non-primary stored key versions', function (): void {
    $scope = clientHmacScope();
    $credential = storedClientHmac($scope, bin2hex(random_bytes(32)));
    $keyring = app(HmacKeyring::class);

    expect($keyring->cutoverInProgress())->toBeFalse();

    DB::table('bfc_client_hmac_credentials')->where('id', $credential->id)->update([
        'secret_key_version' => null,
    ]);
    expect($keyring->cutoverInProgress())->toBeTrue();

    DB::table('bfc_client_hmac_credentials')->where('id', $credential->id)->update([
        'secret_key_version' => str_repeat('0', 16),
    ]);
    expect($keyring->cutoverInProgress())->toBeTrue();
});

it('refuses case-insensitive credential aliases before verification state effects', function (): void {
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $id = strtolower((string) Str::uuid());
    $credential = storedClientHmac($scope, $key, id: $id);
    $recording = new RecordingHmacCredentialStore(app(HmacCredentialStore::class));
    $recording->caseInsensitiveFind = true;
    app()->instance(HmacCredentialStore::class, $recording);
    $nonce = bin2hex(random_bytes(16));
    $aliasedEnvelope = new HmacEnvelope(
        strtoupper($id),
        'matte.completed',
        now()->getTimestamp(),
        $nonce,
        $scope->audience,
    );
    $header = $aliasedEnvelope->headerValue(hash_hmac('sha256', $aliasedEnvelope->canonical('body'), $key));

    expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verifyBound($scope, $header, 'body')))
        ->toBe('unusable_key')
        ->and($recording->admissions)->toBe(0)
        ->and($recording->usageStamps)->toBe(0)
        ->and(Cache::has('bfc:hmac:rate:'.$id))->toBeFalse()
        ->and(Cache::has('bfc:hmac:rate:'.strtoupper($id)))->toBeFalse()
        ->and(Cache::has('bfc:hmac:nonce:'.hash('sha256', $id.'|'.$nonce)))->toBeFalse()
        ->and($credential->refresh()->last_used_at)->toBeNull();

    $unboundId = strtolower((string) Str::uuid());
    $unboundKey = bin2hex(random_bytes(32));
    $unbound = storedClientHmac($scope, $unboundKey, role: null, id: $unboundId);
    $unboundEnvelope = new HmacEnvelope(
        strtoupper($unboundId),
        'matte.completed',
        now()->getTimestamp(),
        bin2hex(random_bytes(16)),
        $scope->audience,
    );
    $unboundHeader = $unboundEnvelope->headerValue(hash_hmac('sha256', $unboundEnvelope->canonical('body'), $unboundKey));
    config()->set('bfc-client.hmac.audience', $scope->audience);

    expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verify($scope->subject, $unboundHeader, 'body')))
        ->toBe('unusable_key')
        ->and($recording->admissions)->toBe(0)
        ->and($recording->usageStamps)->toBe(0)
        ->and($unbound->refresh()->last_used_at)->toBeNull();
});

it('refuses case-insensitive install and predecessor aliases', function (): void {
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $id = strtolower((string) Str::uuid());
    storedClientHmac($scope, $key, id: $id, generation: 4);
    $recording = new RecordingHmacCredentialStore(app(HmacCredentialStore::class));
    $recording->caseInsensitiveFind = true;
    app()->instance(HmacCredentialStore::class, $recording);
    $before = HmacCredential::query()->count();

    expect(fn () => app(InstallHmacCredentialFromClaim::class)(
        $scope,
        clientIssuer(clientClaimed($scope, $key, ['id' => strtoupper($id)])),
        bin2hex(random_bytes(32)),
    ))->toThrow(HmacCredentialTransferRefused::class)
        ->and(HmacCredential::query()->count())->toBe($before);

    expect(fn () => app(InstallHmacCredentialFromClaim::class)(
        $scope,
        clientIssuer(clientClaimed($scope, bin2hex(random_bytes(32)), [
            'predecessor' => strtoupper($id),
            'generation' => 5,
        ])),
        bin2hex(random_bytes(32)),
    ))->toThrow(HmacCredentialTransferRefused::class)
        ->and(HmacCredential::query()->count())->toBe($before);
});

it('recovers only an exact classified conflict and refuses conflict or identity aliases', function (): void {
    $scope = clientHmacScope();
    $delegate = app(HmacCredentialStore::class);
    $key = bin2hex(random_bytes(32));
    $id = (string) Str::uuid();
    app()->instance(HmacCredentialStore::class, new ConflictAfterInsertHmacCredentialStore($delegate));

    $installed = app(InstallHmacCredentialFromClaim::class)(
        $scope,
        clientIssuer(clientClaimed($scope, $key, ['id' => $id])),
        bin2hex(random_bytes(32)),
    );
    expect($installed->credentialId)->toBe($id);

    $conflictingId = (string) Str::uuid();
    app()->instance(HmacCredentialStore::class, new ConflictAfterInsertHmacCredentialStore($delegate, changeFingerprint: true));
    expect(fn () => app(InstallHmacCredentialFromClaim::class)(
        $scope,
        clientIssuer(clientClaimed($scope, bin2hex(random_bytes(32)), ['id' => $conflictingId])),
        bin2hex(random_bytes(32)),
    ))->toThrow(HmacCredentialTransferRefused::class);

    $aliasedId = strtoupper((string) Str::uuid());
    app()->instance(HmacCredentialStore::class, new ConflictAfterInsertHmacCredentialStore($delegate, lowercaseId: true));
    expect(fn () => app(InstallHmacCredentialFromClaim::class)(
        $scope,
        clientIssuer(clientClaimed($scope, bin2hex(random_bytes(32)), ['id' => $aliasedId])),
        bin2hex(random_bytes(32)),
    ))->toThrow(HmacCredentialTransferRefused::class)
        ->and(HmacCredential::query()->whereKey(strtolower($aliasedId))->exists())->toBeTrue();
});

it('preserves unbound parse and algorithm refusal precedence without state effects', function (): void {
    $scope = clientHmacScope();
    $recording = new RecordingHmacCredentialStore(app(HmacCredentialStore::class));
    app()->instance(HmacCredentialStore::class, $recording);
    $keyId = (string) Str::uuid();
    $nonce = bin2hex(random_bytes(16));
    $envelope = new HmacEnvelope($keyId, 'event', now()->getTimestamp(), $nonce, $scope->audience);
    $unsupported = str_replace(
        'alg=hmac-sha256',
        'alg=hmac-sha1',
        $envelope->headerValue(str_repeat('0', 64)),
    );

    config()->set('bfc-client.hmac.audience', null);
    expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verify($scope->subject, 'not-a-header', 'body')))
        ->toBe('malformed_header')
        ->and(hmacFailureReason(fn () => app(HmacVerifier::class)->verify($scope->subject, $unsupported, 'body')))
        ->toBe('algorithm_rejected');

    config()->set('bfc-client.hmac.audience', $scope->audience);
    expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verify(
        $scope->subject,
        $unsupported,
        'body',
        'https://caller-mismatch.example',
    )))->toBe('algorithm_rejected')
        ->and($recording->finds)->toBe(0)
        ->and($recording->admissions)->toBe(0)
        ->and($recording->usageStamps)->toBe(0)
        ->and(Cache::has('bfc:hmac:rate:'.$keyId))->toBeFalse()
        ->and(Cache::has('bfc:hmac:nonce:'.hash('sha256', $keyId.'|'.$nonce)))->toBeFalse();
});

it('refunds every over-ceiling duplicate race increment', function (): void {
    config()->set('bfc-client.hmac.verification_rate_ceiling', 3);
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $credential = storedClientHmac($scope, $key);
    $store = new ReentrantRaceArrayStore;
    Cache::swap(new Repository($store));
    $verifier = app(HmacVerifier::class);
    $seed = signedClientHeader($credential, $key, $scope, 'seed');
    $verifier->verifyBound($scope, $seed, 'seed');
    $rateKey = 'bfc:hmac:rate:'.$credential->id;
    $raceHeader = signedClientHeader($credential, $key, $scope, 'race');
    $outcomes = [];
    $triggers = 0;
    $run = function () use ($verifier, $scope, $raceHeader, &$outcomes): void {
        try {
            $verifier->verifyBound($scope, $raceHeader, 'race');
            $outcomes[] = 'accepted';
        } catch (HmacVerificationFailed $failure) {
            $outcomes[] = $failure->reason;
        }
    };
    $store->afterIncrement = function (string $key) use ($rateKey, &$triggers, $run): void {
        if ($key === $rateKey && $triggers < 2) {
            $triggers++;
            $run();
        }
    };

    $run();
    $store->afterIncrement = null;
    sort($outcomes);

    expect($outcomes)->toBe(['accepted', 'rate_limited', 'replayed_nonce'])
        ->and(Cache::get($rateKey))->toBe(2);

    $fresh = signedClientHeader($credential, $key, $scope, 'fresh');
    expect($verifier->verifyBound($scope, $fresh, 'fresh')->credentialId)->toBe($credential->id)
        ->and(Cache::get($rateKey))->toBe(3);
});

it('seals stored credential records against PHP and JSON serialization', function (): void {
    $scope = clientHmacScope();
    $credential = storedClientHmac($scope, bin2hex(random_bytes(32)));
    $record = app(HmacCredentialStore::class)->find($credential->id);

    expect($record)->toBeInstanceOf(StoredHmacCredential::class)
        ->and(fn () => serialize($record))->toThrow(LogicException::class)
        ->and(fn () => json_encode($record, JSON_THROW_ON_ERROR))->toThrow(LogicException::class);
});

it('normalizes every keyring configuration failure without state effects', function (): void {
    $scope = clientHmacScope();
    $key = bin2hex(random_bytes(32));
    $credential = storedClientHmac($scope, $key);
    $header = signedClientHeader($credential, $key, $scope, 'body');
    $original = $credential->getRawOriginal();
    $originalAppKey = (string) config('app.key');
    $recording = new RecordingHmacCredentialStore(app(HmacCredentialStore::class));
    app()->instance(HmacCredentialStore::class, $recording);
    $cases = [
        'unsupported cipher' => function () use ($originalAppKey): void {
            config()->set('app.key', $originalAppKey);
            config()->set('app.previous_keys', []);
            config()->set('app.cipher', 'unsupported-cipher');
        },
        'absent application key' => function (): void {
            config()->set('app.key', null);
            config()->set('app.previous_keys', []);
            config()->set('app.cipher', 'AES-256-CBC');
        },
        'malformed previous key' => function (): void {
            config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
            config()->set('app.previous_keys', ['base64:not-valid%%%']);
            config()->set('app.cipher', 'AES-256-CBC');
        },
        'matching wrong-length key' => function () use ($credential): void {
            $shortKey = 'short';
            config()->set('app.key', $shortKey);
            config()->set('app.previous_keys', []);
            config()->set('app.cipher', 'AES-256-CBC');
            DB::table('bfc_client_hmac_credentials')->where('id', $credential->id)->update([
                'secret_key_version' => HmacKeyring::fingerprint($shortKey),
            ]);
        },
    ];

    foreach ($cases as $label => $configure) {
        DB::table('bfc_client_hmac_credentials')->where('id', $credential->id)->update([
            'secret_ciphertext' => $original['secret_ciphertext'],
            'secret_key_version' => $original['secret_key_version'],
        ]);
        $configure();

        try {
            app(HmacVerifier::class)->verifyBound($scope, $header, 'body');
            $this->fail($label.' should have failed.');
        } catch (HmacKeyUnreadable $failure) {
            expect($failure->getMessage())->not->toContain($key, (string) $original['secret_ciphertext']);
        }
    }

    expect($recording->admissions)->toBe(0)
        ->and($recording->usageStamps)->toBe(0)
        ->and(Cache::has('bfc:hmac:rate:'.$credential->id))->toBeFalse()
        ->and($credential->refresh()->last_used_at)->toBeNull();
});

it('does not call post-signature admission for an invalid signature and keeps rate budgets per key', function (): void {
    config()->set('bfc-client.hmac.verification_rate_ceiling', 1);
    $scope = clientHmacScope();
    $firstKey = bin2hex(random_bytes(32));
    $first = storedClientHmac($scope, $firstKey);
    $secondKey = bin2hex(random_bytes(32));
    $second = storedClientHmac($scope, $secondKey);
    $recording = new RecordingHmacCredentialStore(app(HmacCredentialStore::class));
    app()->instance(HmacCredentialStore::class, $recording);
    $invalid = signedClientHeader($first, $firstKey, $scope, 'invalid');
    $invalid = substr($invalid, 0, -1).($invalid[-1] === '0' ? '1' : '0');

    expect(hmacFailureReason(fn () => app(HmacVerifier::class)->verifyBound($scope, $invalid, 'invalid')))
        ->toBe('invalid_signature')
        ->and($recording->admissions)->toBe(0)
        ->and($recording->usageStamps)->toBe(0);

    $verifier = app(HmacVerifier::class);
    $verifier->verifyBound($scope, signedClientHeader($first, $firstKey, $scope, 'first'), 'first');
    expect(hmacFailureReason(fn () => $verifier->verifyBound(
        $scope,
        signedClientHeader($first, $firstKey, $scope, 'overflow'),
        'overflow',
    )))->toBe('rate_limited');

    expect($verifier->verifyBound(
        $scope,
        signedClientHeader($second, $secondKey, $scope, 'independent'),
        'independent',
    )->credentialId)->toBe($second->id);
});

it('refuses malformed bound scope strings', function (Closure $scope): void {
    expect($scope)->toThrow(InvalidArgumentException::class);
})->with([
    'invalid UTF-8' => fn (): BoundCredentialScope => clientHmacScope(appPurpose: "bad\xC3\x28"),
    'control character' => fn (): BoundCredentialScope => clientHmacScope(subjectRef: "bad\nsubject"),
    'field longer than 255 bytes' => fn (): BoundCredentialScope => clientHmacScope(installation: str_repeat('a', 256)),
    'audience comma' => fn (): BoundCredentialScope => clientHmacScope(audience: 'https://client.example/callback,other'),
    'audience whitespace' => fn (): BoundCredentialScope => clientHmacScope(audience: 'https://client.example/call back'),
]);
