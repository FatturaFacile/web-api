<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification matrix: delivery frequency per category/channel (immediate or daily digest) and the per-user
 * do-not-disturb settings (quiet hours, snooze).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_preferences') && ! Schema::hasColumn('notification_preferences', 'frequency')) {
            Schema::table('notification_preferences', function (Blueprint $table): void {
                $table->string('frequency', 12)->default('immediate');
            });
        }

        if (! Schema::hasTable('notification_settings')) {
            Schema::create('notification_settings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->unique();
                $table->boolean('quiet_hours_enabled')->default(false);
                $table->string('quiet_start', 5)->default('20:00');
                $table->string('quiet_end', 5)->default('08:00');
                $table->string('timezone', 64)->default('Europe/Rome');
                $table->timestamp('muted_until')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');

        if (Schema::hasColumn('notification_preferences', 'frequency')) {
            Schema::table('notification_preferences', function (Blueprint $table): void {
                $table->dropColumn('frequency');
            });
        }
    }
};
