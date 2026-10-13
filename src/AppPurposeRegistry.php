<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

final class AppPurposeRegistry
{
    public function purpose(string $appPurpose): ?CredentialPurpose
    {
        $purposes = config('bfc-client.credentials.app_purposes', []);
        $configured = is_array($purposes) ? ($purposes[$appPurpose] ?? null) : null;

        if ($configured instanceof CredentialPurpose) {
            return $configured;
        }

        return is_string($configured) ? CredentialPurpose::tryFrom($configured) : null;
    }
}
