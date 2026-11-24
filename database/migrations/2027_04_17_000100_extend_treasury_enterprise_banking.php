<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise extension of treasury and banking (additive only, safe for `migrate --force`).
 *
 * - Ledger links of categorised statement lines and the reconciliation rules that categorise them.
 * - Bank movements posted by treasury: internal transfers (both accounts), bank fees and interest.
 * - Cash registers (prima nota cassa) with their movements and daily closings.
 * - Supplier payment proposals, approved by someone other than their author and turned into payment batches.
 * - Recurring cash items for the liquidity forecast.
 * - FX revaluations per bank account and period end, posted once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('bank_statement_lines', 'journal_entry_id')) {
                $table->uuid('journal_entry_id')->nullable()->index();
            }

            if (! Schema::hasColumn('bank_statement_lines', 'reconciliation_rule_id')) {
                $table->uuid('reconciliation_rule_id')->nullable()->index();
            }
        });

        if (! Schema::hasTable('bank_reconciliation_rules')) {
            Schema::create('bank_reconciliation_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 120);
                $table->unsignedInteger('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->string('direction', 10)->nullable();
                $table->decimal('expected_amount', 15, 2)->nullable();
                $table->decimal('amount_tolerance', 15, 2)->default(0);
                $table->decimal('min_amount', 15, 2)->nullable();
                $table->decimal('max_amount', 15, 2)->nullable();
                $table->string('reference_contains', 140)->nullable();
                $table->string('description_contains', 140)->nullable();
                $table->string('counterparty_iban', 34)->nullable();
                $table->string('counterparty_name_contains', 140)->nullable();
                $table->string('category', 50);
                $table->string('ledger_account_code', 20)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'is_active', 'priority']);
                $table->unique(['tenant_id', 'name']);
            });
        }

        if (! Schema::hasTable('treasury_bank_movements')) {
            Schema::create('treasury_bank_movements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('movement_type', 30);
                $table->uuid('bank_account_id')->index();
                $table->uuid('counter_bank_account_id')->nullable()->index();
                $table->decimal('amount', 15, 2);
                $table->string('currency', 3);
                $table->date('movement_date')->index();
                $table->string('description', 255)->nullable();
                $table->string('idempotency_key', 100);
                $table->uuid('journal_entry_id')->nullable()->index();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'idempotency_key'], 'uq_treasury_bank_movement_key');
            });
        }

        if (! Schema::hasTable('treasury_cash_registers')) {
            Schema::create('treasury_cash_registers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 30);
                $table->string('name', 150);
                $table->string('currency', 3);
                $table->uuid('gl_account_id');
                $table->decimal('opening_balance', 15, 2)->default(0);
                $table->decimal('current_balance', 15, 2)->default(0);
                $table->date('last_closed_date')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code'], 'uq_treasury_cash_register_code');
            });
        }

        if (! Schema::hasTable('treasury_cash_movements')) {
            Schema::create('treasury_cash_movements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('cash_register_id')->index();
                $table->date('movement_date')->index();
                $table->string('direction', 10);
                $table->string('payment_method', 20)->default('cash');
                $table->decimal('amount', 15, 2);
                $table->uuid('counter_account_id');
                $table->string('description', 255);
                $table->string('reference', 100)->nullable();
                $table->string('idempotency_key', 100);
                $table->uuid('journal_entry_id')->nullable()->index();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'idempotency_key'], 'uq_treasury_cash_movement_key');
            });
        }

        if (! Schema::hasTable('treasury_cash_closings')) {
            Schema::create('treasury_cash_closings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('cash_register_id')->index();
                $table->date('closing_date');
                $table->decimal('opening_balance', 15, 2);
                $table->decimal('total_in', 15, 2);
                $table->decimal('total_out', 15, 2);
                $table->decimal('expected_balance', 15, 2);
                $table->decimal('counted_balance', 15, 2);
                $table->decimal('difference', 15, 2);
                $table->uuid('difference_journal_entry_id')->nullable();
                $table->text('notes')->nullable();
                $table->uuid('closed_by')->nullable();
                $table->timestamps();

                $table->unique(['cash_register_id', 'closing_date'], 'uq_treasury_cash_closing_day');
            });
        }

        if (! Schema::hasTable('treasury_payment_proposals')) {
            Schema::create('treasury_payment_proposals', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('proposal_number', 40);
                $table->uuid('bank_account_id')->index();
                $table->string('status', 20)->default('draft');
                $table->string('format', 30);
                $table->date('execution_date');
                $table->date('due_until');
                $table->boolean('include_discounts')->default(true);
                $table->string('currency', 3);
                $table->decimal('total_amount', 15, 2)->default(0);
                $table->decimal('total_discount', 15, 2)->default(0);
                $table->unsignedInteger('item_count')->default(0);
                $table->uuid('created_by');
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->uuid('payment_batch_id')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'proposal_number'], 'uq_treasury_payment_proposal_number');
            });
        }

        if (! Schema::hasTable('treasury_payment_proposal_lines')) {
            Schema::create('treasury_payment_proposal_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('payment_proposal_id')->index();
                $table->uuid('passive_invoice_id')->index();
                $table->uuid('supplier_id')->index();
                $table->string('selection_reason', 20);
                $table->date('due_date')->nullable();
                $table->date('discount_deadline')->nullable();
                $table->decimal('open_amount', 15, 2);
                $table->decimal('discount_amount', 15, 2)->default(0);
                $table->decimal('amount', 15, 2);
                $table->uuid('discount_payment_id')->nullable();
                $table->timestamps();

                $table->unique(['payment_proposal_id', 'passive_invoice_id'], 'uq_treasury_proposal_invoice');
            });
        }

        if (! Schema::hasTable('treasury_recurring_items')) {
            Schema::create('treasury_recurring_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('name', 150);
                $table->string('category', 50)->default('other');
                $table->string('direction', 10);
                $table->decimal('amount', 15, 2);
                $table->string('currency', 3);
                $table->string('frequency', 20);
                $table->date('next_date');
                $table->date('end_date')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        Schema::table('fx_revaluations', function (Blueprint $table): void {
            if (! Schema::hasColumn('fx_revaluations', 'bank_account_id')) {
                $table->uuid('bank_account_id')->nullable()->index();
            }

            if (! Schema::hasColumn('fx_revaluations', 'closing_rate')) {
                $table->decimal('closing_rate', 18, 8)->nullable();
            }
        });

        Schema::table('fx_revaluations', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'bank_account_id', 'period_end_date'], 'uq_fx_revaluation_account_period');
        });
    }

    public function down(): void
    {
        Schema::table('fx_revaluations', function (Blueprint $table): void {
            $table->dropUnique('uq_fx_revaluation_account_period');
            $table->dropColumn(['bank_account_id', 'closing_rate']);
        });

        Schema::dropIfExists('treasury_recurring_items');
        Schema::dropIfExists('treasury_payment_proposal_lines');
        Schema::dropIfExists('treasury_payment_proposals');
        Schema::dropIfExists('treasury_cash_closings');
        Schema::dropIfExists('treasury_cash_movements');
        Schema::dropIfExists('treasury_cash_registers');
        Schema::dropIfExists('treasury_bank_movements');
        Schema::dropIfExists('bank_reconciliation_rules');

        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->dropColumn(['journal_entry_id', 'reconciliation_rule_id']);
        });
    }
};
