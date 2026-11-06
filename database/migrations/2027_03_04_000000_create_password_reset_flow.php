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
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('password_reset_required')->default(false);
            $table->timestamp('last_password_reset_at')->nullable();
        });

        // The framework's table was never used: it keeps one plain row per email and cannot tell a used token apart.
        Schema::dropIfExists('password_reset_tokens');

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('user_id')->index()->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->text('created_user_agent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['password_changed_at', 'password_reset_required', 'last_password_reset_at']);
        });
    }
};
