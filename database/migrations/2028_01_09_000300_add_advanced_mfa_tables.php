<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advanced second factors: the email one-time code factor of a user (a confirmation timestamp next to the TOTP one) and
 * the trusted devices that skip the second factor for a while ("remember this device"). A trusted device belongs to the
 * user, not to a tenant, and keeps only the SHA-256 hash of its token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('two_factor_email_confirmed_at')->nullable();
        });

        Schema::create('trusted_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('label', 120)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('two_factor_email_confirmed_at');
        });
    }
};
