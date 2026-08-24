<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ClientIdentity
{
    private ?string $resolved = null;

    public function __construct(
        private readonly Repository $config,
        private readonly Filesystem $files,
    ) {}

    /**
     * Resolve the stable identity for this client installation.
     *
     * Resolution order: explicit config value, then the persisted identity
     * file, then a freshly generated UUID that is persisted for next time.
     * An explicitly configured identity is never written to storage.
     */
    public function resolve(): string
    {
        return $this->resolved ??= $this->resolveFresh();
    }

    /**
     * Resolve the identity and enforce the wire contract: valid UTF-8, at
     * most 255 bytes, no CR or LF octets. Every surface that puts the
     * identity on the wire (the X-BfC-Client-Id header, the proof-of-life
     * route) uses this single code path, so a misconfigured identity fails
     * loudly instead of being sent or served.
     *
     * @throws InvalidArgumentException
     */
    public function validated(): string
    {
        $identity = $this->resolve();

        if (
            ! mb_check_encoding($identity, 'UTF-8')
            || strlen($identity) > 255
            || strpbrk($identity, "\r\n") !== false
        ) {
            throw new InvalidArgumentException(
                'The resolved client identity must be valid UTF-8, at most 255 bytes, and contain no CR or LF octets.'
            );
        }

        return $identity;
    }

    private function resolveFresh(): string
    {
        $configured = $this->config->get('bfc-client.identity');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $path = $this->storagePath();

        if ($this->files->exists($path)) {
            $persisted = trim($this->files->get($path));

            if ($persisted !== '') {
                return $persisted;
            }
        }

        return $this->generate($path);
    }

    /**
     * Generate a fresh identity and persist it at the given path.
     *
     * The write takes an exclusive lock and the persisted value is read back
     * and returned, so concurrent first resolutions converge on whatever the
     * file ultimately holds rather than each returning its own candidate.
     */
    private function generate(string $path): string
    {
        $identity = Str::uuid7()->toString();

        $this->files->ensureDirectoryExists(dirname($path), 0755);

        if ($this->files->put($path, $identity, lock: true) === false) {
            throw new RuntimeException("Unable to persist the client identity to [{$path}].");
        }

        return trim($this->files->get($path));
    }

    private function storagePath(): string
    {
        return storage_path('app/bfc-client/identity');
    }
}
