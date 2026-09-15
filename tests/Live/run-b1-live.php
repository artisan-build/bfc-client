<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Testing\BoundedWait;
use ArtisanBuild\BuiltForCloud\Testing\DisposablePostgresLane;
use ArtisanBuild\BuiltForCloud\Testing\P6LoopbackProcess;
use ArtisanBuild\BuiltForCloud\Testing\PostgresAdministrator;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Keys\Version4\AsymmetricSecretKey;
use ParagonIE\Paseto\Protocol\Version4;
use ParagonIE\Paseto\Purpose;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';

/** @return never */
function b1Fail(string $message): void
{
    throw new RuntimeException($message);
}

/** @param list<string> $command
 * @param  array<string, string>  $environment
 */
function b1Run(array $command, string $directory, array $environment, string $label, ?string $input = null): string
{
    $process = new Process($command, $directory, $environment, $input, 300);

    if ($process->run() !== 0) {
        b1Fail($label.' exited non-zero.');
    }

    return $process->getOutput();
}

/** @param array<string, string> $overrides
 * @return array<string, string>
 */
function b1Environment(array $overrides = []): array
{
    $environment = getenv();
    $environment = is_array($environment) ? array_filter($environment, 'is_string') : [];

    return array_merge($environment, $overrides);
}

/** @return array<string, mixed> */
function b1Json(string $contents, string $label): array
{
    $value = json_decode($contents, true);

    return is_array($value) && ! array_is_list($value)
        ? $value
        : throw new RuntimeException($label.' did not contain a JSON object.');
}

function b1Same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        b1Fail($label.' did not match.');
    }
}

function b1Copy(string $source, string $target): void
{
    if (! copy($source, $target)) {
        b1Fail('A live fixture file could not be copied.');
    }
}

function b1RemoveTree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        if ($entry instanceof SplFileInfo) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
    }

    rmdir($path);
}

/** @param list<array{0: string, 1: string}> $headers
 * @return array{status: int, headers: array<string, list<string>>, body: string}
 */
function b1Http(int $port, string $method, string $path, array $headers = [], string $body = ''): array
{
    $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $errorNumber, $error, 5);

    if (! is_resource($socket)) {
        b1Fail('A loopback request could not connect.');
    }

    stream_set_timeout($socket, 10);
    $request = $method.' '.$path." HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: application/json\r\nConnection: close\r\n";
    foreach ($headers as [$name, $value]) {
        $request .= $name.': '.$value."\r\n";
    }
    if ($body !== '') {
        $request .= 'Content-Type: application/json'."\r\n".'Content-Length: '.strlen($body)."\r\n";
    }
    fwrite($socket, $request."\r\n".$body);
    $response = stream_get_contents($socket);
    fclose($socket);

    if (! is_string($response) || ! str_contains($response, "\r\n\r\n")) {
        b1Fail('A loopback request returned an invalid HTTP response.');
    }

    [$head, $responseBody] = explode("\r\n\r\n", $response, 2);
    $lines = explode("\r\n", $head);
    $statusLine = array_shift($lines);
    if (! is_string($statusLine) || preg_match('/^HTTP\/1\.[01] ([0-9]{3})/', $statusLine, $matches) !== 1) {
        b1Fail('A loopback response status was invalid.');
    }
    $responseHeaders = [];
    foreach ($lines as $line) {
        if (! str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $responseHeaders[strtolower($name)][] = trim($value);
    }

    return ['status' => (int) $matches[1], 'headers' => $responseHeaders, 'body' => $responseBody];
}

function b1Ready(int $port, string $path): bool
{
    try {
        return b1Http($port, 'GET', $path)['status'] === 200;
    } catch (Throwable) {
        return false;
    }
}

/** @param array{status: int, headers: array<string, list<string>>, body: string} $response */
function b1Status(array $response, int $status, string $label): void
{
    b1Same($status, $response['status'], $label.' status');
}

