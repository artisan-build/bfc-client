<?php

declare(strict_types=1);

use ArtisanBuild\BfcClient\ClientIdentity;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;

require $argv[1].'/vendor/autoload.php';

$app = new Application($argv[1]);
$app->useStoragePath($argv[2]);
$config = new Repository(['bfc-client' => ['identity' => null]]);
$ready = $argv[4];

file_put_contents($ready, 'ready');

$deadline = microtime(true) + 10;
while (! is_file($argv[3]) && microtime(true) < $deadline) {
    usleep(5_000);
}

if (! is_file($argv[3])) {
    fwrite(STDERR, 'The identity fixture barrier timed out.');
    exit(1);
}

fwrite(STDOUT, (new ClientIdentity($config, new Filesystem))->resolve());
