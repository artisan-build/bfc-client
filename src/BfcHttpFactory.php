<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient;

use Illuminate\Http\Client\Factory;

/**
 * The package-private HTTP factory type.
 *
 * It adds no behaviour. It exists so the private factory has a container key
 * of its own that is a class name rather than a string, and so it can never
 * be mistaken for — or resolved in place of — the shared
 * `Illuminate\Http\Client\Factory` behind the `Http` facade.
 *
 * It must be constructed with no dispatcher; see
 * `BfcClientServiceProvider::register()` and {@see BfcHttp}.
 */
final class BfcHttpFactory extends Factory {}
