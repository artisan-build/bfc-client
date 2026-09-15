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
     * Resolve the identity and enforce the byte-stable HTTP field contract.
     * Every wire surface uses this path, so transport normalization cannot
     * make the request metadata differ from the proof response.
     *
     * @throws InvalidArgumentException
     */
    public function validated(): string
    {
        $identity = $this->resolve();

        if (
            ! mb_check_encoding($identity, 'UTF-8')
            || strlen($identity) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $identity) === 1
            || str_starts_with($identity, ' ')
            || str_ends_with($identity, ' ')
        ) {
            throw new InvalidArgumentException(
                'The resolved client identity must be a valid, byte-stable HTTP field value of at most 255 UTF-8 bytes.'
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
