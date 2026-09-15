<?php

declare(strict_types=1);

use ArtisanBuild\BfcClient\Install\InstallFiles;
use ArtisanBuild\BfcClient\Install\InstallTargetState;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->installRoot = storage_path('app/bfc-client-install-'.bin2hex(random_bytes(8)));
    (new Filesystem)->ensureDirectoryExists($this->installRoot, 0700);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->installRoot);
});

it('updates exact env keys and Composer majors while preserving unrelated bytes and modes', function (): void {
    $env = $this->installRoot.'/.env.install';
    $composer = $this->installRoot.'/composer.json';
    $secret = 'fixture_'.bin2hex(random_bytes(8))."\nquoted=\"value\"\rnext";
    $envBefore = "# untouched\r\nAPP_NAME=Original\r\nSOURCE_CREDENTIAL=old\r\nSOURCE_CREDENTIAL_SUFFIX=stay\r\n";
    $composerBefore = <<<'JSON'
{
  "name" : "fixture/source-host",
  "require" : {
    "php" : "^8.3",
    "vendor/source-client" : "^1"
  },
  "extra" : { "unchanged-bytes" : "yes" }
}

JSON;
    file_put_contents($env, $envBefore);
    file_put_contents($composer, $composerBefore);
    chmod($env, 0640);
    chmod($composer, 0644);

    $result = (new InstallFiles)->install(
        $env,
        $composer,
        ['SOURCE_CREDENTIAL' => $secret, 'BFC_CLIENT_IDENTITY' => 'fixture-client'],
        ['vendor/source-client' => 3],
    );

    expect($result->stages())->toBe(['environment' => 'replaced', 'composer' => 'replaced'])
        ->and(fileperms($env) & 0777)->toBe(0640)
        ->and(fileperms($composer) & 0777)->toBe(0644)
        ->and(file_get_contents($env))->toBe(
            "# untouched\r\nAPP_NAME=Original\r\n"
            .'SOURCE_CREDENTIAL="'.strtr($secret, ["\n" => '\\n', "\r" => '\\r', '"' => '\\"'])."\"\r\n"
            ."SOURCE_CREDENTIAL_SUFFIX=stay\r\nBFC_CLIENT_IDENTITY=fixture-client\r\n",
        )
        ->and(file_get_contents($composer))->toBe(str_replace(
            '"vendor/source-client" : "^1"',
            '"vendor/source-client" : "^3"',
            $composerBefore,
        ));
});

it('adds a missing Composer requirement without rewriting existing bytes', function (): void {
    $composer = $this->installRoot.'/composer.json';
    $before = "{\n    \"name\": \"fixture/app\",\n    \"require\": {\n        \"php\": \"^8.3\"\n    },\n    \"extra\": {\"marker\": \"byte-exact\"}\n}\n";
    file_put_contents($composer, $before);

    expect((new InstallFiles)->writeComposerMajors($composer, ['vendor/new-client' => 2]))
        ->toBe(InstallTargetState::Replaced)
        ->and(file_get_contents($composer))->toBe(
            "{\n    \"name\": \"fixture/app\",\n    \"require\": {\n        \"php\": \"^8.3\",\n        \"vendor/new-client\": \"^2\"\n    },\n    \"extra\": {\"marker\": \"byte-exact\"}\n}\n",
        );
});

it('is byte and target-stat idempotent on an identical rerun', function (): void {
    $env = $this->installRoot.'/.env.install';
    $composer = $this->installRoot.'/composer.json';
    file_put_contents($env, "APP_NAME=fixture\n");
    file_put_contents($composer, "{\n    \"require\": {\"vendor/client\": \"^1\"}\n}\n");
    $utility = new InstallFiles;
    $utility->install($env, $composer, ['CLIENT_VALUE' => 'created-value'], ['vendor/client' => 2]);
    clearstatcache(true);
    $before = [file_get_contents($env), stat($env), file_get_contents($composer), stat($composer)];

    $result = $utility->install($env, $composer, ['CLIENT_VALUE' => 'created-value'], ['vendor/client' => 2]);
    clearstatcache(true);

    expect($result->stages())->toBe(['environment' => 'unchanged', 'composer' => 'unchanged'])
        ->and([file_get_contents($env), stat($env), file_get_contents($composer), stat($composer)])->toBe($before);
});

