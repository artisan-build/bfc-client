<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class B1P6ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app['config']->set('cache.default', 'database');
        $this->app['config']->set('cache.stores.database', [
            'driver' => 'database',
            'connection' => null,
            'table' => 'cache',
            'lock_connection' => null,
            'lock_table' => 'cache_locks',
        ]);
        $this->app['config']->set('queue.default', 'database');
        $this->app['config']->set('mail.default', 'array');
        $this->app['config']->set('built-for-cloud.console.enabled', true);
        $this->app['config']->set('built-for-cloud.console.issuer', 'https://b1-p6-authority.test');
        $this->app['config']->set('built-for-cloud.console.audience', (string) env('BFC_B1_AUDIENCE'));
    }

    public function boot(): void
    {
        $this->app->booted(static function (): void {
            Route::get('/_b1/runtime', static fn (): array => [
                'database' => DB::scalar('select current_database()'),
                'cache' => config('cache.default'),
                'replay' => 'database',
            ]);

            Route::post('/_b1/probe', static fn (Request $request): array => [
                'purpose' => $request->user()?->purpose?->value,
                'subject_ref' => $request->user()?->subject_ref,
                'client_id' => $request->header('X-BfC-Client-Id'),
                'contract_major' => $request->header('BFC-Contract-Version'),
                'idempotency_key' => $request->header('Idempotency-Key'),
            ])->middleware(['bfc.contract-major', 'bfc.mcp']);

            Route::post('/_b1/retry', static function (Request $request) {
                $key = (string) $request->header('Idempotency-Key');
                $attempt = Cache::increment('b1-retry:'.hash('sha256', $key));
                $payload = [
                    'attempt' => $attempt,
                    'client_id' => $request->header('X-BfC-Client-Id'),
                    'contract_major' => $request->header('BFC-Contract-Version'),
                    'idempotency_key' => $key,
                ];

                return response()->json($payload, $attempt === 1 ? 503 : 200);
            })->middleware(['bfc.contract-major', 'bfc.mcp']);
        });
    }
}
