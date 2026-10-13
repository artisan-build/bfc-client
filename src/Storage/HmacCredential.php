<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Storage;

use ArtisanBuild\BfcClient\CredentialPurpose;
use ArtisanBuild\BfcClient\CredentialStatus;
use ArtisanBuild\BfcClient\Subject;
use ArtisanBuild\BfcClient\SubjectType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property CredentialPurpose $purpose
 * @property SubjectType $subject_type
 * @property string $subject_ref
 * @property CredentialStatus $status
 * @property string|null $secret_ciphertext
 * @property string|null $secret_key_version
 * @property CarbonInterface $delivered_at
 * @property int $delivered_generation
 * @property string $delivery_fingerprint
 * @property string|null $predecessor_credential_id
 * @property CarbonInterface $activated_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface|null $last_used_at
 */
final class HmacCredential extends Model
{
    protected $table = 'bfc_client_hmac_credentials';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['secret_ciphertext'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purpose' => CredentialPurpose::class,
            'subject_type' => SubjectType::class,
            'status' => CredentialStatus::class,
            'delivered_at' => 'immutable_datetime',
            'delivered_generation' => 'integer',
            'activated_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    public function subject(): Subject
    {
        return new Subject($this->subject_type, $this->subject_ref);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', CredentialStatus::Active->value)
            ->whereNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
