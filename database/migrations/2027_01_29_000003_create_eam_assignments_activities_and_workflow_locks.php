<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Asset Assignments (Employee, Department, Project, Site/Facility Assignments)
        Schema::create('eam_asset_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('company_id')->index();
            $table->uuid('asset_id')->index();
            $table->string('assignment_number', 64);
            $table->string('assignee_type', 32)->default('employee'); // employee, department, project, site, vehicle_operator
            $table->uuid('assignee_id')->nullable()->index();
            $table->string('assignee_name', 180);
            $table->uuid('department_id')->nullable()->index();
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('location_id')->nullable()->index();
            $table->date('assigned_at');
            $table->date('expected_return_at')->nullable();
            $table->date('returned_at')->nullable();
            $table->string('condition_on_assignment', 32)->default('good');
            $table->string('condition_on_return', 32)->nullable();
            $table->string('status', 32)->default('active')->index(); // active, returned, overdue, lost
            $table->uuid('assigned_by')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'assignment_number']);
            $table->foreign('asset_id')->references('id')->on('eam_fixed_assets')->cascadeOnDelete();
        });

        // 2. Maintenance Activities (Granular tasks, spare parts consumption, technician hours per Work Order)
        Schema::create('eam_maintenance_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('company_id')->index();
            $table->uuid('work_order_id')->index();
            $table->uuid('asset_id')->index();
            $table->string('activity_code', 64);
            $table->string('activity_type', 48)->default('inspection'); // inspection, repair, calibration, lubrication, part_replacement, overhaul
            $table->string('description', 255);
            $table->uuid('performed_by')->nullable()->index();
            $table->decimal('duration_hours', 10, 2)->default(0);
            $table->decimal('labor_cost', 19, 4)->default(0);
            $table->decimal('spare_parts_cost', 19, 4)->default(0);
            $table->jsonb('spare_parts_consumed')->nullable();
            $table->string('status', 32)->default('completed')->index(); // pending, in_progress, completed, skipped
            $table->timestamp('completed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('work_order_id')->references('id')->on('eam_maintenance_work_orders')->cascadeOnDelete();
            $table->foreign('asset_id')->references('id')->on('eam_fixed_assets')->cascadeOnDelete();
        });

        // 3. Add Workflow V2 locking columns to eam_asset_transfers and eam_maintenance_work_orders
        Schema::table('eam_asset_transfers', function (Blueprint $table): void {
            if (! Schema::hasColumn('eam_asset_transfers', 'workflow_instance_id')) {
                $table->uuid('workflow_instance_id')->nullable()->index();
            }
            if (! Schema::hasColumn('eam_asset_transfers', 'is_locked')) {
                $table->boolean('is_locked')->default(false);
            }
            if (! Schema::hasColumn('eam_asset_transfers', 'locked_by_workflow_at')) {
                $table->timestamp('locked_by_workflow_at')->nullable();
            }
        });

        Schema::table('eam_maintenance_work_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('eam_maintenance_work_orders', 'workflow_instance_id')) {
                $table->uuid('workflow_instance_id')->nullable()->index();
            }
            if (! Schema::hasColumn('eam_maintenance_work_orders', 'approval_status')) {
                $table->string('approval_status', 32)->default('approved');
            }
            if (! Schema::hasColumn('eam_maintenance_work_orders', 'is_locked')) {
                $table->boolean('is_locked')->default(false);
            }
            if (! Schema::hasColumn('eam_maintenance_work_orders', 'locked_by_workflow_at')) {
                $table->timestamp('locked_by_workflow_at')->nullable();
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX eam_asset_assignments_metadata_gin ON eam_asset_assignments USING GIN (metadata)');
            DB::statement('CREATE INDEX eam_maintenance_activities_parts_gin ON eam_maintenance_activities USING GIN (spare_parts_consumed)');
            DB::statement("CREATE INDEX eam_fixed_assets_active_depreciable_partial_idx ON eam_fixed_assets (tenant_id, company_id, in_service_date) WHERE status IN ('active', 'revalued', 'impaired', 'commissioned') AND is_locked = false AND deleted_at IS NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS eam_fixed_assets_active_depreciable_partial_idx');
        }

        Schema::dropIfExists('eam_maintenance_activities');
        Schema::dropIfExists('eam_asset_assignments');
    }
};
