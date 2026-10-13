<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bfc_client_hmac_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('purpose');
            $table->string('subject_type');
            $table->string('subject_ref');
            $table->string('status');
            $table->text('secret_ciphertext')->nullable();
            $table->string('secret_key_version', 64)->nullable()->index();
            $table->timestamp('delivered_at');
            $table->unsignedInteger('delivered_generation');
            $table->string('delivery_fingerprint', 16);
            $table->uuid('predecessor_credential_id')->nullable();
            $table->timestamp('activated_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_ref', 'status']);
            $table->index('predecessor_credential_id');
        });

        Schema::create('bfc_client_hmac_bindings', function (Blueprint $table): void {
            $table->foreignUuid('credential_id')
                ->primary()
                ->constrained('bfc_client_hmac_credentials')
                ->cascadeOnDelete();
            $table->string('app_purpose');
            $table->string('installation_ref');
            $table->string('application_ref');
            $table->string('audience');
            $table->string('algorithm');
            $table->string('material_role');
            $table->char('scope_hash', 64)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bfc_client_hmac_bindings');
        Schema::dropIfExists('bfc_client_hmac_credentials');
    }
};
