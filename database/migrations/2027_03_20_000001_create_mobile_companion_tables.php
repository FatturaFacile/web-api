<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_device_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('device_id', 191);
            $table->string('device_name', 191)->nullable();
            $table->string('platform', 30);
            $table->string('push_provider', 10)->nullable();
            $table->text('push_token')->nullable();
            $table->string('app_version', 30)->nullable();
            $table->boolean('biometric_enrolled')->default(false);
            $table->unsignedBigInteger('access_token_id')->nullable()->index();
            $table->timestampTz('last_active_at')->nullable();
            $table->boolean('is_revoked')->default(false);
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoke_reason', 100)->nullable();
            $table->timestampTz('wipe_requested_at')->nullable();
            $table->timestampTz('wipe_acknowledged_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'user_id', 'device_id']);
            $table->index(['user_id', 'is_revoked']);
        });

        Schema::create('mobile_offline_cache_manifests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('mobile_device_session_id')->constrained('mobile_device_sessions')->cascadeOnDelete();
            $table->string('entity_name', 60);
            $table->timestampTz('last_synced_at')->nullable();
            $table->unsignedInteger('record_count')->default(0);
            $table->unsignedBigInteger('storage_bytes')->default(0);
            $table->timestampsTz();

            $table->unique(['mobile_device_session_id', 'entity_name'], 'mobile_cache_manifest_unique');
        });

        Schema::create('work_sites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_meters')->default(150);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('employee_shifts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('work_site_id')->nullable()->constrained('work_sites')->nullOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('shift_type', 30)->default('regular');
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'starts_at']);
        });

        Schema::create('payslip_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('document_type', 20); // payslip, cu, lohnausweis
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month')->nullable();
            $table->string('title', 191);
            $table->string('file_path', 500);
            $table->string('country', 2)->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->string('read_ip', 64)->nullable();
            $table->string('read_device_id', 191)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'document_type', 'period_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_documents');
        Schema::dropIfExists('employee_shifts');
        Schema::dropIfExists('work_sites');
        Schema::dropIfExists('mobile_offline_cache_manifests');
        Schema::dropIfExists('mobile_device_sessions');
    }
};
