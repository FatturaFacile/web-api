<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 18 HR - Recruiting & ATS (Mod 070) core schema: job openings, candidate applications,
 * stage history and interview scorecards.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Job Openings (published on the careers page or internal only)
        if (! Schema::hasTable('rec_job_openings')) {
            Schema::create('rec_job_openings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('reference', 40);
                $table->foreignUuid('position_id')->constrained('hr_positions')->restrictOnDelete();
                $table->foreignUuid('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
                $table->string('location_site', 150);
                $table->string('employment_type', 40)->default('full_time'); // full_time, part_time, contractor, apprentice, seasonal
                $table->unsignedInteger('openings_count')->default(1);
                $table->unsignedInteger('hires_count')->default(0);
                $table->decimal('salary_min', 18, 2)->nullable();
                $table->decimal('salary_max', 18, 2)->nullable();
                $table->string('salary_currency', 3)->nullable();
                $table->string('status', 30)->default('draft')->index(); // draft, published, internal_only, closed_filled, cancelled
                $table->jsonb('published_languages')->nullable();
                $table->jsonb('translations')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->date('closes_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->uuid('created_by')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'reference']);
                $table->index(['tenant_id', 'status']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX rec_job_openings_translations_gin ON rec_job_openings USING GIN (translations)');
            }
        }

        // 2. Candidate Applications (Kanban cards)
        if (! Schema::hasTable('rec_candidate_applications')) {
            Schema::create('rec_candidate_applications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('reference', 40);
                $table->foreignUuid('job_opening_id')->constrained('rec_job_openings')->cascadeOnDelete();
                $table->string('first_name', 100);
                $table->string('last_name', 100);
                $table->string('email', 180)->nullable()->index();
                $table->string('phone', 50)->nullable();
                $table->text('cover_letter')->nullable();
                $table->text('notes')->nullable();
                $table->string('locale', 10)->default('en');
                $table->string('source', 30)->default('careers_page'); // careers_page, manual, referral, job_board
                $table->string('stage', 40)->default('applied')->index();
                $table->timestamp('stage_entered_at')->nullable();
                $table->unsignedInteger('board_position')->default(0);
                $table->string('cv_storage_key', 255)->nullable();
                $table->string('cv_original_name', 255)->nullable();
                $table->string('cv_mime_type', 120)->nullable();
                $table->unsignedBigInteger('cv_size_bytes')->nullable();
                $table->string('cv_parse_status', 20)->default('not_provided'); // pending, parsed, partial, failed, not_provided
                $table->jsonb('extracted_profile')->nullable();
                $table->jsonb('skills')->nullable();
                $table->timestamp('consent_given_at')->nullable();
                $table->string('consent_text_version', 60)->nullable();
                $table->timestamp('privacy_consent_expires_at')->nullable()->index();
                $table->timestamp('consent_extended_at')->nullable();
                $table->unsignedSmallInteger('retention_months')->default(6);
                $table->string('rejection_reason_code', 40)->nullable();
                $table->text('rejection_note')->nullable();
                $table->foreignUuid('hired_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
                $table->timestamp('anonymized_at')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'reference']);
                $table->index(['tenant_id', 'job_opening_id', 'stage', 'board_position'], 'rec_candidate_applications_board_idx');
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX rec_candidate_applications_skills_gin ON rec_candidate_applications USING GIN (skills)');
            }
        }

        // 3. Stage History (audit trail of Kanban moves)
        if (! Schema::hasTable('rec_application_stage_history')) {
            Schema::create('rec_application_stage_history', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('application_id')->constrained('rec_candidate_applications')->cascadeOnDelete();
                $table->string('from_stage', 40)->nullable();
                $table->string('to_stage', 40);
                $table->uuid('actor_user_id')->nullable()->index();
                $table->text('note')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 4. Interview Scorecards (one per application, interviewer and stage)
        if (! Schema::hasTable('rec_interview_scorecards')) {
            Schema::create('rec_interview_scorecards', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('application_id')->constrained('rec_candidate_applications')->cascadeOnDelete();
                $table->uuid('interviewer_user_id')->index();
                $table->string('interviewer_name', 150)->nullable();
                $table->string('stage', 40);
                $table->jsonb('criteria_scores');
                $table->decimal('overall_score', 4, 2);
                $table->string('recommendation', 20); // strong_hire, hire, no_hire
                $table->text('notes')->nullable();
                $table->timestamp('interviewed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['application_id', 'interviewer_user_id', 'stage'], 'rec_interview_scorecards_unique_interviewer');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_interview_scorecards');
        Schema::dropIfExists('rec_application_stage_history');
        Schema::dropIfExists('rec_candidate_applications');
        Schema::dropIfExists('rec_job_openings');
    }
};
