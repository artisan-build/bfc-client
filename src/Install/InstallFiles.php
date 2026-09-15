<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Install;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

/** Applies already-obtained values to local install files without performing provisioning. */
final class InstallFiles
{
    /** Composer's package-name rule from composer-schema.json. */
    private const string PACKAGE_PATTERN = '{^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$}D';

    /**
     * Each target is independently atomic; cross-file atomicity is not claimed.
     *
     * @param  array<string, string>  $environment
     * @param  array<string, int>  $composerMajors
     */
    public function install(
        string $environmentPath,
        string $composerPath,
        array $environment,
        array $composerMajors,
    ): InstallResult {
        $this->validatePath($environmentPath, false);
        $this->validatePath($composerPath, true);
        $this->validateEnvironment($environment);
        $this->validateComposerMajors($composerMajors);

        // Reject malformed input and targets before either stage can write.
        $this->environmentContents($this->readExisting($environmentPath, false), $environment);
        $this->composerContents($this->readExisting($composerPath, true), $composerMajors);

        try {
            $environmentState = $this->replaceLocked(
                $environmentPath,
                fn (string $contents): string => $this->environmentContents($contents, $environment),
                0600,
                false,
            );
        } catch (Throwable) {
            return new InstallResult(InstallTargetState::Failed, InstallTargetState::Failed);
        }

        try {
            $composerState = $this->replaceLocked(
                $composerPath,
                fn (string $contents): string => $this->composerContents($contents, $composerMajors),
                0644,
                true,
            );
        } catch (Throwable) {
            return new InstallResult($environmentState, InstallTargetState::Failed);
        }

        return new InstallResult($environmentState, $composerState);
    }

    /** @param array<string, string> $values */
    public function writeEnvironment(string $path, array $values): InstallTargetState
    {
        $this->validatePath($path, false);
        $this->validateEnvironment($values);

        return $this->replaceLocked(
            $path,
            fn (string $contents): string => $this->environmentContents($contents, $values),
            0600,
            false,
        );
    }

    /** @param array<string, int> $majors */
    public function writeComposerMajors(string $path, array $majors): InstallTargetState
    {
        $this->validatePath($path, true);
        $this->validateComposerMajors($majors);

        return $this->replaceLocked(
            $path,
            fn (string $contents): string => $this->composerContents($contents, $majors),
            0644,
            true,
        );
    }

    public function setEnvironmentValue(string $contents, string $key, string $value): string
    {
        $this->validateEnvironment([$key => $value]);
        $line = $key.'='.$this->formatEnvironmentValue($value);
        $pattern = '/^'.preg_quote($key, '/').'=[^\r\n]*(\r?)$/m';

        if (preg_match($pattern, $contents) === 1) {
            return (string) preg_replace_callback(
                $pattern,
                static fn (array $matches): string => $line.$matches[1],
                $contents,
            );
        }

        $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";

        if ($contents === '') {
            return $line.$newline;
        }

        return str_ends_with($contents, "\n")
            ? $contents.$line.$newline
            : $contents.$newline.$line;
    }

    private function validatePath(string $path, bool $mustExist): void
    {
        $segments = explode(DIRECTORY_SEPARATOR, $path);
        $parent = realpath(dirname($path));
        $lockPath = $path.'.bfc-client.lock';

        if ($path === ''
            || str_contains($path, "\0")
            || ! str_starts_with($path, DIRECTORY_SEPARATOR)
            || ! is_string($parent)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || is_link($path)
            || is_link($lockPath)
            || (file_exists($path) && ! is_file($path))
            || (file_exists($lockPath) && ! is_file($lockPath))
            || ($mustExist && ! is_file($path))) {
            throw new InvalidArgumentException('An install target path is invalid.');
        }
    }

