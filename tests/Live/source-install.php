<?php

declare(strict_types=1);

use ArtisanBuild\BfcClient\Install\InstallFiles;

require __DIR__.'/vendor/autoload.php';

$input = json_decode((string) stream_get_contents(STDIN), true);
$credential = is_array($input) && is_string($input['credential'] ?? null) ? $input['credential'] : null;

if ($credential === null) {
    fwrite(STDERR, 'The local install fixture requires an in-memory credential.');
    exit(2);
}

$result = (new InstallFiles)->install(
    __DIR__.'/.env.bfc-client-install',
    __DIR__.'/composer.json',
    ['SOURCE_CREDENTIAL' => $credential, 'BFC_CLIENT_IDENTITY' => 'b1-live-source'],
    ['vendor/source-client' => 2],
);

fwrite(STDOUT, json_encode($result->stages(), JSON_THROW_ON_ERROR));
exit($result->succeeded() ? 0 : 1);
