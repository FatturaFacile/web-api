<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extraction proposals wait for a person. The per-tenant setting starts disabled: without a row no customer data
     * can reach an external provider.
     */
    public function up(): void
    {
        Schema::create('tenant_ai_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->boolean('external_providers_enabled')->default(false);
            $table->uuid('changed_by')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('dms_extractions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('document_id')->index();
            $table->uuid('document_version_id')->index();
            $table->string('status', 20)->default('proposed')->index();
            $table->string('driver', 60)->nullable();
            $table->jsonb('proposed_fields')->default('[]');
            $table->jsonb('confirmed_fields')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->uuid('requested_by')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('document_id')->references('id')->on('dms_documents')->cascadeOnDelete();
            $table->foreign('document_version_id')->references('id')->on('dms_document_versions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dms_extractions');
        Schema::dropIfExists('tenant_ai_settings');
    }
};
