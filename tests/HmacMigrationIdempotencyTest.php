<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');
    DB::purge('sqlite');
});

function hmacCredentialsMigration(): Migration
{
    return require __DIR__.'/../database/migrations/2026_10_13_000001_create_bfc_client_hmac_credentials_table.php';
}

it('creates both HMAC tables with their columns on an empty schema', function (): void {
    hmacCredentialsMigration()->up();

    expect(Schema::hasColumns('bfc_client_hmac_credentials', [
        'id', 'purpose', 'subject_type', 'subject_ref', 'status', 'secret_ciphertext',
        'secret_key_version', 'delivered_at', 'delivered_generation', 'delivery_fingerprint',
        'predecessor_credential_id', 'activated_at', 'expires_at', 'revoked_at', 'last_used_at',
        'created_at', 'updated_at',
    ]))->toBeTrue();
    expect(Schema::hasColumns('bfc_client_hmac_bindings', [
        'credential_id', 'app_purpose', 'installation_ref', 'application_ref', 'audience',
        'algorithm', 'material_role', 'scope_hash', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('leaves existing HMAC tables and their data intact on a rerun', function (): void {
    $migration = hmacCredentialsMigration();
    $migration->up();

    foreach (['bfc_client_hmac_credentials', 'bfc_client_hmac_bindings'] as $table) {
        Schema::table($table, function (Blueprint $table): void {
            $table->string('existing_marker')->nullable();
        });
    }

    $credentialColumns = Schema::getColumnListing('bfc_client_hmac_credentials');
    $bindingColumns = Schema::getColumnListing('bfc_client_hmac_bindings');
    DB::table('bfc_client_hmac_credentials')->insert([
        'id' => 'existing-credential',
        'purpose' => 'signing',
        'subject_type' => 'installation',
        'subject_ref' => 'existing-installation',
        'status' => 'active',
        'delivered_at' => '2026-11-18 00:00:00',
        'delivered_generation' => 1,
        'delivery_fingerprint' => '0123456789abcdef',
        'activated_at' => '2026-11-18 00:00:00',
        'existing_marker' => 'credential-preserved',
    ]);
    DB::table('bfc_client_hmac_bindings')->insert([
        'credential_id' => 'existing-credential',
        'app_purpose' => 'callback',
        'installation_ref' => 'existing-installation',
        'application_ref' => 'existing-application',
        'audience' => 'https://client.example/callback',
        'algorithm' => 'hmac-sha256',
        'material_role' => 'signing',
        'scope_hash' => str_repeat('a', 64),
        'existing_marker' => 'binding-preserved',
    ]);

    $migration->up();

    expect(Schema::getColumnListing('bfc_client_hmac_credentials'))->toBe($credentialColumns);
    expect(Schema::getColumnListing('bfc_client_hmac_bindings'))->toBe($bindingColumns);
    expect(DB::table('bfc_client_hmac_credentials')->value('existing_marker'))->toBe('credential-preserved');
    expect(DB::table('bfc_client_hmac_bindings')->value('existing_marker'))->toBe('binding-preserved');
});

it('creates bindings when only credentials already exist', function (): void {
    $migration = hmacCredentialsMigration();
    $migration->up();
    Schema::drop('bfc_client_hmac_bindings');

    $migration->up();

    expect(Schema::hasTable('bfc_client_hmac_credentials'))->toBeTrue();
    expect(Schema::hasColumn('bfc_client_hmac_bindings', 'credential_id'))->toBeTrue();
});
