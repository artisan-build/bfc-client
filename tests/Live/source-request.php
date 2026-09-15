<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode((string) stream_get_contents(STDIN), true);

if (! is_array($input)
    || ! is_string($input['url'] ?? null)
    || ! is_string($input['credential'] ?? null)
    || ! is_string($input['idempotency_key'] ?? null)) {
    fwrite(STDERR, 'The source request fixture input is invalid.');
    exit(2);
}

$request = Http::withClientIdentity()
    ->withToken($input['credential'])
    ->withHeader('Idempotency-Key', $input['idempotency_key']);

if (($input['retry'] ?? false) === true) {
    $request->retry(2, 0, throw: false);
}

$response = $request->post($input['url']);

fwrite(STDOUT, json_encode([
    'status' => $response->status(),
    'body' => $response->json(),
], JSON_THROW_ON_ERROR));
