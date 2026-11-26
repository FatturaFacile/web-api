<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Progressive lockout (each lockout without a successful login in between doubles the lock, up to a cap) and the
     * compromised-password check. Additive only: defaulted columns, so a tenant keeps today's behavior until it opts in.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedSmallInteger('lockout_streak')->default(0);
        });

        Schema::table('tenant_security_policies', function (Blueprint $table): void {
            $table->boolean('lockout_backoff')->default(false);
            $table->unsignedSmallInteger('lockout_max_minutes')->default(1440);
            $table->boolean('password_check_breached')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_security_policies', function (Blueprint $table): void {
            $table->dropColumn(['lockout_backoff', 'lockout_max_minutes', 'password_check_breached']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('lockout_streak');
        });
    }
};
