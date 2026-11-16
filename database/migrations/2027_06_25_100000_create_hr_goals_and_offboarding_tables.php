<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_goal_cycles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('draft'); // draft, active, closed
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hr_goals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('goal_cycle_id')->constrained('hr_goal_cycles')->cascadeOnDelete();
            $table->uuid('parent_goal_id')->nullable();
            $table->string('level', 20); // company, team, employee
            $table->foreignUuid('employee_id')->nullable()->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignUuid('team_id')->nullable()->constrained('hr_teams')->cascadeOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->decimal('weight', 6, 2)->default(1.00);
            $table->date('due_on')->nullable();
            $table->string('status', 20)->default('draft'); // draft, active, completed, cancelled
            $table->decimal('progress', 5, 2)->default(0.00);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'goal_cycle_id']);
            $table->index(['tenant_id', 'employee_id']);
        });
        // The self-reference needs the primary key of the table, so it is added once the table exists.
        Schema::table('hr_goals', function (Blueprint $table): void {
            $table->foreign('parent_goal_id')->references('id')->on('hr_goals')->nullOnDelete();
        });

        Schema::create('hr_goal_key_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('goal_id')->constrained('hr_goals')->cascadeOnDelete();
            $table->string('metric', 180);
            $table->string('unit', 30)->nullable();
            $table->decimal('start_value', 18, 4)->default(0);
            $table->decimal('target_value', 18, 4);
            $table->decimal('current_value', 18, 4)->default(0);
            $table->decimal('progress', 5, 2)->default(0.00);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'goal_id']);
        });

        Schema::create('hr_goal_check_ins', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('goal_id')->constrained('hr_goals')->cascadeOnDelete();
            $table->foreignUuid('key_result_id')->nullable()->constrained('hr_goal_key_results')->nullOnDelete();
            $table->decimal('value', 18, 4)->nullable();
            $table->decimal('progress', 5, 2)->default(0.00);
            $table->text('comment')->nullable();
            $table->uuid('checked_in_by')->nullable();
            $table->timestamp('checked_in_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'goal_id']);
        });

        Schema::table('hr_onboarding_templates', function (Blueprint $table): void {
            $table->string('kind', 20)->default('onboarding')->after('tenant_id'); // onboarding, offboarding
        });

        Schema::table('hr_onboarding_checklists', function (Blueprint $table): void {
            $table->string('kind', 20)->default('onboarding')->after('tenant_id');
            $table->date('reference_date')->nullable()->after('onboarding_template_id');
        });

        Schema::table('hr_onboarding_tasks', function (Blueprint $table): void {
            $table->string('code', 40)->nullable()->after('title'); // offboarding: asset_return, access_revocation, exit_interview, final_settlement, custom
        });

        Schema::create('hr_exit_interviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignUuid('onboarding_checklist_id')->nullable()->constrained('hr_onboarding_checklists')->nullOnDelete();
            $table->uuid('interviewer_employee_id')->nullable();
            $table->date('conducted_on');
            $table->string('reason_code', 40);
            $table->boolean('would_recommend')->nullable();
            $table->boolean('eligible_for_rehire')->nullable();
            $table->unsignedSmallInteger('satisfaction_rating')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'reason_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_exit_interviews');

        Schema::table('hr_onboarding_tasks', function (Blueprint $table): void {
            $table->dropColumn('code');
        });
        Schema::table('hr_onboarding_checklists', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'reference_date']);
        });
        Schema::table('hr_onboarding_templates', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });

        Schema::dropIfExists('hr_goal_check_ins');
        Schema::dropIfExists('hr_goal_key_results');
        Schema::dropIfExists('hr_goals');
        Schema::dropIfExists('hr_goal_cycles');
    }
};
