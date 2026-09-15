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
$barrier = @stream_socket_client('tcp://'.$argv[3], $errorCode, $errorMessage, 10);

if ($barrier === false || fwrite($barrier, "ready\n") !== 6 || fgets($barrier) !== "go\n") {
    fwrite(STDERR, 'The identity fixture barrier failed.');
    exit(1);
}

fclose($barrier);

fwrite(STDOUT, (new ClientIdentity($config, new Filesystem))->resolve());
