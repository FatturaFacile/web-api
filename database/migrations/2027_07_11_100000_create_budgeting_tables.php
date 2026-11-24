<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Planning and budgeting: budget versions per fiscal year (budget, forecast, rolling forecast), their lines by
     * account, cost centre, project and fiscal period, the drivers that generate lines, and the approval trail. New
     * tables only; the version lifecycle (a locked version is immutable) and the spread arithmetic are enforced by the
     * application, not by the engine. Accounts, cost centres and projects are referenced by id without foreign keys so
     * the module stays decoupled from the general ledger tables.
     */
    public function up(): void
    {
        if (! Schema::hasTable('budget_versions')) {
            Schema::create('budget_versions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 160);
                $table->text('description')->nullable();
                $table->unsignedSmallInteger('fiscal_year');
                $table->date('year_start');
                $table->string('version_type', 20)->default('budget');
                $table->string('status', 20)->default('draft');
                $table->string('currency', 3)->default('EUR');
                $table->uuid('base_version_id')->nullable()->index();
                $table->unsignedTinyInteger('reforecast_from_period')->nullable();
                $table->json('seasonal_weights')->nullable();
                $table->uuid('submitted_by')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->uuid('locked_by')->nullable();
                $table->timestamp('locked_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'fiscal_year']);
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('budget_drivers')) {
            Schema::create('budget_drivers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('version_id')->constrained('budget_versions')->cascadeOnDelete();
                $table->string('name', 160);
                $table->uuid('account_id')->index();
                $table->uuid('cost_center_id')->nullable()->index();
                $table->uuid('project_id')->nullable()->index();
                $table->decimal('quantity', 15, 3);
                $table->decimal('rate', 15, 4);
                $table->string('spread_method', 20)->default('even');
                $table->json('percentages')->nullable();
                $table->unsignedTinyInteger('start_period')->default(1);
                $table->unsignedTinyInteger('end_period')->default(12);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['version_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('budget_lines')) {
            Schema::create('budget_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('version_id')->constrained('budget_versions')->cascadeOnDelete();
                $table->uuid('account_id')->index();
                $table->uuid('cost_center_id')->nullable()->index();
                $table->uuid('project_id')->nullable()->index();
                $table->unsignedTinyInteger('period');
                $table->decimal('amount', 15, 2)->default(0);
                $table->string('source', 20)->default('manual');
                $table->uuid('driver_id')->nullable()->index();
                $table->string('notes', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['version_id', 'account_id', 'period'], 'budget_lines_lookup');
            });
        }

        if (! Schema::hasTable('budget_approvals')) {
            Schema::create('budget_approvals', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('version_id')->constrained('budget_versions')->cascadeOnDelete();
                $table->string('action', 20);
                $table->string('from_status', 20);
                $table->string('to_status', 20);
                $table->uuid('user_id')->nullable();
                $table->string('comment', 1000)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['version_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        foreach (['budget_approvals', 'budget_lines', 'budget_drivers', 'budget_versions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
