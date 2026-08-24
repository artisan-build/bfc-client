<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('responds at the default path with exactly the package name and client identity', function () {
    config()->set('bfc-client.identity', 'proof-of-life-identity-pr4');

    $response = $this->getJson('/bfc-client');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/json');

    // Exact equality, not subset: the payload is these two keys and nothing else.
    expect($response->json())->toBe([
        'package' => 'artisan-build/bfc-client',
        'client_id' => 'proof-of-life-identity-pr4',
    ]);
});

it('never leaks the application key in the response', function () {
    config()->set('app.key', 'base64:'.base64_encode('bfc-proof-of-life-canary-secret!'));
    config()->set('bfc-client.identity', 'proof-of-life-identity-pr4');

    $content = $this->getJson('/bfc-client')->getContent();

    expect($content)->not->toContain('bfc-proof-of-life-canary-secret!')
        ->and($content)->not->toContain(base64_encode('bfc-proof-of-life-canary-secret!'));
});

it('registers the route under the expected name', function () {
    expect(Route::has('bfc-client.proof-of-life'))->toBeTrue();
});

it('throttles the route at 60 requests per minute', function () {
    $route = Route::getRoutes()->getByName('bfc-client.proof-of-life');

    expect($route)->not->toBeNull()
        ->and($route->middleware())->toContain('throttle:60,1');
});
