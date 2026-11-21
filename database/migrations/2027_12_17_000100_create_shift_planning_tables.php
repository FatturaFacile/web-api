<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workforce shift planning and roster scheduling (Mod 073): the reusable shift templates, the roster periods, the scheduled
 * shift assignments (an assignment without an employee is an open shift) and the peer shift swap requests. Employees,
 * locations and skills are plain references to the HR tables (no foreign key across modules). JSON columns are jsonb on
 * PostgreSQL and text on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_shift_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 32);
            $table->jsonb('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->string('color_hex', 7)->default('#1890FF');
            $table->boolean('night_surcharge_applicable')->default(true);
            $table->boolean('is_on_call')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('roster_schedule_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('location_or_department_id')->nullable();
            $table->uuid('production_line_id')->nullable();
            $table->string('period_type', 12);
            $table->string('jurisdiction', 2);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 12)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->uuid('locked_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'start_date', 'end_date']);
            $table->index(['tenant_id', 'location_or_department_id', 'status']);
            $table->index(['tenant_id', 'production_line_id']);
        });

        Schema::create('roster_shift_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('roster_schedule_period_id')->constrained('roster_schedule_periods')->cascadeOnDelete();
            $table->uuid('employee_id')->nullable();
            $table->date('shift_date');
            $table->foreignUuid('shift_template_id')->constrained('roster_shift_templates')->restrictOnDelete();
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->uuid('required_role_or_skill_id')->nullable();
            $table->decimal('estimated_labor_cost', 14, 2)->default(0);
            $table->jsonb('surcharge_breakdown')->nullable();
            $table->jsonb('compliance_warnings')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'roster_schedule_period_id', 'shift_date'], 'roster_assign_period_date_idx');
            $table->index(['tenant_id', 'employee_id', 'start_at'], 'roster_assign_employee_start_idx');
            $table->index(['tenant_id', 'employee_id', 'end_at'], 'roster_assign_employee_end_idx');
        });

        Schema::create('roster_shift_swap_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('requester_employee_id');
            $table->uuid('target_peer_employee_id')->nullable();
            $table->foreignUuid('shift_assignment_id')->constrained('roster_shift_assignments')->cascadeOnDelete();
            $table->uuid('target_shift_assignment_id')->nullable();
            $table->string('status', 32)->default('pending_peer_acceptance');
            $table->timestamp('peer_responded_at')->nullable();
            $table->uuid('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'requester_employee_id', 'status'], 'roster_swap_requester_idx');
            $table->index(['tenant_id', 'target_peer_employee_id', 'status'], 'roster_swap_peer_idx');
            $table->index(['tenant_id', 'shift_assignment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_shift_swap_requests');
        Schema::dropIfExists('roster_shift_assignments');
        Schema::dropIfExists('roster_schedule_periods');
        Schema::dropIfExists('roster_shift_templates');
    }
};