it('rejects injection and malformed targets before writing either file', function (array $environment, array $majors, string $composerContents): void {
    $env = $this->installRoot.'/.env.install';
    $composer = $this->installRoot.'/composer.json';
    file_put_contents($env, "UNCHANGED=yes\n");
    file_put_contents($composer, $composerContents);
    clearstatcache(true);
    $before = [file_get_contents($env), stat($env), file_get_contents($composer), stat($composer)];

    $threw = false;
    try {
        (new InstallFiles)->install($env, $composer, $environment, $majors);
    } catch (Throwable) {
        $threw = true;
    }
    clearstatcache(true);

    expect($threw)->toBeTrue()
        ->and([file_get_contents($env), stat($env), file_get_contents($composer), stat($composer)])->toBe($before)
        ->and(glob($this->installRoot.'/.bfc-client-install-*') ?: [])->toBe([]);
})->with([
    'env key newline' => [["SAFE\nINJECTED" => 'value'], ['vendor/client' => 2], '{"require":{}}'],
    'env key equals' => [['SAFE=INJECTED' => 'value'], ['vendor/client' => 2], '{"require":{}}'],
    'env NUL' => [['SAFE' => "value\0injected"], ['vendor/client' => 2], '{"require":{}}'],
    'package injection' => [['SAFE' => 'value'], ["vendor/client\nother" => 2], '{"require":{}}'],
    'invalid major' => [['SAFE' => 'value'], ['vendor/client' => 0], '{"require":{}}'],
    'malformed composer' => [['SAFE' => 'value'], ['vendor/client' => 2], '{"require":'],
]);

it('reports an environment success and Composer failure as separate stages', function (): void {
    $envDirectory = $this->installRoot.'/env';
    $composerDirectory = $this->installRoot.'/composer';
    mkdir($envDirectory, 0700);
    mkdir($composerDirectory, 0700);
    $env = $envDirectory.'/.env.install';
    $composer = $composerDirectory.'/composer.json';
    file_put_contents($composer, '{"require":{"vendor/client":"^1"}}');
    chmod($composerDirectory, 0500);

    try {
        $result = (new InstallFiles)->install($env, $composer, ['SAFE' => 'created'], ['vendor/client' => 2]);

        expect($result->stages())->toBe(['environment' => 'replaced', 'composer' => 'failed'])
            ->and(file_get_contents($env))->toBe("SAFE=created\n")
            ->and(file_get_contents($composer))->toBe('{"require":{"vendor/client":"^1"}}');
    } finally {
        chmod($composerDirectory, 0700);
    }
});

it('contains a generated secret only in the selected env target and never returns it', function (): void {
    $env = $this->installRoot.'/.env.install';
    $composer = $this->installRoot.'/composer.json';
    $secret = 'fixture_secret_'.bin2hex(random_bytes(24));
    file_put_contents($composer, '{"require":{"vendor/client":"^1"}}');

    $result = (new InstallFiles)->install($env, $composer, ['SOURCE_CREDENTIAL' => $secret], ['vendor/client' => 2]);

    expect(file_get_contents($env))->toContain($secret)
        ->and(json_encode($result->stages(), JSON_THROW_ON_ERROR))->not->toContain($secret)
        ->and(file_get_contents($composer))->not->toContain($secret)
        ->and(file_get_contents($env.'.bfc-client.lock'))->not->toContain($secret)
        ->and(file_get_contents($composer.'.bfc-client.lock'))->not->toContain($secret)
        ->and(fileperms($env) & 0777)->toBe(0600)
        ->and(fileperms($env.'.bfc-client.lock') & 0777)->toBe(0600)
        ->and(fileperms($composer.'.bfc-client.lock') & 0777)->toBe(0600);
});
