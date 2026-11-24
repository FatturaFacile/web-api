<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * White-label settings of the customer portal (one row per tenant) and the second-factor state of a magic link.
 * Plain columns only, so the migration behaves the same on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('portal_slug', 63)->nullable()->unique();
            $table->string('custom_domain', 253)->nullable()->unique();
            $table->string('logo_url', 500)->nullable();
            $table->string('favicon_url', 500)->nullable();
            $table->string('primary_color', 7)->nullable();
            $table->string('secondary_color', 7)->nullable();
            $table->string('support_email', 254)->nullable();
            $table->string('privacy_policy_url', 500)->nullable();
            $table->boolean('require_otp')->default(false);
            $table->boolean('allow_partial_payments')->default(false);
            $table->timestampsTz();
        });

        Schema::table('portal_access_tokens', function (Blueprint $table): void {
            $table->string('otp_hash', 64)->nullable();
            $table->timestampTz('otp_expires_at')->nullable();
            $table->unsignedSmallInteger('otp_attempts')->default(0);
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->timestampTz('portal_consent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('portal_consent_at');
        });

        Schema::table('portal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn(['otp_hash', 'otp_expires_at', 'otp_attempts']);
        });

        Schema::dropIfExists('portal_settings');
    }
};
