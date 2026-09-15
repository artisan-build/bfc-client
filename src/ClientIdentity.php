<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

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
     * most 255 bytes, no CR, LF, or NUL octets. Every surface that puts the
     * identity on the wire (the X-BfC-Client-Id header, the proof-of-life
     * route) uses this single code path, so a misconfigured identity fails
     * loudly instead of being sent or served.
     *
     * NUL is rejected separately from CR/LF because it is not a header
     * injection hazard but a collision one: a NUL is valid UTF-8, so it
     * clears the encoding check, yet PostgreSQL silently truncates a stored
     * value at the first NUL. Two byte-distinct identities would collapse
     * into one on a provider using that driver, so servers reject it and the
     * client fails fast rather than sending a value that is dropped later.
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
            || str_contains($identity, "\0")
        ) {
            throw new InvalidArgumentException(
                'The resolved client identity must be valid UTF-8, at most 255 bytes, and contain no CR, LF, or NUL octets.'
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

        return $this->resolvePersisted($this->storagePath());
    }

    /**
     * Generate a fresh identity while holding the identity file's lock. Using
     * the target itself means every process observes one winner and no lock or
     * temporary artifact remains after first resolution.
     */
    private function resolvePersisted(string $path): string
    {
        $this->files->ensureDirectoryExists(dirname($path), 0755);
        $file = @fopen($path, 'c+b');

        if ($file === false || ! flock($file, LOCK_EX)) {
            throw new RuntimeException("Unable to persist the client identity to [{$path}].");
        }

        try {
            if (fseek($file, 0) !== 0) {
                throw new RuntimeException("Unable to persist the client identity to [{$path}].");
            }

            $persisted = stream_get_contents($file);

            if (! is_string($persisted)) {
                throw new RuntimeException("Unable to persist the client identity to [{$path}].");
            }

            if ($persisted !== '') {
                return $persisted;
            }

            $identity = (string) Str::uuid7();

            if (ftruncate($file, 0) === false
                || rewind($file) === false
                || fwrite($file, $identity) !== strlen($identity)
                || fflush($file) === false) {
                throw new RuntimeException("Unable to persist the client identity to [{$path}].");
            }

            return $identity;
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException("Unable to persist the client identity to [{$path}].", previous: $exception);
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    private function storagePath(): string
    {
        return storage_path('app/bfc-client/identity');
    }
}
