<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('email_verification_token', 64)->nullable()->unique();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->boolean('is_platform_admin')->default(false);
        });

        Schema::create('tenant_access_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            // The tenant matched by tax identifier at registration, then the one chosen by the platform administrator.
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('requested_company_name');
            $table->string('requested_tax_id', 30);
            $table->string('requested_country', 2);
            $table->string('status', 20)->default('pending')->index();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_access_requests');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['email_verification_token']);
            $table->dropColumn([
                'first_name',
                'last_name',
                'email_verification_token',
                'email_verification_expires_at',
                'terms_accepted_at',
                'privacy_accepted_at',
                'is_platform_admin',
            ]);
        });
    }
};