/** @param array{status: int, headers: array<string, list<string>>, body: string} $response */
function b1VersionRefusal(array $response, int $status, string $error): void
{
    b1Status($response, $status, $error);
    b1Same(['error' => $error, 'supported_contract_major' => 2], b1Json($response['body'], $error), $error.' body');

    if (! str_contains(implode(',', $response['headers']['cache-control'] ?? []), 'no-store')
        || array_key_exists('retry-after', $response['headers'])) {
        b1Fail($error.' response headers were not stable.');
    }
}

/** @param array<string, mixed> $composer */
function b1WriteComposer(string $path, array $composer): void
{
    file_put_contents($path, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
}

/** @return array<string, mixed> */
function b1LockedPackage(string $lockPath, string $package): array
{
    $lock = b1Json((string) file_get_contents($lockPath), 'Composer lock');

    foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $entry) {
        if (is_array($entry) && ($entry['name'] ?? null) === $package) {
            return $entry;
        }
    }

    b1Fail('The required package was absent from the installed lock.');
}

/** @param array<string, mixed> $overrides */
function b1Assertion(AsymmetricSecretKey $key, string $audience, array $overrides = []): string
{
    $now = new DateTimeImmutable;
    $claims = array_merge([
        'iss' => 'https://b1-p6-authority.test',
        'sub' => 'b1-operator',
        'aud' => $audience,
        'iat' => $now->format(DATE_ATOM),
        'nbf' => $now->format(DATE_ATOM),
        'exp' => $now->modify('+90 seconds')->format(DATE_ATOM),
        'jti' => 'b1_'.bin2hex(random_bytes(12)),
        'display_name' => 'B1 Fixture Operator',
        'role' => 'admin',
        'purpose' => 'mcp',
    ], $overrides);

    return (new Builder)
        ->setVersion(new Version4)
        ->setPurpose(Purpose::public())
        ->setKey($key)
        ->setClaims($claims)
        ->setFooterArray(['kid' => 'b1-live-key'])
        ->toString();
}

function b1SecretAbsent(string $root, string $allowed, string $secret): bool
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        if (! $entry instanceof SplFileInfo || ! $entry->isFile() || $entry->getPathname() === $allowed) {
            continue;
        }
        $contents = file_get_contents($entry->getPathname());
        if (is_string($contents) && str_contains($contents, $secret)) {
            return false;
        }
    }

    return true;
}

$root = dirname(__DIR__, 2);
$stampPath = getenv('BFC_B1_LIVE_STAMP');
if (! is_string($stampPath) || $stampPath === '' || ! str_starts_with($stampPath, DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "BFC_B1_LIVE_STAMP must name an absolute artifact path.\n");
    exit(2);
}

$runDirectory = sys_get_temp_dir().'/bfc-b1-live-'.bin2hex(random_bytes(12));
$manifestDirectory = $runDirectory.'/manifest';
mkdir($runDirectory, 0700);
mkdir($manifestDirectory, 0700);
$sourceListener = null;
$providerListener = null;
$lane = null;
$container = null;
$failure = null;
$databaseName = '';
$candidateSha = '';
$candidateChecksum = '';
$p6Version = '';
$p6Reference = '';
$cases = [];
$thinSourceInventoryPassed = false;
$realProofRoutePassed = false;
$teardown = ['listeners_absent' => false, 'database_absent' => false, 'container_absent' => false, 'files_absent' => false];

