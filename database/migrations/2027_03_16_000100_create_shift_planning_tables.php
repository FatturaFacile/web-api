<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shift planning. Shift types, sequences and staffing needs are the rules of the plan; hr_shifts holds the plan itself,
     * with its instants in UTC so that a shift across a clock change keeps its real length. Times of a type are local to
     * Europe/Zurich. The employee is hr_employees.id: a planned employee may have no user account.
     */
    public function up(): void
    {
        Schema::create('hr_shift_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('category', 20);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->boolean('is_paid_absence')->default(false);
            $table->string('color', 9)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('hr_shift_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('pattern', 30);
            $table->jsonb('steps');
            $table->boolean('skip_holidays')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hr_shift_sequence_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignUuid('shift_sequence_id')->constrained('hr_shift_sequences')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('offset_days')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'employee_id', 'start_date']);
        });

        Schema::create('hr_shift_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('exception_date');
            $table->foreignUuid('employee_id')->nullable()->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('hr_departments')->cascadeOnDelete();
            $table->foreignUuid('shift_type_id')->nullable()->constrained('hr_shift_types')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'exception_date']);
        });

        Schema::create('hr_staffing_needs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('department_id')->constrained('hr_departments')->cascadeOnDelete();
            $table->foreignUuid('shift_type_id')->constrained('hr_shift_types')->cascadeOnDelete();
            $table->string('day_kind', 10);
            $table->unsignedSmallInteger('required_count');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'department_id', 'day_kind']);
        });

        Schema::create('hr_shift_publications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->foreignUuid('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->unsignedInteger('shift_count');
            $table->unsignedInteger('employee_count');
            $table->jsonb('violations')->nullable();
            $table->text('acknowledgement_reason')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'published_at']);
        });

        Schema::create('hr_shifts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->foreignUuid('shift_type_id')->constrained('hr_shift_types')->restrictOnDelete();
            $table->date('work_date');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedInteger('planned_minutes')->default(0);
            $table->string('status', 20)->default('draft');
            $table->string('source', 20)->default('manual');
            $table->foreignUuid('shift_publication_id')->nullable()->constrained('hr_shift_publications')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'work_date', 'status']);
            $table->index(['tenant_id', 'employee_id', 'work_date']);
        });

        DB::statement('CREATE UNIQUE INDEX hr_shifts_one_type_a_day ON hr_shifts (tenant_id, employee_id, work_date, shift_type_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_shifts');
        Schema::dropIfExists('hr_shift_publications');
        Schema::dropIfExists('hr_staffing_needs');
        Schema::dropIfExists('hr_shift_exceptions');
        Schema::dropIfExists('hr_shift_sequence_assignments');
        Schema::dropIfExists('hr_shift_sequences');
        Schema::dropIfExists('hr_shift_types');
    }
};
