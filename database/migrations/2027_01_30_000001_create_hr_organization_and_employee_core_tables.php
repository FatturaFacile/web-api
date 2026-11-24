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
        // 1. Business Units
        if (! Schema::hasTable('hr_business_units')) {
            Schema::create('hr_business_units', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 60)->index();
                $table->string('name', 150);
                $table->uuid('head_employee_id')->nullable()->index();
                $table->boolean('is_active')->default(true);
                $table->jsonb('translations')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });
        }

        // 2. Cost Centers
        if (! Schema::hasTable('hr_cost_centers')) {
            Schema::create('hr_cost_centers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('business_unit_id')->nullable()->index();
                $table->string('code', 60)->index();
                $table->string('name', 150);
                $table->string('gl_expense_account_code', 40)->default('6100');
                $table->string('gl_accrual_account_code', 40)->default('2250');
                $table->decimal('annual_budget', 18, 2)->default(0.00);
                $table->boolean('is_active')->default(true);
                $table->jsonb('translations')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });
        }

        // 3. Departments (Hierarchical Org Structure)
        if (! Schema::hasTable('hr_departments')) {
            Schema::create('hr_departments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('parent_id')->nullable()->index();
                $table->uuid('business_unit_id')->nullable()->index();
                $table->uuid('cost_center_id')->nullable()->index();
                $table->uuid('manager_employee_id')->nullable()->index();
                $table->string('code', 60)->index();
                $table->string('name', 150);
                $table->boolean('is_active')->default(true);
                $table->jsonb('translations')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX hr_departments_translations_gin ON hr_departments USING GIN (translations)');
            }
        }

        // 4. Teams
        if (! Schema::hasTable('hr_teams')) {
            Schema::create('hr_teams', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
                $table->uuid('lead_employee_id')->nullable()->index();
                $table->string('code', 60)->index();
                $table->string('name', 150);
                $table->boolean('is_active')->default(true);
                $table->jsonb('translations')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });
        }

        // 5. Job Grades & Compensation Bands
        if (! Schema::hasTable('hr_job_grades')) {
            Schema::create('hr_job_grades', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 60)->index();
                $table->string('name', 150);
                $table->unsignedInteger('level_rank')->default(1);
                $table->decimal('min_salary', 18, 2)->default(0.00);
                $table->decimal('midpoint_salary', 18, 2)->default(0.00);
                $table->decimal('max_salary', 18, 2)->default(0.00);
                $table->string('currency', 3)->default('EUR');
                $table->boolean('is_active')->default(true);
                $table->jsonb('translations')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX hr_job_grades_translations_gin ON hr_job_grades USING GIN (translations)');
            }
        }

        // 6. Positions & Succession Planning
        if (! Schema::hasTable('hr_positions')) {
            Schema::create('hr_positions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
                $table->foreignUuid('job_grade_id')->nullable()->constrained('hr_job_grades')->nullOnDelete();
                $table->uuid('reports_to_position_id')->nullable()->index();
                $table->string('code', 60)->index();
                $table->string('title', 150);
                $table->unsignedInteger('headcount_budget')->default(1);
                $table->boolean('is_critical_role')->default(false);
                $table->uuid('successor_employee_id')->nullable()->index();
                $table->string('readiness_level', 30)->nullable(); // ready_now, ready_1_2_years, developing
                $table->boolean('is_active')->default(true);
                $table->jsonb('translations')->nullable();
                $table->jsonb('competencies_required')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX hr_positions_translations_gin ON hr_positions USING GIN (translations)');
            }
        }

        // 7. Employees Master Aggregate Root
        if (! Schema::hasTable('hr_employees')) {
            Schema::create('hr_employees', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('user_id')->nullable()->index();
                $table->string('employee_number', 60)->index();
                $table->string('first_name', 100);
                $table->string('last_name', 100);
                $table->string('full_name', 210);
                $table->string('work_email', 180)->index();
                $table->string('personal_email', 180)->nullable();
                $table->string('phone', 50)->nullable();
                $table->date('birth_date')->nullable();
                $table->string('gender', 20)->nullable();
                $table->string('nationality', 10)->default('IT');
                $table->string('tax_code', 100)->nullable()->index();
                $table->string('social_security_number', 100)->nullable();

                $table->foreignUuid('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
                $table->foreignUuid('team_id')->nullable()->constrained('hr_teams')->nullOnDelete();
                $table->foreignUuid('business_unit_id')->nullable()->constrained('hr_business_units')->nullOnDelete();
                $table->foreignUuid('cost_center_id')->nullable()->constrained('hr_cost_centers')->nullOnDelete();
                $table->foreignUuid('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
                $table->foreignUuid('job_grade_id')->nullable()->constrained('hr_job_grades')->nullOnDelete();
                $table->uuid('manager_id')->nullable()->index();

                // Lifecycle statuses: candidate, onboarding, probation, active, promoted, transferred, on_leave, terminated, offboarding, retired, rehired
                $table->string('employment_status', 40)->default('onboarding')->index();
                $table->string('employment_type', 40)->default('full_time'); // full_time, part_time, contractor, apprentice, seasonal

                $table->date('hire_date');
                $table->date('probation_end_date')->nullable();
                $table->date('confirmation_date')->nullable();
                $table->date('termination_date')->nullable();
                $table->text('termination_reason')->nullable();
                $table->date('rehire_date')->nullable();

                $table->decimal('base_annual_salary', 18, 2)->default(0.00);
                $table->decimal('hourly_cost_rate', 18, 2)->default(0.00);
                $table->string('currency', 3)->default('EUR');
                $table->decimal('fte_ratio', 5, 2)->default(1.00);
                $table->string('work_schedule_code', 50)->default('STD-40H');

                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->string('approval_status', 30)->default('approved');
                $table->boolean('is_locked')->default(false);

                $table->jsonb('emergency_contact')->nullable();
                $table->jsonb('equipment_assigned')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'employee_number']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE hr_employees ADD CONSTRAINT chk_hr_employees_salary_non_neg CHECK (base_annual_salary >= 0 AND hourly_cost_rate >= 0)');
                DB::statement('CREATE INDEX hr_employees_equipment_gin ON hr_employees USING GIN (equipment_assigned)');
                DB::statement('CREATE INDEX hr_employees_metadata_gin ON hr_employees USING GIN (metadata)');
                DB::statement("CREATE INDEX hr_employees_active_partial_idx ON hr_employees (tenant_id, department_id, manager_id) WHERE employment_status IN ('onboarding', 'probation', 'active', 'promoted', 'transferred', 'rehired') AND deleted_at IS NULL");
            }
        }

        // 8. Employment Contracts
        if (! Schema::hasTable('hr_employment_contracts')) {
            Schema::create('hr_employment_contracts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->string('contract_number', 60)->index();
                $table->string('contract_type', 40)->default('permanent'); // permanent, fixed_term, apprenticeship, contractor, internship
                $table->string('collective_agreement', 120)->nullable(); // CCNL Metalmeccanico, CCNL Commercio, GAV Construction
                $table->string('job_title', 150);
                $table->uuid('job_grade_id')->nullable()->index();
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->date('probation_end_date')->nullable();
                $table->decimal('weekly_hours', 6, 2)->default(40.00);
                $table->decimal('fte_percentage', 6, 2)->default(100.00);
                $table->decimal('base_annual_salary', 18, 2)->default(0.00);
                $table->decimal('hourly_rate', 18, 2)->default(0.00);
                $table->decimal('loaded_hourly_cost', 18, 2)->default(0.00);
                $table->string('currency', 3)->default('EUR');
                $table->string('status', 30)->default('active')->index(); // draft, active, amended, expired, terminated
                $table->boolean('is_active')->default(true);
                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->boolean('is_locked')->default(false);
                $table->jsonb('terms')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'contract_number']);
            });
        }

        // 9. Employee Lifecycle History Events (Promotions, Transfers, Hiring, Termination, Rehire)
        if (! Schema::hasTable('hr_employee_lifecycle_events')) {
            Schema::create('hr_employee_lifecycle_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->string('event_type', 40)->index(); // hiring, onboarding, probation_passed, promotion, transfer, termination, offboarding, rehire
                $table->date('effective_date');
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40);
                $table->uuid('from_department_id')->nullable();
                $table->uuid('to_department_id')->nullable();
                $table->uuid('from_position_id')->nullable();
                $table->uuid('to_position_id')->nullable();
                $table->uuid('from_job_grade_id')->nullable();
                $table->uuid('to_job_grade_id')->nullable();
                $table->decimal('from_salary', 18, 2)->nullable();
                $table->decimal('to_salary', 18, 2)->nullable();
                $table->text('reason')->nullable();
                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->string('approval_status', 30)->default('approved');
                $table->boolean('is_locked')->default(false);
                $table->uuid('initiated_by_user_id')->nullable();
                $table->uuid('approved_by_user_id')->nullable();
                $table->jsonb('snapshot')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 10. Employee Documents
        if (! Schema::hasTable('hr_employee_documents')) {
            Schema::create('hr_employee_documents', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->string('document_type', 50); // contract, id_card, work_permit, medical_fitness, nda, payslip
                $table->string('title', 200);
                $table->string('file_path', 500);
                $table->date('issue_date')->nullable();
                $table->date('expiry_date')->nullable()->index();
                $table->boolean('is_confidential')->default(true);
                $table->string('status', 30)->default('valid');
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_documents');
        Schema::dropIfExists('hr_employee_lifecycle_events');
        Schema::dropIfExists('hr_employment_contracts');
        Schema::dropIfExists('hr_employees');
        Schema::dropIfExists('hr_positions');
        Schema::dropIfExists('hr_job_grades');
        Schema::dropIfExists('hr_teams');
        Schema::dropIfExists('hr_departments');
        Schema::dropIfExists('hr_cost_centers');
        Schema::dropIfExists('hr_business_units');
    }
};