try {
    if (trim(b1Run(['git', 'status', '--porcelain'], $root, [], 'clean tree')) !== '') {
        b1Fail('The B1 live rung requires a clean committed candidate.');
    }
    $candidateSha = trim(b1Run(['git', 'rev-parse', 'HEAD'], $root, [], 'candidate SHA'));

    $container = 'bfc-b1-pg-'.bin2hex(random_bytes(6));
    b1Run([
        'docker', 'run', '--rm', '--detach', '--name', $container,
        '--env', 'POSTGRES_HOST_AUTH_METHOD=trust', '--publish', '127.0.0.1::5432', 'postgres:17-alpine',
    ], $root, [], 'PostgreSQL container start');
    $portOutput = trim(b1Run(['docker', 'port', $container, '5432/tcp'], $root, [], 'PostgreSQL port discovery'));
    if (preg_match('/127\.0\.0\.1:([0-9]+)$/', $portOutput, $portMatch) !== 1) {
        b1Fail('The disposable PostgreSQL port was invalid.');
    }
    $administrator = new PostgresAdministrator('127.0.0.1', (int) $portMatch[1], 'postgres', 'postgres', '', 'disable');
    BoundedWait::until(static function () use ($administrator): bool {
        try {
            return $administrator->connect()->query('SELECT 1') !== false;
        } catch (Throwable) {
            return false;
        }
    }, 30, 'The disposable PostgreSQL host service did not become ready.');
    $lane = DisposablePostgresLane::create($administrator, $manifestDirectory);
    $databaseName = $lane->databaseName();

    $sourceArchive = $runDirectory.'/candidate.tar';
    $sourcePackage = $runDirectory.'/candidate';
    mkdir($sourcePackage, 0700);
    b1Run(['git', 'archive', '--format=tar', '--output='.$sourceArchive, $candidateSha], $root, [], 'candidate export');
    b1Run(['tar', '-xf', $sourceArchive, '-C', $sourcePackage], $root, [], 'candidate extraction');
    unlink($sourceArchive);
    $artifactDirectory = $runDirectory.'/artifacts';
    mkdir($artifactDirectory, 0700);
    b1Run(
        ['composer', 'archive', '--format=zip', '--dir='.$artifactDirectory, '--file=bfc-client'],
        $sourcePackage,
        ['COMPOSER_ROOT_VERSION' => '0.0.0+b1.'.$candidateSha],
        'candidate Composer archive',
    );
    $archives = glob($artifactDirectory.'/*.zip') ?: [];
    if (count($archives) !== 1) {
        b1Fail('Composer did not create exactly one candidate archive.');
    }
    $archive = $archives[0];
    $candidateChecksum = (string) hash_file('sha256', $archive);

    $sourceHost = $runDirectory.'/source-host';
    $providerHost = $runDirectory.'/provider-host';
    foreach ([$sourceHost, $providerHost] as $host) {
        b1Run([
            'composer', 'create-project', 'laravel/laravel:^13.0', $host,
            '--no-interaction', '--no-install', '--no-scripts',
        ], $runDirectory, [], 'fresh Laravel host creation');
    }

    foreach ([$sourceHost.'/app/Models/User.php', $sourceHost.'/database/factories/UserFactory.php'] as $authFile) {
        if (! is_file($authFile) || ! unlink($authFile)) {
            b1Fail('The fresh source host auth-file baseline could not be removed.');
        }
    }
    $authMigrations = glob($sourceHost.'/database/migrations/*_create_users_table.php') ?: [];
    if (count($authMigrations) !== 1 || ! unlink($authMigrations[0])) {
        b1Fail('The fresh source host auth-schema baseline could not be removed.');
    }

    $sourceComposer = b1Json((string) file_get_contents($sourceHost.'/composer.json'), 'source composer');
    $candidate = b1Json((string) file_get_contents($sourcePackage.'/composer.json'), 'candidate composer');
    $candidate['version'] = '0.0.0+b1.'.$candidateSha;
    $candidate['dist'] = ['type' => 'zip', 'url' => $archive, 'reference' => $candidateSha];
    $sourceComposer['repositories'] = [['type' => 'package', 'canonical' => true, 'package' => $candidate]];
    $sourceComposer['require']['artisan-build/bfc-client'] = $candidate['version'];
    b1WriteComposer($sourceHost.'/composer.json', $sourceComposer);
    b1Run(['composer', 'update', '--no-interaction', '--prefer-dist', '--no-scripts'], $sourceHost, [], 'source archive install');
    b1Run([PHP_BINARY, 'artisan', 'package:discover', '--no-interaction'], $sourceHost, [], 'source package discovery');
    foreach (['source-inventory.php', 'source-install.php', 'source-request.php'] as $fixture) {
        b1Copy(__DIR__.'/'.$fixture, $sourceHost.'/'.$fixture);
    }

    $sourceLock = b1LockedPackage($sourceHost.'/composer.lock', 'artisan-build/bfc-client');
    b1Same($candidate['version'], $sourceLock['version'] ?? null, 'candidate installed version');
    b1Same($candidateSha, $sourceLock['dist']['reference'] ?? null, 'candidate installed reference');
    $sourceEnvironment = b1Environment([
        'APP_ENV' => 'testing',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'CACHE_STORE' => 'file',
        'SESSION_DRIVER' => 'array',
        'MAIL_MAILER' => 'array',
        'BFC_CLIENT_IDENTITY' => 'b1-live-source',
    ]);
    $inventory = b1Json(b1Run([PHP_BINARY, 'source-inventory.php'], $sourceHost, $sourceEnvironment, 'source inventory'), 'source inventory');
    b1Same(['ArtisanBuild\\BfcClient\\BfcClientServiceProvider'], $inventory['providers'] ?? null, 'source providers');
    b1Same([
        'App\\Providers\\AppServiceProvider',
        'ArtisanBuild\\BfcClient\\BfcClientServiceProvider',
    ], $inventory['application_providers'] ?? null, 'source application providers');
    b1Same(['AppServiceProvider.php'], $inventory['provider_files'] ?? null, 'source provider files');
    b1Same(false, $inventory['server_package_installed'] ?? null, 'source server dependency absence');
    b1Same(['artisan-build/bfc-client'], $inventory['artisan_dependencies'] ?? null, 'source Artisan Build dependencies');
    b1Same([], $inventory['commands'] ?? null, 'source commands');
    b1Same([], $inventory['command_files'] ?? null, 'source command files');
    b1Same([], $inventory['migration_paths'] ?? null, 'source migrations');
    b1Same([
        '0001_01_01_000001_create_cache_table.php',
        '0001_01_01_000002_create_jobs_table.php',
    ], $inventory['migration_files'] ?? null, 'source migration files');
    b1Same([], $inventory['model_files'] ?? null, 'source model files');
    b1Same([], $inventory['factory_files'] ?? null, 'source factory files');
    b1Same(['Controller.php'], $inventory['controller_files'] ?? null, 'source controller files');
    b1Same(['welcome.blade.php'], $inventory['view_files'] ?? null, 'source view files');
    b1Same([], $inventory['package_view_hints'] ?? null, 'source package view hints');
    b1Same([], $inventory['event_files'] ?? null, 'source event files');
    b1Same([], $inventory['listener_files'] ?? null, 'source listener files');
    b1Same([], $inventory['registered_listeners'] ?? null, 'source registered listeners');
    b1Same([], $inventory['scheduled_tasks'] ?? null, 'source scheduled tasks');
    b1Same([
        [['GET', 'HEAD'], '/'],
        [['GET', 'HEAD'], 'bfc-client'],
        [['GET', 'HEAD'], 'up'],
    ], $inventory['route_inventory'] ?? null, 'source route inventory');
    b1Same([[['GET', 'HEAD'], 'bfc-client', 'bfc-client.proof-of-life', ['throttle:bfc-client']]], $inventory['routes'] ?? null, 'source BfC routes');
    b1Same(['web'], $inventory['guards'] ?? null, 'source guards');
    b1Same(true, $inventory['identity_bound'] ?? null, 'source identity binding');
    b1Same(true, $inventory['macro_registered'] ?? null, 'source HTTP macro');
    $thinSourceInventoryPassed = true;

    $installSecret = 'b1_fixture_'.bin2hex(random_bytes(24));
    $installInput = json_encode(['credential' => $installSecret], JSON_THROW_ON_ERROR);
    $firstInstall = b1Json(b1Run([PHP_BINARY, 'source-install.php'], $sourceHost, [], 'source local install', $installInput), 'first source install');
    $installEnv = $sourceHost.'/.env.bfc-client-install';
    $firstBytes = [(string) file_get_contents($installEnv), (string) file_get_contents($sourceHost.'/composer.json')];
    clearstatcache(true);
    $firstStats = [stat($installEnv), stat($sourceHost.'/composer.json')];
    $secondInstall = b1Json(b1Run([PHP_BINARY, 'source-install.php'], $sourceHost, [], 'source local install rerun', $installInput), 'second source install');
    clearstatcache(true);
    b1Same(['environment' => 'replaced', 'composer' => 'replaced'], $firstInstall, 'first source install states');
    b1Same(['environment' => 'unchanged', 'composer' => 'unchanged'], $secondInstall, 'second source install states');
    b1Same($firstBytes, [(string) file_get_contents($installEnv), (string) file_get_contents($sourceHost.'/composer.json')], 'source install bytes');
    b1Same($firstStats, [stat($installEnv), stat($sourceHost.'/composer.json')], 'source install stats');
    if (! str_contains((string) file_get_contents($installEnv), $installSecret)
        || ! b1SecretAbsent($sourceHost, $installEnv, $installSecret)) {
        b1Fail('The install fixture secret escaped its selected env target.');
    }
    $cases['local_install_idempotence_and_secrecy'] = 'pass';

    $sourceListener = P6LoopbackProcess::start(
        [PHP_BINARY, '-S', '127.0.0.1:{port}', '-t', 'public', 'public/index.php'],
        $sourceHost,
        $sourceEnvironment,
        static fn (int $port): bool => b1Ready($port, '/bfc-client'),
    );
    $proof = b1Http($sourceListener->port, 'GET', '/bfc-client');
    b1Status($proof, 200, 'source proof');
    b1Same([
        'contract_major' => 2,
        'package' => 'artisan-build/bfc-client',
        'client_id' => 'b1-live-source',
    ], b1Json($proof['body'], 'source proof'), 'source proof body');
    foreach (range(1, 60) as $ignored) {
        $throttled = b1Http($sourceListener->port, 'GET', '/bfc-client');
    }
    b1Status($throttled, 429, 'source proof throttle');
    $sourceListener->stop();
    $sourceListener = null;
    b1Run([PHP_BINARY, 'artisan', 'cache:clear', '--no-interaction'], $sourceHost, $sourceEnvironment, 'source proof limiter reset');

    $customEnvironment = array_merge($sourceEnvironment, ['BFC_CLIENT_PROOF_OF_LIFE_PATH' => 'custom-proof']);
    $sourceListener = P6LoopbackProcess::start(
        [PHP_BINARY, '-S', '127.0.0.1:{port}', '-t', 'public', 'public/index.php'],
        $sourceHost,
        $customEnvironment,
        static fn (int $port): bool => b1Ready($port, '/custom-proof'),
    );
    b1Status(b1Http($sourceListener->port, 'GET', '/bfc-client'), 404, 'moved proof default path');
    $sourceListener->stop();
    $sourceListener = null;
    $disabledEnvironment = array_merge($sourceEnvironment, ['BFC_CLIENT_PROOF_OF_LIFE' => 'false']);
    $sourceListener = P6LoopbackProcess::start(
        [PHP_BINARY, '-S', '127.0.0.1:{port}', '-t', 'public', 'public/index.php'],
        $sourceHost,
        $disabledEnvironment,
        static fn (int $port): bool => b1Ready($port, '/up'),
    );
    b1Status(b1Http($sourceListener->port, 'GET', '/bfc-client'), 404, 'disabled proof');
    $sourceListener->stop();
    $sourceListener = null;
    $invalidMarker = 'invalid-live-marker';
    $invalidEnvironment = array_merge($sourceEnvironment, ['BFC_CLIENT_IDENTITY' => $invalidMarker."\nvalue"]);
    $sourceListener = P6LoopbackProcess::start(
        [PHP_BINARY, '-S', '127.0.0.1:{port}', '-t', 'public', 'public/index.php'],
        $sourceHost,
        $invalidEnvironment,
        static fn (int $port): bool => b1Ready($port, '/up'),
    );
    $invalidProof = b1Http($sourceListener->port, 'GET', '/bfc-client');
    b1Status($invalidProof, 500, 'invalid proof identity');
    if (str_contains($invalidProof['body'], $invalidMarker)) {
        b1Fail('The invalid proof identity was reflected.');
    }
    $sourceListener->stop();
    $sourceListener = null;
    $realProofRoutePassed = true;
    $cases['real_proof_route'] = 'pass';

    $providerComposer = b1Json((string) file_get_contents($providerHost.'/composer.json'), 'provider composer');
    $providerComposer['require']['artisan-build/built-for-cloud'] = '^0.12';
    b1WriteComposer($providerHost.'/composer.json', $providerComposer);
    b1Run(['composer', 'update', '--no-interaction', '--prefer-dist', '--no-scripts'], $providerHost, [], 'published P6 install');
    foreach (glob($providerHost.'/database/migrations/*.php') ?: [] as $migration) {
        unlink($migration);
    }
    b1Copy($providerHost.'/vendor/artisan-build/built-for-cloud/tests/Live/p6c-host-migration.php', $providerHost.'/database/migrations/0001_01_01_000004_create_p6_live_tables.php');
    b1Copy(__DIR__.'/B1P6ServiceProvider.php', $providerHost.'/app/Providers/B1P6ServiceProvider.php');
    b1Copy(__DIR__.'/provider-seed.php', $providerHost.'/provider-seed.php');
    file_put_contents(
        $providerHost.'/bootstrap/providers.php',
        "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n    App\\Providers\\B1P6ServiceProvider::class,\n];\n",
    );
    b1Run([PHP_BINARY, 'artisan', 'package:discover', '--no-interaction'], $providerHost, [], 'provider package discovery');
    $p6 = b1LockedPackage($providerHost.'/composer.lock', 'artisan-build/built-for-cloud');
    $p6Version = (string) ($p6['version'] ?? '');
    $p6Reference = (string) ($p6['source']['reference'] ?? '');
    if (! in_array($p6Version, ['v0.12.0', 'v0.12.1'], true)) {
        b1Fail('The provider did not install published P6 ^0.12.');
    }

    $audience = 'urn:bfc:installation:'.$databaseName;
    $providerEnvironment = b1Environment([
        'APP_ENV' => 'testing',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'APP_URL' => 'http://127.0.0.1',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => $administrator->host,
        'DB_PORT' => (string) $administrator->port,
        'DB_DATABASE' => $databaseName,
        'DB_USERNAME' => $administrator->username,
        'DB_PASSWORD' => $administrator->password,
        'DB_SSLMODE' => $administrator->sslMode,
        'CACHE_STORE' => 'database',
        'QUEUE_CONNECTION' => 'database',
        'SESSION_DRIVER' => 'array',
        'MAIL_MAILER' => 'array',
        'BFC_B1_AUDIENCE' => $audience,
    ]);
    b1Run([PHP_BINARY, 'artisan', 'migrate:fresh', '--force', '--no-interaction'], $providerHost, $providerEnvironment, 'provider PostgreSQL migration');
    $key = AsymmetricSecretKey::generate(new Version4);
    $seed = b1Json(b1Run(
        [PHP_BINARY, 'provider-seed.php'],
        $providerHost,
        $providerEnvironment,
        'provider credential seed',
        json_encode(['public_key' => $key->getPublicKey()->toHexString()], JSON_THROW_ON_ERROR),
    ), 'provider credential seed');
    foreach (['valid_id', 'valid_secret', 'wrong_secret', 'revoked_secret', 'operator_secret'] as $field) {
        if (! is_string($seed[$field] ?? null) || $seed[$field] === '') {
            b1Fail('The provider credential seed shape was invalid.');
        }
    }

    $providerListener = P6LoopbackProcess::start(
        [PHP_BINARY, '-S', '127.0.0.1:{port}', '-t', 'public', 'public/index.php'],
        $providerHost,
        $providerEnvironment,
        static fn (int $port): bool => b1Ready($port, '/_b1/runtime'),
    );
    $runtime = b1Json(b1Http($providerListener->port, 'GET', '/_b1/runtime')['body'], 'provider runtime');
    b1Same($databaseName, $runtime['database'] ?? null, 'provider database');
    b1Same('database', $runtime['cache'] ?? null, 'provider shared cache');
    b1Same('database', $runtime['replay'] ?? null, 'provider replay state');
    $cases['published_p6_postgres_shared_state'] = 'pass';

    $idempotency = 'b1_'.bin2hex(random_bytes(12));
    $sourceRequest = static function (string $url, string $credential, bool $retry = false) use ($sourceHost, $sourceEnvironment, $idempotency): array {
        return b1Json(b1Run(
            [PHP_BINARY, 'source-request.php'],
            $sourceHost,
            $sourceEnvironment,
            'source decorated request',
            json_encode([
                'url' => $url,
                'credential' => $credential,
                'idempotency_key' => $idempotency,
                'retry' => $retry,
            ], JSON_THROW_ON_ERROR),
        ), 'source decorated request');
    };
    $providerBase = 'http://127.0.0.1:'.$providerListener->port;
    $valid = $sourceRequest($providerBase.'/_b1/probe', $seed['valid_secret']);
    b1Same(200, $valid['status'] ?? null, 'valid fixed-purpose request');
    b1Same([
        'purpose' => 'mcp',
        'subject_ref' => 'b1-installation',
        'client_id' => 'b1-live-source',
        'contract_major' => '2',
        'idempotency_key' => $idempotency,
    ], $valid['body'] ?? null, 'valid fixed-purpose response');
    if (! $thinSourceInventoryPassed || ! $realProofRoutePassed) {
        b1Fail('The thin source host runtime proof was incomplete.');
    }
    $cases['thin_source_host'] = 'pass';
    b1Same(401, $sourceRequest($providerBase.'/_b1/probe', $seed['wrong_secret'])['status'] ?? null, 'wrong purpose');
    b1Same(401, $sourceRequest($providerBase.'/_b1/probe', $seed['revoked_secret'])['status'] ?? null, 'revoked credential');
    $cases['fixed_purpose_and_revocation'] = 'pass';

    $majorHeaders = static fn (string $credential): array => [
        ['BFC-Contract-Version', '2'],
        ['Authorization', 'Bearer '.$credential],
        ['X-BfC-Client-Id', 'b1-live-source'],
    ];
    b1VersionRefusal(b1Http($providerListener->port, 'POST', '/_b1/probe'), 400, 'missing_contract_major');
    b1VersionRefusal(b1Http($providerListener->port, 'POST', '/_b1/probe', [['BFC-Contract-Version', '02']]), 400, 'malformed_contract_major');
    b1VersionRefusal(b1Http($providerListener->port, 'POST', '/_b1/probe', [
        ['BFC-Contract-Version', '2'], ['BFC-Contract-Version', '2'],
    ]), 400, 'malformed_contract_major');
    b1VersionRefusal(b1Http($providerListener->port, 'POST', '/_b1/probe', [['BFC-Contract-Version', '3']]), 426, 'unsupported_contract_major');
    $cases['version_vocabulary'] = 'pass';

    $spoofed = b1Http($providerListener->port, 'POST', '/_b1/probe', [
        ['BFC-Contract-Version', '2'],
        ['Authorization', 'Bearer unknown-b1-credential'],
        ['X-BfC-Client-Id', 'claimed-authority'],
    ]);
    b1Status($spoofed, 401, 'spoofed client identity');
    b1Same(['message' => 'Unauthenticated.'], b1Json($spoofed['body'], 'spoofed client identity'), 'uniform auth refusal');
    $cases['spoofed_client_refused'] = 'pass';

    $assertion = b1Assertion($key, $audience);
    b1Status(b1Http($providerListener->port, 'POST', '/_b1/probe', $majorHeaders($assertion)), 200, 'first assertion');
    b1Status(b1Http($providerListener->port, 'POST', '/_b1/probe', $majorHeaders($assertion)), 401, 'replayed assertion');
    b1Status(b1Http($providerListener->port, 'POST', '/_b1/probe', $majorHeaders(b1Assertion($key, 'https://wrong-audience.test'))), 401, 'wrong audience');
    b1Status(b1Http($providerListener->port, 'POST', '/_b1/probe', $majorHeaders(b1Assertion($key, 'urn:bfc:installation:wrong'))), 401, 'wrong installation');
    $cases['audience_installation_and_replay'] = 'pass';

    $retry = $sourceRequest($providerBase.'/_b1/retry', $seed['valid_secret'], true);
    b1Same(200, $retry['status'] ?? null, 'consumer-owned retry');
    b1Same([
        'attempt' => 2,
        'client_id' => 'b1-live-source',
        'contract_major' => '2',
        'idempotency_key' => $idempotency,
    ], $retry['body'] ?? null, 'retry metadata');
    $cases['caller_owned_retry'] = 'pass';

    $rotation = b1Http($providerListener->port, 'POST', '/bfc/credentials/'.$seed['valid_id'].'/rotate', [
        ['Authorization', 'Bearer '.$seed['operator_secret']],
    ], '{"emergency":true}');
    b1Status($rotation, 201, 'credential rotation');
    $rotationBody = b1Json($rotation['body'], 'credential rotation');
    $replacementId = $rotationBody['credential']['id'] ?? null;
    $replacementSecret = $rotationBody['delivery']['secret'] ?? null;
    if (! is_string($replacementId) || ! is_string($replacementSecret) || $replacementSecret === '') {
        b1Fail('P6 rotation did not return a fixture replacement.');
    }
    b1Status(b1Http($providerListener->port, 'POST', '/_b1/probe', $majorHeaders($seed['valid_secret'])), 401, 'retired credential');
    b1Status(b1Http($providerListener->port, 'POST', '/_b1/probe', $majorHeaders($replacementSecret)), 200, 'replacement credential');
    b1Status(b1Http($providerListener->port, 'DELETE', '/bfc/credentials/'.$replacementId, [
        ['Authorization', 'Bearer '.$seed['operator_secret']],
    ]), 204, 'replacement revocation');
    b1Status(b1Http($providerListener->port, 'POST', '/_b1/probe', $majorHeaders($replacementSecret)), 401, 'revoked replacement');
    $cases['rotation_and_revocation'] = 'pass';
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    try {
        if ($sourceListener instanceof P6LoopbackProcess) {
            $sourceListener->stop();
        }
        if ($providerListener instanceof P6LoopbackProcess) {
            $providerListener->stop();
        }
        $teardown['listeners_absent'] = true;
        if ($lane instanceof DisposablePostgresLane) {
            $teardown['database_absent'] = $lane->teardown()->verdict === 'pass';
        }
        if (is_string($container)) {
            $stop = new Process(['docker', 'stop', '--time', '3', $container], $root);
            $stop->run();
            BoundedWait::until(function () use ($container, $root): bool {
                $probe = new Process(['docker', 'inspect', $container], $root);

                return $probe->run() !== 0;
            }, 10, 'The disposable PostgreSQL container survived teardown.');
            $teardown['container_absent'] = true;
        }
        b1RemoveTree($runDirectory);
        $teardown['files_absent'] = ! file_exists($runDirectory);
    } catch (Throwable $teardownFailure) {
        $failure ??= $teardownFailure;
    }
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, 'B1 live rung failed: '.$failure->getMessage()."\n");
    exit(1);
}

