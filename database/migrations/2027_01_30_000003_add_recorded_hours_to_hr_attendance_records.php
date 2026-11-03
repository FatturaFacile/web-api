<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hr_attendance_records', 'recorded_hours')) {
            Schema::table('hr_attendance_records', function (Blueprint $table): void {
                $table->decimal('recorded_hours', 6, 2)->nullable()->after('break_minutes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hr_attendance_records', 'recorded_hours')) {
            Schema::table('hr_attendance_records', function (Blueprint $table): void {
                $table->dropColumn('recorded_hours');
            });
        }
    }
};
