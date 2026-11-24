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
        // 1. Service Categories
        if (! Schema::hasTable('esm_service_categories')) {
            Schema::create('esm_service_categories', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 60)->index();
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->jsonb('translations')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX esm_service_categories_translations_gin ON esm_service_categories USING GIN (translations)');
            }
        }

        // 2. Business Hours Calendars
        if (! Schema::hasTable('esm_business_hours_calendars')) {
            Schema::create('esm_business_hours_calendars', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 60)->index();
                $table->string('name', 150);
                $table->string('timezone', 50)->default('UTC');
                $table->jsonb('weekly_schedule')->nullable(); // e.g. Mon-Fri 08:00-18:00
                $table->jsonb('holidays')->nullable();
                $table->boolean('is_active')->default(true);
                $table->jsonb('translations')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX esm_business_hours_calendars_translations_gin ON esm_business_hours_calendars USING GIN (translations)');
            }
        }

        // 3. Support Plans
        if (! Schema::hasTable('esm_support_plans')) {
            Schema::create('esm_support_plans', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 60)->index();
                $table->string('name', 150);
                $table->string('tier', 30)->default('standard'); // basic, standard, premium, mission_critical
                $table->decimal('response_time_hours', 6, 2)->default(8.00);
                $table->decimal('resolution_time_hours', 6, 2)->default(24.00);
                $table->decimal('price', 18, 2)->default(0.00);
                $table->string('currency', 3)->default('EUR');
                $table->string('billing_cycle', 30)->default('monthly'); // monthly, quarterly, annual
                $table->boolean('is_active')->default(true);
                $table->jsonb('translations')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX esm_support_plans_translations_gin ON esm_support_plans USING GIN (translations)');
            }
        }

        // 4. SLA Policies & Target Rules
        if (! Schema::hasTable('esm_sla_policies')) {
            Schema::create('esm_sla_policies', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('service_category_id')->nullable()->constrained('esm_service_categories')->nullOnDelete();
                $table->foreignUuid('support_plan_id')->nullable()->constrained('esm_support_plans')->nullOnDelete();
                $table->string('code', 60)->index();
                $table->string('name', 150);
                $table->string('priority', 20)->default('medium'); // low, medium, high, critical
                $table->unsignedInteger('response_time_minutes')->default(240); // 4 hours
                $table->unsignedInteger('resolution_time_minutes')->default(1440); // 24 hours
                $table->unsignedInteger('escalation_time_minutes')->default(120); // 2 hours
                $table->boolean('business_hours_only')->default(true);
                $table->boolean('is_active')->default(true);
                $table->jsonb('translations')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX esm_sla_policies_translations_gin ON esm_sla_policies USING GIN (translations)');
            }
        }

        // 5. Service Contracts
        if (! Schema::hasTable('esm_service_contracts')) {
            Schema::create('esm_service_contracts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('customer_id')->index(); // Contact/Customer in CRM/Sales
                $table->foreignUuid('support_plan_id')->nullable()->constrained('esm_support_plans')->nullOnDelete();
                $table->string('contract_number', 60)->index();
                $table->string('title', 180);
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->date('renewal_date')->nullable();
                $table->decimal('annual_value', 18, 2)->default(0.00);
                $table->string('currency', 3)->default('EUR');
                $table->string('status', 30)->default('active')->index(); // draft, active, suspended, expired, cancelled
                $table->boolean('auto_renew')->default(true);
                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->string('approval_status', 30)->default('approved');
                $table->boolean('is_locked')->default(false);
                $table->jsonb('terms')->nullable();
                $table->jsonb('entitlements')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'contract_number']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX esm_service_contracts_entitlements_gin ON esm_service_contracts USING GIN (entitlements)');
            }
        }

        // 6. Service Teams
        if (! Schema::hasTable('esm_service_teams')) {
            Schema::create('esm_service_teams', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 60)->index();
                $table->string('name', 150);
                $table->uuid('lead_user_id')->nullable()->index();
                $table->uuid('department_id')->nullable()->index();
                $table->boolean('is_active')->default(true);
                $table->jsonb('translations')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX esm_service_teams_translations_gin ON esm_service_teams USING GIN (translations)');
            }
        }

        // 7. Service Technicians
        if (! Schema::hasTable('esm_service_technicians')) {
            Schema::create('esm_service_technicians', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('user_id')->nullable()->index();
                $table->uuid('employee_id')->nullable()->index();
                $table->foreignUuid('team_id')->nullable()->constrained('esm_service_teams')->nullOnDelete();
                $table->string('technician_number', 60)->index();
                $table->string('first_name', 100);
                $table->string('last_name', 100);
                $table->string('email', 180);
                $table->string('phone', 50)->nullable();
                $table->string('skill_level', 30)->default('intermediate'); // apprentice, junior, intermediate, senior, lead
                $table->decimal('hourly_cost_rate', 18, 2)->default(0.00);
                $table->decimal('hourly_billable_rate', 18, 2)->default(0.00);
                $table->boolean('is_available')->default(true);
                $table->string('current_status', 30)->default('available'); // available, on_assignment, in_transit, on_leave, offline
                $table->jsonb('skills')->nullable();
                $table->jsonb('certifications')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'technician_number']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX esm_service_technicians_skills_gin ON esm_service_technicians USING GIN (skills)');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('esm_service_technicians');
        Schema::dropIfExists('esm_service_teams');
        Schema::dropIfExists('esm_service_contracts');
        Schema::dropIfExists('esm_sla_policies');
        Schema::dropIfExists('esm_support_plans');
        Schema::dropIfExists('esm_business_hours_calendars');
        Schema::dropIfExists('esm_service_categories');
    }
};
