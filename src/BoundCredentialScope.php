<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

use InvalidArgumentException;

final readonly class BoundCredentialScope
{
    public function __construct(
        public string $appPurpose,
        public Subject $subject,
        public string $installation,
        public string $application,
        public string $audience,
    ) {
        self::assertString($this->appPurpose);
        self::assertString($this->subject->ref);
        self::assertString($this->installation);
        self::assertString($this->application);
        self::assertString($this->audience);

        if (preg_match('/\A[^,\s]{1,255}\z/uD', $this->audience) !== 1) {
            throw new InvalidArgumentException('The bound credential scope is invalid.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->subject->type === $other->subject->type
            && hash_equals($this->subject->ref, $other->subject->ref)
            && hash_equals($this->appPurpose, $other->appPurpose)
            && hash_equals($this->installation, $other->installation)
            && hash_equals($this->application, $other->application)
            && hash_equals($this->audience, $other->audience);
    }

    private static function assertString(string $value): void
    {
        if ($value === ''
            || strlen($value) > 255
            || ! mb_check_encoding($value, 'UTF-8')
            || preg_match('/\p{Cc}/u', $value) === 1) {
            throw new InvalidArgumentException('The bound credential scope is invalid.');
        }
    }
}