$expectedCases = [
    'local_install_idempotence_and_secrecy',
    'real_proof_route',
    'published_p6_postgres_shared_state',
    'thin_source_host',
    'fixed_purpose_and_revocation',
    'version_vocabulary',
    'spoofed_client_refused',
    'audience_installation_and_replay',
    'caller_owned_retry',
    'rotation_and_revocation',
];
b1Same($expectedCases, array_keys($cases), 'live case inventory');
b1Same(array_fill_keys($expectedCases, 'pass'), $cases, 'live cases');
b1Same(array_fill_keys(array_keys($teardown), true), $teardown, 'live teardown');

$stamp = [
    'schema' => 'bfc-client.b1.live.v1',
    'candidate_sha' => $candidateSha,
    'candidate_archive_sha256' => $candidateChecksum,
    'packages' => [
        'bfc_client' => '0.0.0+b1.'.$candidateSha,
        'built_for_cloud_constraint' => '^0.12',
        'built_for_cloud_version' => $p6Version,
        'built_for_cloud_reference' => $p6Reference,
    ],
    'runtime' => [
        'php' => PHP_VERSION,
        'postgres' => 'postgres:17-alpine',
        'cache' => 'database',
        'replay' => 'database',
        'loopback' => true,
    ],
    'cases' => $cases,
    'teardown' => $teardown,
];
file_put_contents($stampPath, json_encode($stamp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
fwrite(STDOUT, json_encode([
    'candidate_sha' => $candidateSha,
    'p6_version' => $p6Version,
    'cases' => count($cases),
    'teardown' => 'pass',
    'stamp' => $stampPath,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
