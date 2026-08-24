<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Tests;

use ArtisanBuild\BfcClient\BfcClientServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BfcClientServiceProvider::class];
    }
}
