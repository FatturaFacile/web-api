<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_signing_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('driver', 20)->default('local');
            $table->text('certificate')->nullable();
            $table->text('private_key')->nullable();
            $table->text('passphrase')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('document_signatures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('document_type', 30);
            $table->uuid('document_id');
            $table->string('driver', 20);
            $table->string('format', 30);
            $table->string('content_sha256', 64);
            $table->text('signature');
            $table->timestamps();

            $table->index(['tenant_id', 'document_type', 'document_id']);
        });

        Schema::create('preservation_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('document_type', 30);
            $table->uuid('document_id');
            $table->string('provider', 20);
            $table->string('status', 30);
            $table->string('reference', 100)->nullable();
            $table->string('content_sha256', 64);
            $table->timestamps();

            $table->index(['tenant_id', 'document_type', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preservation_records');
        Schema::dropIfExists('document_signatures');
        Schema::dropIfExists('document_signing_settings');
    }
};
