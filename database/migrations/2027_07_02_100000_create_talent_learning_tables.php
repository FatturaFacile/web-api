<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Talent and learning: succession planning, compensation planning cycles, the learning catalogue with enrolments and
     * certificates, and engagement surveys. New tables only. Employees and positions are plain uuids that point at the
     * human resources tables: they are read through the HR directory contract, never joined. Business rules (readiness
     * levels, budget limits, anonymity threshold) are enforced by the application, not by the engine.
     */
    public function up(): void
    {
        $this->successionTables();
        $this->compensationTables();
        $this->learningTables();
        $this->surveyTables();
    }

    private function base(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
    }

    private function successionTables(): void
    {
        if (! Schema::hasTable('tl_succession_positions')) {
            Schema::create('tl_succession_positions', function (Blueprint $table): void {
                $this->base($table);
                $table->uuid('position_id')->nullable()->index();
                $table->string('title', 150);
                $table->uuid('incumbent_employee_id')->nullable()->index();
                $table->string('criticality', 20)->default('high');
                $table->string('vacancy_risk', 20)->default('medium');
                $table->string('status', 20)->default('active');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('tl_succession_candidates')) {
            Schema::create('tl_succession_candidates', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('succession_position_id')->constrained('tl_succession_positions')->cascadeOnDelete();
                $table->uuid('employee_id')->index();
                $table->string('readiness', 30)->default('ready_1_2_years');
                $table->string('risk_of_loss', 20)->default('medium');
                $table->string('potential', 20)->default('medium');
                $table->unsignedSmallInteger('ranking')->default(1);
                $table->text('development_plan')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'employee_id']);
            });
        }
    }

    private function compensationTables(): void
    {
        if (! Schema::hasTable('tl_comp_cycles')) {
            Schema::create('tl_comp_cycles', function (Blueprint $table): void {
                $this->base($table);
                $table->string('name', 150);
                $table->unsignedSmallInteger('fiscal_year');
                $table->string('currency', 3)->default('EUR');
                $table->date('starts_on');
                $table->date('ends_on');
                $table->date('effective_on');
                $table->string('status', 20)->default('draft');
                $table->timestamp('closed_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('tl_comp_budget_pools')) {
            Schema::create('tl_comp_budget_pools', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('cycle_id')->constrained('tl_comp_cycles')->cascadeOnDelete();
                $table->string('name', 150);
                $table->uuid('department_id')->nullable()->index();
                $table->decimal('budget_amount', 14, 2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('tl_comp_guidelines')) {
            Schema::create('tl_comp_guidelines', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('cycle_id')->constrained('tl_comp_cycles')->cascadeOnDelete();
                $table->unsignedTinyInteger('performance_rating');
                $table->decimal('min_percent', 5, 2)->default(0);
                $table->decimal('max_percent', 5, 2)->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('tl_comp_proposals')) {
            Schema::create('tl_comp_proposals', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('cycle_id')->constrained('tl_comp_cycles')->cascadeOnDelete();
                $table->foreignUuid('budget_pool_id')->constrained('tl_comp_budget_pools')->cascadeOnDelete();
                $table->uuid('employee_id')->index();
                $table->unsignedTinyInteger('performance_rating');
                $table->decimal('current_salary', 14, 2);
                $table->decimal('increase_percent', 5, 2);
                $table->decimal('increase_amount', 14, 2);
                $table->decimal('new_salary', 14, 2);
                $table->boolean('is_exception')->default(false);
                $table->text('justification')->nullable();
                $table->string('status', 20)->default('draft');
                $table->timestamp('submitted_at')->nullable();
                $table->uuid('decided_by_user_id')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_note')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'cycle_id', 'status']);
            });
        }
    }

    private function learningTables(): void
    {
        if (! Schema::hasTable('tl_courses')) {
            Schema::create('tl_courses', function (Blueprint $table): void {
                $this->base($table);
                $table->string('code', 40);
                $table->string('title', 200);
                $table->text('description')->nullable();
                $table->string('delivery', 20)->default('online');
                $table->decimal('duration_hours', 6, 1)->default(0);
                $table->boolean('is_mandatory')->default(false);
                $table->unsignedSmallInteger('validity_months')->nullable();
                $table->unsignedTinyInteger('pass_score')->nullable();
                $table->string('status', 20)->default('draft');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('tl_learning_paths')) {
            Schema::create('tl_learning_paths', function (Blueprint $table): void {
                $this->base($table);
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->string('status', 20)->default('draft');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('tl_learning_path_courses')) {
            Schema::create('tl_learning_path_courses', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('learning_path_id')->constrained('tl_learning_paths')->cascadeOnDelete();
                $table->foreignUuid('course_id')->constrained('tl_courses')->cascadeOnDelete();
                $table->unsignedSmallInteger('position')->default(1);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('tl_enrolments')) {
            Schema::create('tl_enrolments', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('course_id')->constrained('tl_courses')->cascadeOnDelete();
                $table->uuid('employee_id')->index();
                $table->uuid('learning_path_id')->nullable()->index();
                $table->string('status', 20)->default('enrolled');
                $table->unsignedTinyInteger('progress_percent')->default(0);
                $table->date('due_on')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->decimal('score', 5, 2)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'course_id', 'status']);
            });
        }

        if (! Schema::hasTable('tl_certificates')) {
            Schema::create('tl_certificates', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('enrolment_id')->constrained('tl_enrolments')->cascadeOnDelete();
                $table->foreignUuid('course_id')->constrained('tl_courses')->cascadeOnDelete();
                $table->uuid('employee_id')->index();
                $table->string('certificate_number', 40);
                $table->date('issued_on');
                $table->date('expires_on')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'certificate_number']);
            });
        }
    }

    private function surveyTables(): void
    {
        if (! Schema::hasTable('tl_surveys')) {
            Schema::create('tl_surveys', function (Blueprint $table): void {
                $this->base($table);
                $table->string('title', 200);
                $table->text('description')->nullable();
                $table->string('status', 20)->default('draft');
                $table->boolean('is_anonymous')->default(true);
                $table->unsignedTinyInteger('min_group_size')->default(5);
                $table->date('opens_on')->nullable();
                $table->date('closes_on')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('tl_survey_questions')) {
            Schema::create('tl_survey_questions', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('survey_id')->constrained('tl_surveys')->cascadeOnDelete();
                $table->unsignedSmallInteger('position')->default(1);
                $table->text('prompt');
                $table->string('question_type', 20)->default('rating');
                $table->boolean('is_required')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Who has answered. It never points at a response: with an anonymous survey nobody can tell which answers are whose.
        if (! Schema::hasTable('tl_survey_participants')) {
            Schema::create('tl_survey_participants', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('survey_id')->constrained('tl_surveys')->cascadeOnDelete();
                $table->uuid('employee_id');
                $table->date('responded_on');
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['survey_id', 'employee_id']);
            });
        }

        // No timestamps and no audit trail on purpose: a response carries the submission date only, and the respondent
        // only when the survey is not anonymous.
        if (! Schema::hasTable('tl_survey_responses')) {
            Schema::create('tl_survey_responses', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('survey_id')->constrained('tl_surveys')->cascadeOnDelete();
                $table->uuid('respondent_employee_id')->nullable()->index();
                $table->string('segment', 150)->nullable();
                $table->date('submitted_on');
            });
        }

        if (! Schema::hasTable('tl_survey_answers')) {
            Schema::create('tl_survey_answers', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('response_id')->constrained('tl_survey_responses')->cascadeOnDelete();
                $table->foreignUuid('question_id')->constrained('tl_survey_questions')->cascadeOnDelete();
                $table->unsignedTinyInteger('numeric_value')->nullable();
                $table->text('text_value')->nullable();
                $table->date('submitted_on');
            });
        }
    }
};
