<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * General Ledger controlling: default posting accounts and journal approval settings per tenant, four-eyes approval
 * and automatic reversal of journals, recurring journals, accruals and deferrals (ratei e risconti), the period close
 * checklist and the revaluation of open foreign-currency receivables and payables. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gl_default_posting_accounts')) {
            Schema::create('gl_default_posting_accounts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('role', 40);
                $table->uuid('account_id');
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('account_id')->references('id')->on('chart_of_accounts')->restrictOnDelete();
                $table->unique(['tenant_id', 'role'], 'uq_gl_default_posting_account_role');
            });
        }

        if (! Schema::hasTable('gl_ledger_settings')) {
            Schema::create('gl_ledger_settings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->unique();
                $table->boolean('manual_journal_approval_required')->default(false);
                $table->decimal('manual_journal_approval_threshold', 15, 4)->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        Schema::table('journal_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('journal_entries', 'auto_reverse_on')) {
                $table->date('auto_reverse_on')->nullable()->index();
            }
            if (! Schema::hasColumn('journal_entries', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
                $table->uuid('submitted_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->uuid('approved_by')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->uuid('rejected_by')->nullable();
                $table->string('rejection_reason', 500)->nullable();
            }
            if (! Schema::hasColumn('journal_entries', 'recurring_journal_id')) {
                $table->uuid('recurring_journal_id')->nullable()->index();
            }
        });

        if (! Schema::hasTable('gl_recurring_journals')) {
            Schema::create('gl_recurring_journals', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('name', 190);
                $table->string('description', 500);
                $table->string('frequency', 20);
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->date('next_run_date')->index();
                $table->date('last_run_date')->nullable();
                $table->boolean('reverse_next_day')->default(false);
                $table->string('status', 20)->default('active');
                $table->jsonb('lines');
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('gl_recurring_journal_runs')) {
            Schema::create('gl_recurring_journal_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('recurring_journal_id');
                $table->date('run_date');
                $table->uuid('journal_entry_id');
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('recurring_journal_id')->references('id')->on('gl_recurring_journals')->cascadeOnDelete();
                $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->restrictOnDelete();
                $table->unique(['recurring_journal_id', 'run_date'], 'uq_gl_recurring_run_date');
            });
        }

        if (! Schema::hasTable('gl_accruals')) {
            Schema::create('gl_accruals', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('accrual_type', 30);
                $table->string('description', 500);
                $table->decimal('total_amount', 15, 4);
                $table->date('coverage_start');
                $table->date('coverage_end');
                $table->date('cutoff_date');
                $table->unsignedInteger('coverage_days');
                $table->unsignedInteger('accrued_days');
                $table->decimal('amount', 15, 4);
                $table->uuid('income_statement_account_id');
                $table->uuid('balance_sheet_account_id');
                $table->uuid('cost_center_id')->nullable();
                $table->uuid('journal_entry_id')->nullable()->index();
                $table->date('reversal_date');
                $table->string('status', 20)->default('posted');
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('income_statement_account_id')->references('id')->on('chart_of_accounts')->restrictOnDelete();
                $table->foreign('balance_sheet_account_id')->references('id')->on('chart_of_accounts')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('gl_period_close_tasks')) {
            Schema::create('gl_period_close_tasks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('fiscal_period_id')->index();
                $table->string('title', 190);
                $table->string('description', 1000)->nullable();
                $table->boolean('is_blocking')->default(true);
                $table->string('status', 20)->default('open');
                $table->uuid('created_by')->nullable();
                $table->uuid('completed_by')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('completion_note', 1000)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('fiscal_period_id')->references('id')->on('fiscal_periods')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('gl_fx_revaluations')) {
            Schema::create('gl_fx_revaluations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->date('revaluation_date');
                $table->string('side', 20);
                $table->string('currency', 3);
                $table->uuid('account_id');
                $table->decimal('closing_rate', 18, 8);
                $table->decimal('open_amount', 15, 4);
                $table->decimal('book_value', 15, 4);
                $table->decimal('revalued_value', 15, 4);
                $table->decimal('difference', 15, 4);
                $table->unsignedInteger('open_items');
                $table->uuid('journal_entry_id')->nullable();
                $table->date('reversal_date')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('account_id')->references('id')->on('chart_of_accounts')->restrictOnDelete();
                $table->unique(['tenant_id', 'revaluation_date', 'side', 'currency', 'account_id'], 'uq_gl_fx_revaluation');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_fx_revaluations');
        Schema::dropIfExists('gl_period_close_tasks');
        Schema::dropIfExists('gl_accruals');
        Schema::dropIfExists('gl_recurring_journal_runs');
        Schema::dropIfExists('gl_recurring_journals');
        Schema::dropIfExists('gl_ledger_settings');
        Schema::dropIfExists('gl_default_posting_accounts');

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropColumn(['auto_reverse_on', 'submitted_at', 'submitted_by', 'approved_at', 'approved_by', 'rejected_at', 'rejected_by', 'rejection_reason', 'recurring_journal_id']);
        });
    }
};
