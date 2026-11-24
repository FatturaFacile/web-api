<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resource planning and capacity saturation (Mod 079). Employees are hr_employees.id (Mod 068); projects and WBS nodes
     * (project_tasks) belong to Mod 078, opportunities to the CRM (Mod 035). Those three are plain uuid columns so that the
     * planning data survives a reorganisation of the neighbouring modules.
     */
    public function up(): void
    {
        Schema::create('rp_skills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('category', 60);
            $table->string('skill_name', 150);
            $table->boolean('requires_certification')->default(false);
            $table->string('certification_code', 60)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'category', 'skill_name']);
        });

        Schema::create('rp_employee_skill_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignUuid('skill_id')->constrained('rp_skills')->cascadeOnDelete();
            $table->unsignedTinyInteger('proficiency_level');
            $table->date('valid_until_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'employee_id', 'skill_id']);
        });

        Schema::create('rp_resource_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('wbs_node_id')->nullable()->index();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->uuid('role_id')->nullable();
            $table->string('booking_type', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('allocated_hours_per_day', 5, 2);
            $table->decimal('total_allocated_hours', 9, 2)->default(0);
            $table->boolean('is_billable')->default(true);
            $table->uuid('crm_opportunity_id')->nullable()->index();
            $table->boolean('is_overloaded')->default(false);
            $table->decimal('peak_load_percent', 7, 2)->default(0);
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('converted_to_hard_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'employee_id', 'start_date', 'end_date'], 'rp_alloc_employee_window');
        });

        Schema::create('rp_resource_capacity_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('week_start_date');
            $table->decimal('contractual_hours', 7, 2)->default(0);
            $table->decimal('leave_and_holiday_hours', 7, 2)->default(0);
            $table->decimal('net_available_hours', 7, 2)->default(0);
            $table->decimal('billable_booked_hours', 7, 2)->default(0);
            $table->decimal('billable_committed_hours', 7, 2)->default(0);
            $table->decimal('internal_booked_hours', 7, 2)->default(0);
            $table->decimal('tentative_booked_hours', 7, 2)->default(0);
            $table->decimal('utilization_rate_percent', 7, 2)->default(0);
            $table->uuid('department_id')->nullable()->index();
            $table->uuid('cost_center_id')->nullable()->index();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'employee_id', 'week_start_date'], 'rp_snapshot_employee_week');
        });

        // Billable saturation targets: both dimensions null is the tenant default.
        Schema::create('rp_utilization_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('department_id')->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->decimal('target_percent', 5, 2);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'department_id', 'cost_center_id'], 'rp_target_scope');
        });

        // Municipal patron saints and other local holidays that no national or cantonal calendar knows. A null tenant is a
        // generic row shared by every company; a null year recurs every year.
        Schema::create('rp_local_holidays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('country', 2);
            $table->string('region_code', 10)->nullable();
            $table->string('municipality', 120)->nullable();
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('day');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('name', 150);
            $table->decimal('day_fraction', 3, 2)->default(1.00);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'country', 'region_code']);
        });
    }

    public function down(): void
    {
        foreach (['rp_local_holidays', 'rp_utilization_targets', 'rp_resource_capacity_snapshots', 'rp_resource_allocations', 'rp_employee_skill_profiles', 'rp_skills'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
