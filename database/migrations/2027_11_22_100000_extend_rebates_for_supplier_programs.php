<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supplier rebate programmes (Mod 049): programme type (tiered volume, fixed target bonus, marketing contributions),
     * link to a blanket agreement, ledger accounts of the accrual, flat tier amounts, marketing contributions, accrual
     * runs with the invoices that contributed to them, and the settlement proposal workflow with its analytical lines and
     * the open-item set-off against a supplier payable. Additive only: every new column is nullable or has a default.
     */
    public function up(): void
    {
        Schema::table('rebate_agreements', function (Blueprint $table): void {
            if (! Schema::hasColumn('rebate_agreements', 'rebate_type')) {
                $table->uuid('supplier_contract_id')->nullable()->index();
                $table->string('rebate_type', 24)->default('tiered_volume');
                $table->decimal('fixed_bonus_target', 15, 2)->nullable();
                $table->decimal('fixed_bonus_amount', 15, 2)->nullable();
                $table->string('accrual_frequency', 12)->default('monthly');
                $table->uuid('accrual_account_id')->nullable();
                $table->uuid('income_account_id')->nullable();
                $table->unsignedInteger('version')->default(1);
            }
        });

        Schema::table('rebate_tiers', function (Blueprint $table): void {
            if (! Schema::hasColumn('rebate_tiers', 'flat_amount')) {
                $table->decimal('flat_amount', 15, 2)->nullable();
            }
        });

        Schema::table('rebate_accruals', function (Blueprint $table): void {
            if (! Schema::hasColumn('rebate_accruals', 'accrual_run_id')) {
                $table->uuid('accrual_run_id')->nullable()->index();
                $table->uuid('journal_entry_id')->nullable();
                $table->string('source', 16)->default('volume');
            }
        });

        Schema::table('rebate_settlements', function (Blueprint $table): void {
            if (! Schema::hasColumn('rebate_settlements', 'status')) {
                $table->string('status', 20)->default('settled');
                $table->string('execution_mode', 24)->nullable();
                $table->decimal('gross_amount', 15, 2)->nullable();
                $table->decimal('tax_rate', 7, 2)->nullable();
                $table->decimal('tax_amount', 15, 2)->nullable();
                $table->uuid('passive_invoice_id')->nullable()->index();
                $table->decimal('set_off_amount', 15, 2)->nullable();
                $table->uuid('journal_entry_id')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedInteger('version')->default(1);
            }
        });

        if (! Schema::hasTable('rebate_contributions')) {
            Schema::create('rebate_contributions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('agreement_id')->constrained('rebate_agreements')->cascadeOnDelete();
                $table->string('kind', 16)->default('marketing');
                $table->string('description', 200);
                $table->decimal('amount', 15, 2);
                $table->date('due_on');
                $table->string('status', 12)->default('planned');
                $table->uuid('accrual_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'agreement_id', 'status']);
            });
        }

        if (! Schema::hasTable('rebate_accrual_runs')) {
            Schema::create('rebate_accrual_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('agreement_id')->constrained('rebate_agreements')->cascadeOnDelete();
                $table->date('period_start');
                $table->date('period_end');
                $table->string('status', 12)->default('posted');
                $table->decimal('base_amount', 15, 2)->default(0);
                $table->decimal('cumulative_rebate', 15, 2)->default(0);
                $table->decimal('delta_amount', 15, 2)->default(0);
                $table->uuid('journal_entry_id')->nullable();
                $table->uuid('reversal_journal_entry_id')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->string('reversal_reason', 200)->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'agreement_id', 'period_end']);
            });
        }

        if (! Schema::hasTable('rebate_accrual_lines')) {
            Schema::create('rebate_accrual_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('accrual_run_id')->constrained('rebate_accrual_runs')->cascadeOnDelete();
                $table->uuid('passive_invoice_id')->nullable()->index();
                $table->string('invoice_number', 60)->nullable();
                $table->string('document_type', 8)->nullable();
                $table->date('invoice_date')->nullable();
                $table->decimal('net_amount', 15, 2);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('rebate_settlement_lines')) {
            Schema::create('rebate_settlement_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('settlement_id')->constrained('rebate_settlements')->cascadeOnDelete();
                $table->string('line_type', 16);
                $table->uuid('passive_invoice_id')->nullable();
                $table->string('reference', 120)->nullable();
                $table->date('reference_date')->nullable();
                $table->decimal('net_amount', 15, 2)->default(0);
                $table->decimal('rebate_amount', 15, 2)->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'settlement_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rebate_settlement_lines');
        Schema::dropIfExists('rebate_accrual_lines');
        Schema::dropIfExists('rebate_accrual_runs');
        Schema::dropIfExists('rebate_contributions');

        Schema::table('rebate_settlements', function (Blueprint $table): void {
            if (Schema::hasColumn('rebate_settlements', 'status')) {
                $table->dropIndex(['passive_invoice_id']);
                $table->dropColumn(['status', 'execution_mode', 'gross_amount', 'tax_rate', 'tax_amount', 'passive_invoice_id', 'set_off_amount', 'journal_entry_id', 'submitted_at', 'approved_by', 'approved_at', 'cancelled_at', 'version']);
            }
        });

        Schema::table('rebate_accruals', function (Blueprint $table): void {
            if (Schema::hasColumn('rebate_accruals', 'accrual_run_id')) {
                $table->dropIndex(['accrual_run_id']);
                $table->dropColumn(['accrual_run_id', 'journal_entry_id', 'source']);
            }
        });

        Schema::table('rebate_tiers', function (Blueprint $table): void {
            if (Schema::hasColumn('rebate_tiers', 'flat_amount')) {
                $table->dropColumn('flat_amount');
            }
        });

        Schema::table('rebate_agreements', function (Blueprint $table): void {
            if (Schema::hasColumn('rebate_agreements', 'rebate_type')) {
                $table->dropIndex(['supplier_contract_id']);
                $table->dropColumn(['supplier_contract_id', 'rebate_type', 'fixed_bonus_target', 'fixed_bonus_amount', 'accrual_frequency', 'accrual_account_id', 'income_account_id', 'version']);
            }
        });
    }
};
