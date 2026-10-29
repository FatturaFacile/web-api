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
        // 1. Holiday Calendars & Working Calendars
        if (! Schema::hasTable('hr_holiday_calendars')) {
            Schema::create('hr_holiday_calendars', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 60)->index();
                $table->string('name', 150);
                $table->string('country_code', 5)->default('IT');
                $table->string('region_code', 30)->nullable();
                $table->unsignedSmallInteger('year')->index();
                $table->decimal('daily_standard_hours', 5, 2)->default(8.00);
                $table->jsonb('working_days')->nullable(); // e.g., ["MON","TUE","WED","THU","FRI"]
                $table->jsonb('holidays')->nullable(); // list of holiday dates & names
                $table->boolean('is_active')->default(true);
                $table->jsonb('translations')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code', 'year']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX hr_holiday_calendars_holidays_gin ON hr_holiday_calendars USING GIN (holidays)');
            }
        }

        // 2. Leave Types
        if (! Schema::hasTable('hr_leave_types')) {
            Schema::create('hr_leave_types', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 60)->index();
                $table->string('name', 150);
                $table->string('category', 40)->default('vacation'); // vacation, sick, maternity, paternity, unpaid, rol, study, bereavement
                $table->boolean('is_paid')->default(true);
                $table->boolean('requires_approval')->default(true);
                $table->boolean('requires_attachment')->default(false);
                $table->decimal('annual_entitlement_hours', 8, 2)->default(160.00);
                $table->string('accrual_frequency', 30)->default('monthly'); // monthly, yearly, none
                $table->decimal('max_carryover_hours', 8, 2)->default(80.00);
                $table->boolean('is_active')->default(true);
                $table->jsonb('translations')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX hr_leave_types_translations_gin ON hr_leave_types USING GIN (translations)');
            }
        }

        // 3. Leave Balances & Accruals
        if (! Schema::hasTable('hr_leave_balances')) {
            Schema::create('hr_leave_balances', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignUuid('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
                $table->unsignedSmallInteger('fiscal_year')->index();
                $table->decimal('entitlement_hours', 8, 2)->default(0.00);
                $table->decimal('carryover_hours', 8, 2)->default(0.00);
                $table->decimal('accrued_hours', 8, 2)->default(0.00);
                $table->decimal('used_hours', 8, 2)->default(0.00);
                $table->decimal('pending_hours', 8, 2)->default(0.00);
                $table->decimal('available_hours', 8, 2)->default(0.00);
                $table->timestamp('last_accrued_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'employee_id', 'leave_type_id', 'fiscal_year']);
            });
        }

        // 4. Leave Requests
        if (! Schema::hasTable('hr_leave_requests')) {
            Schema::create('hr_leave_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignUuid('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
                $table->string('request_number', 60)->index();
                $table->date('start_date')->index();
                $table->date('end_date')->index();
                $table->decimal('total_hours', 8, 2);
                $table->decimal('total_days', 6, 2)->default(1.00);
                $table->string('status', 30)->default('pending_approval')->index(); // draft, pending_approval, approved, rejected, cancelled
                $table->text('reason')->nullable();
                $table->string('medical_certificate_ref', 120)->nullable();
                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->boolean('is_locked')->default(false);
                $table->uuid('approved_by_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'request_number']);
            });
        }

        // 5. Attendance Records & Overtime Tracking
        if (! Schema::hasTable('hr_attendance_records')) {
            Schema::create('hr_attendance_records', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->date('work_date')->index();
                $table->timestamp('clock_in_at')->nullable();
                $table->timestamp('clock_out_at')->nullable();
                $table->unsignedInteger('break_minutes')->default(0);
                $table->decimal('regular_hours', 6, 2)->default(8.00);
                $table->decimal('overtime_hours', 6, 2)->default(0.00);
                $table->decimal('night_hours', 6, 2)->default(0.00);
                $table->decimal('holiday_hours', 6, 2)->default(0.00);
                $table->string('status', 30)->default('present')->index(); // present, remote, on_leave, absent, holiday
                $table->string('source', 30)->default('web_portal');
                $table->uuid('project_id')->nullable()->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'employee_id', 'work_date']);
            });
        }

        // 6. Timesheets & Project Labor Allocation
        if (! Schema::hasTable('hr_timesheets')) {
            Schema::create('hr_timesheets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->uuid('project_id')->nullable()->index();
                $table->uuid('task_id')->nullable()->index();
                $table->uuid('cost_center_id')->nullable()->index();
                $table->string('timesheet_number', 60)->index();
                $table->date('work_date')->index();
                $table->decimal('regular_hours', 6, 2)->default(0.00);
                $table->decimal('overtime_hours', 6, 2)->default(0.00);
                $table->decimal('total_hours', 6, 2)->default(0.00);
                $table->decimal('billable_hours', 6, 2)->default(0.00);
                $table->decimal('hourly_cost_rate', 18, 2)->default(0.00);
                $table->decimal('total_labor_cost', 18, 2)->default(0.00);
                $table->string('status', 30)->default('submitted')->index(); // draft, submitted, approved, posted_to_project
                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->boolean('is_locked')->default(false);
                $table->uuid('approved_by_user_id')->nullable();
                $table->text('description')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'timesheet_number']);
            });
        }

        // 7. Skills Catalog & Employee Skills Matrix
        if (! Schema::hasTable('hr_skills')) {
            Schema::create('hr_skills', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 60)->index();
                $table->string('name', 150);
                $table->string('category', 60)->default('technical'); // technical, leadership, compliance, language, operational
                $table->boolean('is_active')->default(true);
                $table->jsonb('translations')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX hr_skills_translations_gin ON hr_skills USING GIN (translations)');
            }
        }

        if (! Schema::hasTable('hr_employee_skills')) {
            Schema::create('hr_employee_skills', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignUuid('skill_id')->constrained('hr_skills')->cascadeOnDelete();
                $table->unsignedTinyInteger('proficiency_level')->default(3); // 1=Novice .. 5=Expert
                $table->uuid('verified_by_user_id')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'employee_id', 'skill_id']);
            });
        }

        // 8. Training Courses & Employee Training Records
        if (! Schema::hasTable('hr_training_courses')) {
            Schema::create('hr_training_courses', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('course_code', 60)->index();
                $table->string('title', 200);
                $table->string('category', 60)->default('safety'); // safety, technical, leadership, compliance, onboarding
                $table->string('provider', 150)->default('Internal Academy');
                $table->decimal('duration_hours', 6, 2)->default(8.00);
                $table->decimal('cost_per_participant', 18, 2)->default(0.00);
                $table->string('currency', 3)->default('EUR');
                $table->unsignedInteger('validity_months')->nullable();
                $table->boolean('is_mandatory_compliance')->default(false);
                $table->boolean('is_active')->default(true);
                $table->jsonb('translations')->nullable();
                $table->jsonb('syllabus')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'course_code']);
            });
        }

        if (! Schema::hasTable('hr_training_records')) {
            Schema::create('hr_training_records', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignUuid('course_id')->constrained('hr_training_courses')->restrictOnDelete();
                $table->string('enrollment_number', 60)->index();
                $table->string('status', 30)->default('assigned')->index(); // assigned, pending_approval, in_progress, completed, expired, cancelled
                $table->date('assigned_date');
                $table->date('scheduled_date')->nullable();
                $table->date('completed_date')->nullable();
                $table->decimal('score', 5, 2)->nullable();
                $table->decimal('cost_amount', 18, 2)->default(0.00);
                $table->string('currency', 3)->default('EUR');
                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->boolean('is_locked')->default(false);
                $table->boolean('certificate_issued')->default(false);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'enrollment_number']);
            });
        }

        // 9. Certifications & Expiration Tracking
        if (! Schema::hasTable('hr_certifications')) {
            Schema::create('hr_certifications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('employee_id')->nullable()->constrained('hr_employees')->cascadeOnDelete();
                $table->uuid('course_id')->nullable()->index();
                $table->string('code', 60)->index();
                $table->string('name', 200);
                $table->string('certificate_number', 100)->nullable()->index();
                $table->string('issuing_authority', 150)->default('National Safety Board');
                $table->date('issue_date')->nullable();
                $table->date('expiry_date')->nullable()->index();
                $table->string('status', 30)->default('active')->index(); // catalog, active, expiring_soon, expired, revoked
                $table->boolean('is_mandatory')->default(false);
                $table->timestamp('renewal_alert_sent_at')->nullable();
                $table->jsonb('translations')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 10. Performance Reviews, Goals, Competencies & Succession
        if (! Schema::hasTable('hr_performance_reviews')) {
            Schema::create('hr_performance_reviews', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->uuid('reviewer_employee_id')->nullable()->index();
                $table->string('review_number', 60)->index();
                $table->string('review_period', 30)->index(); // e.g., 2026-ANNUAL, 2026-H1
                $table->string('review_type', 40)->default('annual'); // annual, mid_year, probation, promotion_readiness
                $table->date('review_date');
                $table->decimal('overall_rating', 4, 2)->default(3.00); // 1.00 - 5.00
                $table->decimal('goals_score', 4, 2)->default(3.00);
                $table->decimal('competencies_score', 4, 2)->default(3.00);
                $table->string('status', 30)->default('draft')->index(); // draft, submitted, pending_approval, completed, acknowledged
                $table->boolean('promotion_recommended')->default(false);
                $table->uuid('recommended_job_grade_id')->nullable();
                $table->decimal('recommended_salary_increase_percent', 5, 2)->default(0.00);
                $table->text('career_path_notes')->nullable();
                $table->string('successor_readiness', 30)->nullable(); // ready_now, ready_1_2_years, not_ready
                $table->jsonb('goals')->nullable();
                $table->jsonb('competencies')->nullable();
                $table->text('strengths')->nullable();
                $table->text('improvement_areas')->nullable();
                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->boolean('is_locked')->default(false);
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'review_number']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX hr_performance_reviews_goals_gin ON hr_performance_reviews USING GIN (goals)');
                DB::statement('CREATE INDEX hr_performance_reviews_competencies_gin ON hr_performance_reviews USING GIN (competencies)');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_performance_reviews');
        Schema::dropIfExists('hr_certifications');
        Schema::dropIfExists('hr_training_records');
        Schema::dropIfExists('hr_training_courses');
        Schema::dropIfExists('hr_employee_skills');
        Schema::dropIfExists('hr_skills');
        Schema::dropIfExists('hr_timesheets');
        Schema::dropIfExists('hr_attendance_records');
        Schema::dropIfExists('hr_leave_requests');
        Schema::dropIfExists('hr_leave_balances');
        Schema::dropIfExists('hr_leave_types');
        Schema::dropIfExists('hr_holiday_calendars');
    }
};
