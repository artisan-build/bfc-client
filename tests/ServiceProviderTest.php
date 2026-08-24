<?php

declare(strict_types=1);

use ArtisanBuild\BfcClient\BfcClientServiceProvider;

it('registers the service provider in the application', function () {
    expect($this->app->getProviders(BfcClientServiceProvider::class))->not->toBeEmpty();
});

it('merges the package config with a null identity by default', function () {
    expect(config('bfc-client'))->toBeArray()
        ->and(config('bfc-client'))->toHaveKey('identity')
        ->and(config('bfc-client.identity'))->toBeNull();
});

it('reads back an explicitly configured identity', function () {
    config()->set('bfc-client.identity', 'client-abc-123');

    expect(config('bfc-client.identity'))->toBe('client-abc-123');
});
