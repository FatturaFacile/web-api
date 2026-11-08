<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise extension of Human Resources and Payroll (additive only, safe for `migrate --force`).
 *
 * - Contract history: collective agreement and level as references to the payroll tables, the contract that superseded
 *   a row, and recurring salary components per contract (wage type code, amount, validity).
 * - Time and attendance: configurable overtime rules, monthly closing of the time records, the link of a clock event to
 *   the legacy time entry it was copied from (idempotent consolidation of /time-attendance).
 * - Leave: accrual policies per tenant and an accrual journal that makes every monthly accrual idempotent.
 * - Employee documents: stored file metadata, retention date, health-data flag and visibility to the employee.
 * - Payroll runs: the approval step between calculation and closing.
 *
 * No statutory rate or table is created here: rates stay tenant data entered through the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employment_contracts', function (Blueprint $table): void {
            $this->addColumn($table, 'hr_employment_contracts', 'collective_agreement_id', fn () => $table->uuid('collective_agreement_id')->nullable()->index());
            $this->addColumn($table, 'hr_employment_contracts', 'collective_agreement_level_id', fn () => $table->uuid('collective_agreement_level_id')->nullable());
            $this->addColumn($table, 'hr_employment_contracts', 'level_code', fn () => $table->string('level_code', 40)->nullable());
            $this->addColumn($table, 'hr_employment_contracts', 'superseded_by_contract_id', fn () => $table->uuid('superseded_by_contract_id')->nullable());
            $this->addColumn($table, 'hr_employment_contracts', 'notes', fn () => $table->text('notes')->nullable());
        });

        if (! Schema::hasTable('hr_contract_salary_components')) {
            Schema::create('hr_contract_salary_components', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('contract_id')->constrained('hr_employment_contracts')->cascadeOnDelete();
                $table->string('wage_type_code', 20);
                $table->string('description', 150);
                $table->decimal('amount', 18, 2);
                $table->string('frequency', 20)->default('monthly');
                $table->date('valid_from');
                $table->date('valid_to')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'contract_id']);
            });
        }

        if (! Schema::hasTable('hr_overtime_rules')) {
            Schema::create('hr_overtime_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('name', 150);
                $table->string('basis', 10);
                $table->unsignedInteger('threshold_minutes');
                $table->decimal('premium_percent', 6, 2)->default(0);
                $table->string('wage_type_code', 20)->nullable();
                $table->unsignedSmallInteger('priority')->default(0);
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('source_reference')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('hr_time_period_closings')) {
            Schema::create('hr_time_period_closings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->unsignedSmallInteger('year');
                $table->unsignedTinyInteger('month');
                $table->string('status', 20)->default('closed');
                $table->timestamp('closed_at')->nullable();
                $table->uuid('closed_by')->nullable();
                $table->timestamp('reopened_at')->nullable();
                $table->uuid('reopened_by')->nullable();
                $table->text('reopen_reason')->nullable();
                $table->unsignedInteger('reopen_count')->default(0);
                $table->jsonb('summary')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'year', 'month']);
            });
        }

        Schema::table('time_clock_events', function (Blueprint $table): void {
            $this->addColumn($table, 'time_clock_events', 'legacy_time_entry_id', fn () => $table->uuid('legacy_time_entry_id')->nullable());
        });

        if (! Schema::hasIndex('time_clock_events', 'time_clock_events_legacy_entry_unique')) {
            Schema::table('time_clock_events', function (Blueprint $table): void {
                $table->unique(['legacy_time_entry_id', 'event_type'], 'time_clock_events_legacy_entry_unique');
            });
        }

        if (! Schema::hasTable('hr_leave_accrual_policies')) {
            Schema::create('hr_leave_accrual_policies', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('leave_type_id')->constrained('hr_leave_types')->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('name', 150);
                $table->string('employment_type', 40)->nullable();
                $table->decimal('annual_entitlement_hours', 8, 2);
                $table->string('accrual_frequency', 20)->default('monthly');
                $table->boolean('prorate_by_fte')->default(true);
                $table->decimal('max_balance_hours', 8, 2)->nullable();
                $table->decimal('max_carryover_hours', 8, 2)->nullable();
                $table->date('valid_from');
                $table->date('valid_to')->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('source_reference')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('hr_leave_accrual_postings')) {
            Schema::create('hr_leave_accrual_postings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('leave_balance_id')->constrained('hr_leave_balances')->cascadeOnDelete();
                $table->uuid('accrual_policy_id')->nullable();
                $table->string('period', 7);
                $table->decimal('hours', 8, 2);
                $table->timestamps();

                $table->unique(['leave_balance_id', 'period']);
                $table->index(['tenant_id', 'period']);
            });
        }

        Schema::table('hr_employee_documents', function (Blueprint $table): void {
            $this->addColumn($table, 'hr_employee_documents', 'original_name', fn () => $table->string('original_name', 255)->nullable());
            $this->addColumn($table, 'hr_employee_documents', 'mime_type', fn () => $table->string('mime_type', 120)->nullable());
            $this->addColumn($table, 'hr_employee_documents', 'size_bytes', fn () => $table->unsignedBigInteger('size_bytes')->nullable());
            $this->addColumn($table, 'hr_employee_documents', 'contains_health_data', fn () => $table->boolean('contains_health_data')->default(false));
            $this->addColumn($table, 'hr_employee_documents', 'visible_to_employee', fn () => $table->boolean('visible_to_employee')->default(false));
            $this->addColumn($table, 'hr_employee_documents', 'retention_until', fn () => $table->date('retention_until')->nullable()->index());
            $this->addColumn($table, 'hr_employee_documents', 'uploaded_by', fn () => $table->uuid('uploaded_by')->nullable());
        });

        if (! Schema::hasIndex('hr_employee_documents', 'hr_employee_documents_tenant_employee_index')) {
            Schema::table('hr_employee_documents', function (Blueprint $table): void {
                $table->index(['tenant_id', 'employee_id'], 'hr_employee_documents_tenant_employee_index');
            });
        }

        Schema::table('payroll_runs', function (Blueprint $table): void {
            $this->addColumn($table, 'payroll_runs', 'approved_at', fn () => $table->timestamp('approved_at')->nullable());
            $this->addColumn($table, 'payroll_runs', 'approved_by', fn () => $table->uuid('approved_by')->nullable());
        });
    }

    public function down(): void
    {
        // Non-destructive enterprise migration policy: the added columns and tables hold personnel and pay history.
    }

    /**
     * Adds a column only when it is missing, so the migration can run again on partially migrated installations.
     */
    private function addColumn(Blueprint $table, string $tableName, string $column, Closure $definition): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            $definition();
        }
    }
};
