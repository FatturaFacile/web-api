<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Swiss working time recording (art. 46 LL, art. 73, 73a and 73b OLL 1). Instants are stored as UTC in columns
     * without time zone, whatever the session time zone of the connection; they are evaluated in Europe/Zurich.
     * The employee is the user (`user_id`), and it carries no foreign key on purpose: the records must outlive the
     * account for the legal retention period.
     */
    public function up(): void
    {
        Schema::create('time_clock_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->timestamp('occurred_at');
            $table->string('event_type', 20);
            $table->string('source', 20);
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->text('deletion_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'user_id', 'occurred_at']);
            $table->index(['tenant_id', 'occurred_at']);
        });

        Schema::create('time_daily_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->date('work_date');
            $table->unsignedInteger('worked_minutes');
            $table->timestamp('start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'user_id', 'work_date']);
            $table->index(['tenant_id', 'work_date']);
        });

        // Like the audit ledger, no foreign keys: nothing may cascade into this table.
        Schema::create('time_audit_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            $table->string('entity_type', 60);
            $table->uuid('entity_id')->nullable();
            $table->string('action', 30);
            $table->jsonb('before_values')->nullable();
            $table->jsonb('after_values')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('actor_name', 255);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'user_id', 'occurred_at']);
        });

        Schema::create('time_recording_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->string('mode', 20)->default('Detailed');
            $table->date('agreement_date')->nullable();
            $table->unsignedSmallInteger('legal_max_weekly_hours')->default(45);
            $table->decimal('weekly_hours', 5, 2)->default(42.00);
            $table->unsignedTinyInteger('work_days_per_week')->default(5);
            $table->date('hire_date')->nullable();
            $table->date('termination_date')->nullable();
            $table->string('canton', 2)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'user_id']);
        });

        // A null tenant is a generic row shared by every company, a null canton is valid in every canton.
        Schema::create('time_public_holidays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('canton', 2)->nullable();
            $table->date('holiday_date');
            $table->string('name', 150);
            $table->decimal('day_fraction', 3, 2)->default(1.00);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'holiday_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_public_holidays');
        Schema::dropIfExists('time_recording_profiles');
        Schema::dropIfExists('time_audit_entries');
        Schema::dropIfExists('time_daily_records');
        Schema::dropIfExists('time_clock_events');
    }
};
