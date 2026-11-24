<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_recording_profiles', function (Blueprint $table): void {
            $table->boolean('geolocation_enabled')->default(false)->after('ordinary_weekly_hours');
            $table->timestamp('privacy_notice_acknowledged_at')->nullable()->after('geolocation_enabled');
        });

        Schema::table('time_daily_records', function (Blueprint $table): void {
            $table->unsignedSmallInteger('qualifying_break_minutes')->nullable()->after('break_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('time_recording_profiles', function (Blueprint $table): void {
            $table->dropColumn(['geolocation_enabled', 'privacy_notice_acknowledged_at']);
        });

        Schema::table('time_daily_records', function (Blueprint $table): void {
            $table->dropColumn('qualifying_break_minutes');
        });
    }
};
