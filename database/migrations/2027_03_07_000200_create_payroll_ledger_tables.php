<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The mapping of a tenant's payroll to its chart of accounts, and the state of each run towards the ledger: a run
     * closed before its mapping or period exists stays here until it can be posted.
     */
    public function up(): void
    {
        Schema::create('payroll_ledger_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->uuid('salary_expense_account_id')->nullable();
            $table->uuid('employer_cost_account_id')->nullable();
            $table->uuid('expense_allowance_account_id')->nullable();
            $table->uuid('employee_payable_account_id')->nullable();
            $table->uuid('social_payable_account_id')->nullable();
            $table->uuid('tax_payable_account_id')->nullable();
            $table->uuid('non_cash_benefit_account_id')->nullable();
            $table->uuid('rounding_account_id')->nullable();
            $table->jsonb('wage_type_accounts')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            foreach (['salary_expense', 'employer_cost', 'expense_allowance', 'employee_payable', 'social_payable', 'tax_payable', 'non_cash_benefit', 'rounding'] as $role) {
                $table->foreign("{$role}_account_id")->references('id')->on('chart_of_accounts')->nullOnDelete();
            }
        });

        Schema::create('payroll_ledger_postings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('payroll_run_id')->unique();
            $table->string('status', 30)->default('pending')->index();
            $table->date('run_date');
            $table->string('currency', 3);
            $table->jsonb('payload');
            $table->uuid('journal_entry_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_ledger_postings');
        Schema::dropIfExists('payroll_ledger_settings');
    }
};
