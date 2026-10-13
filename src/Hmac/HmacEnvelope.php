<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Hmac;

use ArtisanBuild\BfcClient\Exceptions\HmacVerificationFailed;
use InvalidArgumentException;

final readonly class HmacEnvelope
{
    public const string HEADER = 'BFC-Signature';

    public const string ALGORITHM = 'hmac-sha256';

    private const string KEY_PATTERN = '[0-9a-fA-F-]{1,64}';

    private const string EVENT_PATTERN = '[A-Za-z0-9._-]{1,128}';

    private const string NONCE_PATTERN = '[0-9a-f]{16,64}';

    private const string AUDIENCE_PATTERN = '[^,\s]{1,255}';

    public function __construct(
        public string $keyId,
        public string $eventType,
        public int $timestamp,
        public string $nonce,
        public string $audience,
    ) {
        foreach ([
            'key id' => [$keyId, self::KEY_PATTERN],
            'event type' => [$eventType, self::EVENT_PATTERN],
            'nonce' => [$nonce, self::NONCE_PATTERN],
            'audience' => [$audience, self::AUDIENCE_PATTERN],
        ] as $field => [$value, $pattern]) {
            if (preg_match('/^'.$pattern.'$/', $value) !== 1) {
                throw new InvalidArgumentException(sprintf('The envelope %s does not fit the signed wire form.', $field));
            }
        }

        if ($timestamp < 0) {
            throw new InvalidArgumentException('The envelope timestamp cannot be negative.');
        }
    }

    /** @return array{self, string, string} */
    public static function parse(string $header): array
    {
        $pattern = '/^v1,alg=([a-z0-9-]{1,32}),key=('.self::KEY_PATTERN.'),event=('.self::EVENT_PATTERN.'),'
            .'ts=(0|[1-9]\d{0,11}),nonce=('.self::NONCE_PATTERN.'),aud=('.self::AUDIENCE_PATTERN.'),sig=([0-9a-f]{64})$/';

        if (preg_match($pattern, $header, $matches) !== 1) {
            throw HmacVerificationFailed::malformedHeader();
        }

        return [
            new self(
                keyId: $matches[2],
                eventType: $matches[3],
                timestamp: (int) $matches[4],
                nonce: $matches[5],
                audience: $matches[6],
            ),
            $matches[1],
            $matches[7],
        ];
    }

    public function canonical(string $body): string
    {
        return implode("\n", [
            'bfc-hmac-v1',
            self::ALGORITHM,
            $this->keyId,
            $this->eventType,
            (string) $this->timestamp,
            $this->nonce,
            $this->audience,
            hash('sha256', $body),
        ]);
    }

    public function headerValue(string $signature): string
    {
        return sprintf(
            'v1,alg=%s,key=%s,event=%s,ts=%d,nonce=%s,aud=%s,sig=%s',
            self::ALGORITHM,
            $this->keyId,
            $this->eventType,
            $this->timestamp,
            $this->nonce,
            $this->audience,
            $signature,
        );
    }
}
