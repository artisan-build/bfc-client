<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Hmac;

use ArtisanBuild\BfcClient\Contracts\HmacCredentialStore;
use ArtisanBuild\BfcClient\Exceptions\HmacKeyUnreadable;
use Illuminate\Encryption\Encrypter;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class HmacKeyring
{
    private const int KEY_VERSION_LENGTH = 16;

    public function __construct(private readonly HmacCredentialStore $credentials) {}

    public function writeVersion(): string
    {
        return self::fingerprint($this->primaryKey());
    }

    public function encrypt(#[SensitiveParameter] string $plaintext): EncryptedHmacKey
    {
        $key = $this->primaryKey();

        return new EncryptedHmacKey(
            ciphertext: $this->encrypterFor($key)->encryptString($plaintext),
            keyVersion: self::fingerprint($key),
        );
    }

    public function decrypt(string $ciphertext, ?string $keyVersion): string
    {
        if ($keyVersion === null || $keyVersion === '') {
            throw HmacKeyUnreadable::missingVersion();
        }

        try {
            foreach ($this->readKeys() as $key) {
                if (hash_equals(self::fingerprint($key), $keyVersion)) {
                    return $this->encrypterFor($key)->decryptString($ciphertext);
                }
            }
        } catch (Throwable $failure) {
            throw HmacKeyUnreadable::ringFailure($keyVersion, $failure);
        }

        throw HmacKeyUnreadable::unknownVersion($keyVersion);
    }

    public function cutoverInProgress(): bool
    {
        return $this->credentials->hasKeyVersionOtherThan($this->writeVersion());
    }

    public static function fingerprint(#[SensitiveParameter] string $rawKeyBytes): string
    {
        return substr(hash('sha256', $rawKeyBytes), 0, self::KEY_VERSION_LENGTH);
    }

    public function deliveryFingerprint(#[SensitiveParameter] string $signingKey, int $generation): string
    {
        return substr(hash('sha256', 'bfc-hmac-delivery|'.$generation.'|'.hash('sha256', $signingKey)), 0, 16);
    }

    private function encrypterFor(#[SensitiveParameter] string $key): Encrypter
    {
        return new Encrypter($key, $this->cipher());
    }

    private function primaryKey(): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('The HMAC keyring requires app.key to be set.');
        }

        return self::parseKey($key);
    }

    /** @return list<string> */
    private function readKeys(): array
    {
        $keys = [$this->primaryKey()];
        $previous = config('app.previous_keys');

        foreach (is_array($previous) ? $previous : [] as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = self::parseKey($key);
            }
        }

        return $keys;
    }

    private function cipher(): string
    {
        $cipher = config('app.cipher');

        return is_string($cipher) && $cipher !== '' ? $cipher : 'AES-256-CBC';
    }

    private static function parseKey(#[SensitiveParameter] string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded === false) {
                throw new RuntimeException('An application key declared as base64 does not decode.');
            }

            return $decoded;
        }

        return $key;
    }
}
