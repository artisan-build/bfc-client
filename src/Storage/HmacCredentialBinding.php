<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Storage;

use ArtisanBuild\BfcClient\BoundCredentialScope;
use ArtisanBuild\BfcClient\CredentialMaterialRole;
use ArtisanBuild\BfcClient\CredentialPurpose;
use ArtisanBuild\BfcClient\Hmac\HmacEnvelope;
use ArtisanBuild\BfcClient\StoredHmacCredential;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $credential_id
 * @property string $app_purpose
 * @property string $installation_ref
 * @property string $application_ref
 * @property string $audience
 * @property string $algorithm
 * @property CredentialMaterialRole $material_role
 * @property string $scope_hash
 */
final class HmacCredentialBinding extends Model
{
    protected $table = 'bfc_client_hmac_bindings';

    protected $primaryKey = 'credential_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['material_role' => CredentialMaterialRole::class];
    }

    public static function createOriginator(HmacCredential $credential, BoundCredentialScope $scope): self
    {
        return self::createForRole($credential, $scope, CredentialMaterialRole::Originator);
    }

    public static function createVerificationCopy(HmacCredential $credential, BoundCredentialScope $scope): self
    {
        return self::createForRole($credential, $scope, CredentialMaterialRole::VerificationCopy);
    }

    public function exactlyMatches(
        HmacCredential $credential,
        BoundCredentialScope $scope,
        CredentialMaterialRole $role,
    ): bool {
        return $credential->subject_type === $scope->subject->type
            && hash_equals($credential->subject_ref, $scope->subject->ref)
            && $credential->purpose === CredentialPurpose::Signing
            && hash_equals($this->app_purpose, $scope->appPurpose)
            && hash_equals($this->installation_ref, $scope->installation)
            && hash_equals($this->application_ref, $scope->application)
            && hash_equals($this->audience, $scope->audience)
            && hash_equals($this->algorithm, HmacEnvelope::ALGORITHM)
            && $this->material_role === $role
            && hash_equals($this->scope_hash, self::scopeHash($scope, $role));
    }

    public static function scopeHash(BoundCredentialScope $scope, CredentialMaterialRole $role): string
    {
        return StoredHmacCredential::scopeHash($scope, $role);
    }

    private static function createForRole(
        HmacCredential $credential,
        BoundCredentialScope $scope,
        CredentialMaterialRole $role,
    ): self {
        $binding = new self;
        $binding->forceFill([
            'credential_id' => $credential->id,
            'app_purpose' => $scope->appPurpose,
            'installation_ref' => $scope->installation,
            'application_ref' => $scope->application,
            'audience' => $scope->audience,
            'algorithm' => HmacEnvelope::ALGORITHM,
            'material_role' => $role,
            'scope_hash' => self::scopeHash($scope, $role),
        ])->save();

        return $binding;
    }
}
