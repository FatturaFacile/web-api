<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academy / LMS (Mod 112): the shared learning catalogue (tracks, missions, contextual help: tenant_id null), and the
 * tenant data of the learners (progress, certificates, classroom cohorts, enrollments and assignments). Translated texts
 * are JSON maps keyed by locale so the same schema works on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academy_learning_tracks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('difficulty', 20);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('translations_json');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('academy_interactive_missions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('track_id')->constrained('academy_learning_tracks')->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('difficulty', 20);
            $table->unsignedInteger('sequence')->default(1);
            $table->unsignedInteger('xp_reward')->default(0);
            $table->string('badge_code', 80)->nullable();
            $table->json('verification_rule_json');
            $table->json('tour_steps_json')->nullable();
            $table->json('translations_json');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'code']);
            $table->index(['track_id', 'sequence']);
        });

        Schema::create('academy_contextual_help_articles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('route_pattern', 160);
            $table->string('locale', 5);
            $table->string('title', 200);
            $table->text('body_markdown');
            $table->json('field_tooltips_json')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'route_pattern', 'locale'], 'academy_help_route_locale_unique');
        });

        Schema::create('academy_user_progress', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreignUuid('track_id')->constrained('academy_learning_tracks')->cascadeOnDelete();
            $table->foreignUuid('mission_id')->constrained('academy_interactive_missions')->cascadeOnDelete();
            $table->string('status', 12)->default('started');
            $table->unsignedInteger('xp_awarded')->default(0);
            $table->string('badge_code', 80)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'user_id', 'mission_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('academy_certificates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreignUuid('track_id')->constrained('academy_learning_tracks')->cascadeOnDelete();
            $table->string('certificate_code', 40)->unique();
            $table->string('holder_name', 200);
            $table->unsignedInteger('total_xp')->default(0);
            $table->timestamp('issued_at');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'user_id', 'track_id']);
        });

        Schema::create('academy_classroom_cohorts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('teacher_id');
            $table->string('name', 160);
            $table->string('join_code', 12)->unique();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'teacher_id']);
        });

        Schema::create('academy_classroom_enrollments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('cohort_id')->constrained('academy_classroom_cohorts')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->timestamp('enrolled_at');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['cohort_id', 'user_id']);
            $table->index(['tenant_id', 'cohort_id']);
        });

        Schema::create('academy_classroom_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('cohort_id')->constrained('academy_classroom_cohorts')->cascadeOnDelete();
            $table->foreignUuid('track_id')->constrained('academy_learning_tracks')->cascadeOnDelete();
            $table->date('due_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['cohort_id', 'track_id']);
            $table->index(['tenant_id', 'cohort_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academy_classroom_assignments');
        Schema::dropIfExists('academy_classroom_enrollments');
        Schema::dropIfExists('academy_classroom_cohorts');
        Schema::dropIfExists('academy_certificates');
        Schema::dropIfExists('academy_user_progress');
        Schema::dropIfExists('academy_contextual_help_articles');
        Schema::dropIfExists('academy_interactive_missions');
        Schema::dropIfExists('academy_learning_tracks');
    }
};
