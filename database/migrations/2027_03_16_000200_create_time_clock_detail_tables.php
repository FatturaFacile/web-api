<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Time clock details. Rules of a schedule group are versioned by valid_from and never edited: what was in force on a
     * past day stays readable. Nothing here changes a clock event: rounding and allowances are computed on the way to the
     * payroll, the recorded real minutes stay the only record. user_id carries no foreign key, as in time_clock_events.
     */
    public function up(): void
    {
        Schema::create('time_schedule_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('time_schedule_group_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('schedule_group_id')->constrained('time_schedule_groups')->cascadeOnDelete();
            $table->date('valid_from');
            $table->unsignedSmallInteger('tolerance_before_minutes')->default(0);
            $table->unsignedSmallInteger('tolerance_after_minutes')->default(0);
            $table->unsignedSmallInteger('rounding_minutes')->default(0);
            $table->string('rounding_mode', 20)->default('none');
            $table->decimal('night_allowance_percent', 7, 4)->nullable();
            $table->decimal('sunday_allowance_percent', 7, 4)->nullable();
            $table->decimal('holiday_allowance_percent', 7, 4)->nullable();
            $table->decimal('daily_allowance_amount', 12, 2)->nullable();
            $table->unsignedSmallInteger('daily_allowance_minimum_minutes')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['schedule_group_id', 'valid_from']);
        });

        Schema::create('time_schedule_group_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('schedule_group_id')->constrained('time_schedule_groups')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'user_id', 'valid_from']);
        });

        Schema::create('time_badges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->string('badge_code', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'badge_code']);
        });

        Schema::create('time_meal_bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->date('meal_date');
            $table->string('meal_type', 30);
            $table->timestamp('occurred_at');
            $table->string('source', 20);
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'user_id', 'meal_date', 'meal_type']);
        });

        Schema::create('time_punch_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('reader_id', 120);
            $table->string('external_id', 190);
            $table->uuid('user_id')->nullable();
            $table->uuid('time_clock_event_id')->nullable();
            $table->uuid('time_meal_booking_id')->nullable();
            $table->timestamp('imported_at');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reader_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_punch_imports');
        Schema::dropIfExists('time_meal_bookings');
        Schema::dropIfExists('time_badges');
        Schema::dropIfExists('time_schedule_group_members');
        Schema::dropIfExists('time_schedule_group_rules');
        Schema::dropIfExists('time_schedule_groups');
    }
};