    /** @param array<mixed, mixed> $values */
    private function validateEnvironment(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! is_string($key)
                || preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) !== 1
                || ! is_string($value)
                || str_contains($value, "\0")
                || ! mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('The environment map is invalid.');
            }
        }
    }

    /** @param array<mixed, mixed> $majors */
    private function validateComposerMajors(array $majors): void
    {
        foreach ($majors as $package => $major) {
            if (! is_string($package)
                || preg_match(self::PACKAGE_PATTERN, $package) !== 1
                || ! is_int($major)
                || $major < 1) {
                throw new InvalidArgumentException('The Composer major requirements are invalid.');
            }
        }
    }

    /** @param array<string, string> $values */
    private function environmentContents(string $contents, array $values): string
    {
        foreach ($values as $key => $value) {
            $contents = $this->setEnvironmentValue($contents, $key, $value);
        }

        return $contents;
    }

    /** @param array<string, int> $majors */
    private function composerContents(string $contents, array $majors): string
    {
        try {
            $composer = json_decode($contents, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The Composer target is not valid JSON.', previous: $exception);
        }

        if (! $composer instanceof stdClass) {
            throw new RuntimeException('The Composer target must contain an object.');
        }

        $require = property_exists($composer, 'require') ? $composer->require : new stdClass;

        if (! $require instanceof stdClass) {
            throw new RuntimeException('The Composer require member must contain an object.');
        }

        if (! property_exists($composer, 'require') && $majors !== []) {
            $rootEnd = $this->matchingObjectEnd($contents, $this->firstNonWhitespace($contents));
            $contents = $this->appendObjectProperty($contents, $rootEnd, '"require": {}');
        }

        foreach ($majors as $package => $major) {
            $constraint = '^'.$major;
            $requireRange = $this->topLevelObjectRange($contents, 'require');

            if ($requireRange === null) {
                throw new RuntimeException('The Composer require member could not be located.');
            }

            [$requireStart, $requireEnd] = $requireRange;
            $valueRange = $this->objectStringValueRange($contents, $requireStart, $requireEnd, $package);

            if ($valueRange !== null) {
                [$valueStart, $valueLength] = $valueRange;
                $encoded = json_encode($constraint, JSON_THROW_ON_ERROR);
                $contents = substr_replace($contents, $encoded, $valueStart, $valueLength);

                continue;
            }

            $encodedPackage = json_encode($package, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $encodedConstraint = json_encode($constraint, JSON_THROW_ON_ERROR);
            $contents = $this->appendObjectProperty(
                $contents,
                $requireEnd,
                $encodedPackage.': '.$encodedConstraint,
            );
        }

        return $contents;
    }

    /** @return array{int, int}|null */
    private function topLevelObjectRange(string $json, string $member): ?array
    {
        $rootStart = $this->firstNonWhitespace($json);
        $rootEnd = $this->matchingObjectEnd($json, $rootStart);
        $value = $this->objectValueRange($json, $rootStart, $rootEnd, $member);

        if ($value === null || $json[$value[0]] !== '{') {
            return null;
        }

        return [$value[0], $this->matchingObjectEnd($json, $value[0])];
    }

    /** @return array{int, int}|null */
    private function objectStringValueRange(string $json, int $start, int $end, string $member): ?array
    {
        $range = $this->objectValueRange($json, $start, $end, $member);

        if ($range === null || $json[$range[0]] !== '"') {
            return null;
        }

        $stringEnd = $this->jsonStringEnd($json, $range[0]);

        return [$range[0], $stringEnd - $range[0] + 1];
    }

    /** @return array{int, int}|null */
    private function objectValueRange(string $json, int $start, int $end, string $member): ?array
    {
        $depth = 0;

        for ($offset = $start + 1; $offset < $end; $offset++) {
            $character = $json[$offset];

            if ($character === '"') {
                $stringEnd = $this->jsonStringEnd($json, $offset);

                if ($depth === 0) {
                    $key = json_decode(substr($json, $offset, $stringEnd - $offset + 1), true);
                    $cursor = $this->skipWhitespace($json, $stringEnd + 1);

                    if ($key === $member && ($json[$cursor] ?? null) === ':') {
                        $valueStart = $this->skipWhitespace($json, $cursor + 1);

                        return [$valueStart, $end];
                    }
                }

                $offset = $stringEnd;

                continue;
            }

            if ($character === '{' || $character === '[') {
                $depth++;
            } elseif ($character === '}' || $character === ']') {
                $depth--;
            }
        }

        return null;
    }

    private function appendObjectProperty(string $json, int $objectEnd, string $property): string
    {
        $newline = str_contains($json, "\r\n") ? "\r\n" : "\n";
        $closingIndent = $this->lineIndentAt($json, $objectEnd);
        $propertyIndent = $this->firstPropertyIndent($json, $objectEnd) ?? $closingIndent.'    ';
        $last = $objectEnd - 1;

        while ($last >= 0 && ctype_space($json[$last])) {
            $last--;
        }

        $separator = $json[$last] === '{' ? '' : ',';
        $insertion = $separator.$newline.$propertyIndent.$property;

        if ($json[$last] === '{') {
            $insertion .= $newline.$closingIndent;
        }

        return substr_replace($json, $insertion, $last + 1, 0);
    }

    private function firstPropertyIndent(string $json, int $objectEnd): ?string
    {
        $objectStart = strrpos(substr($json, 0, $objectEnd), '{');

        if (! is_int($objectStart)) {
            return null;
        }

        $quote = strpos($json, '"', $objectStart + 1);

        if (! is_int($quote) || $quote >= $objectEnd) {
            return null;
        }

        return $this->lineIndentAt($json, $quote);
    }

    private function lineIndentAt(string $json, int $offset): string
    {
        $lineStart = strrpos(substr($json, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $indent = substr($json, $lineStart, $offset - $lineStart);

        return preg_match('/^[ \t]*/', $indent, $matches) === 1 ? $matches[0] : '';
    }

    private function matchingObjectEnd(string $json, int $start): int
    {
        if (($json[$start] ?? null) !== '{') {
            throw new RuntimeException('A Composer object could not be located.');
        }

        $depth = 0;

        for ($offset = $start, $length = strlen($json); $offset < $length; $offset++) {
            if ($json[$offset] === '"') {
                $offset = $this->jsonStringEnd($json, $offset);

                continue;
            }

            if ($json[$offset] === '{') {
                $depth++;
            } elseif ($json[$offset] === '}' && --$depth === 0) {
                return $offset;
            }
        }

        throw new RuntimeException('A Composer object is unterminated.');
    }

    private function jsonStringEnd(string $json, int $start): int
    {
        for ($offset = $start + 1, $length = strlen($json); $offset < $length; $offset++) {
            if ($json[$offset] === '\\') {
                $offset++;

                continue;
            }

            if ($json[$offset] === '"') {
                return $offset;
            }
        }

        throw new RuntimeException('A Composer JSON string is unterminated.');
    }

    private function firstNonWhitespace(string $contents): int
    {
        for ($offset = 0, $length = strlen($contents); $offset < $length; $offset++) {
            if (! ctype_space($contents[$offset])) {
                return $offset;
            }
        }

        throw new RuntimeException('The Composer target is empty.');
    }

    private function skipWhitespace(string $contents, int $offset): int
    {
        $length = strlen($contents);

        while ($offset < $length && ctype_space($contents[$offset])) {
            $offset++;
        }

        return $offset;
    }

    /** @param callable(string): string $transform */
    private function replaceLocked(
        string $path,
        callable $transform,
        int $createMode,
        bool $mustExist,
    ): InstallTargetState {
        $lockPath = $path.'.bfc-client.lock';
        $lockExisted = is_file($lockPath);
        $lock = @fopen($lockPath, 'c+b');

        if ($lock === false || (! $lockExisted && ! chmod($lockPath, 0600)) || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RuntimeException('The install target lock could not be acquired.');
        }

        try {
            $original = $this->readExisting($path, $mustExist);
            $contents = $transform($original);

            if ($contents === $original) {
                return InstallTargetState::Unchanged;
            }

            $mode = is_file($path) ? (fileperms($path) & 0777) : $createMode;
            $temporary = tempnam(dirname($path), '.bfc-client-install-');

            if (! is_string($temporary)) {
                throw new RuntimeException('A same-directory temporary file could not be created.');
            }

            try {
                $file = @fopen($temporary, 'wb');

                if ($file === false || ! chmod($temporary, $mode)) {
                    if (is_resource($file)) {
                        fclose($file);
                    }

                    throw new RuntimeException('The install target temporary file could not be opened.');
                }

                try {
                    if (fwrite($file, $contents) !== strlen($contents)
                        || fflush($file) === false
                        || (function_exists('fsync') && ! fsync($file))) {
                        throw new RuntimeException('The install target temporary file could not be written.');
                    }
                } finally {
                    fclose($file);
                }

                if (! rename($temporary, $path)) {
                    throw new RuntimeException('The install target could not be atomically replaced.');
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }

            return InstallTargetState::Replaced;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function readExisting(string $path, bool $mustExist): string
    {
        if (! is_file($path)) {
            if ($mustExist) {
                throw new RuntimeException('A required install target is missing.');
            }

            return '';
        }

        $contents = file_get_contents($path);

        return is_string($contents)
            ? $contents
            : throw new RuntimeException('An install target could not be read.');
    }

    private function formatEnvironmentValue(string $value): string
    {
        if ($value !== '' && preg_match('/^[A-Za-z0-9_:\/.@-]+$/', $value) === 1) {
            return $value;
        }

        return '"'.strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            '$' => '\\$',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
            "\f" => '\\f',
            "\v" => '\\v',
        ]).'"';
    }
}
