<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

final readonly class Subject
{
    public function __construct(
        public SubjectType $type,
        public string $ref,
    ) {}
}
