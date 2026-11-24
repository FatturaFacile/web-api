<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Fiscal Years
        if (! Schema::hasTable('fiscal_years')) {
            Schema::create('fiscal_years', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('year_code', 20); // 'FY2026'
                $table->date('start_date');
                $table->date('end_date');
                $table->string('status', 30)->default('open'); // 'open', 'closing_in_progress', 'closed'
                $table->boolean('is_locked')->default(false);
                $table->timestamp('closed_at')->nullable();
                $table->uuid('closed_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'company_id', 'year_code'], 'uq_fiscal_year');
            });
        }

        // 2. Fiscal Periods
        if (! Schema::hasTable('fiscal_periods')) {
            Schema::create('fiscal_periods', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('fiscal_year_id')->index();
                $table->integer('period_number'); // 1 to 12 (plus 13 for year-end adjustments)
                $table->string('period_name', 100); // 'January 2026'
                $table->date('start_date');
                $table->date('end_date');
                $table->string('status', 30)->default('open'); // 'open', 'soft_locked', 'hard_locked', 'closed'
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('fiscal_year_id')->references('id')->on('fiscal_years')->cascadeOnDelete();
                $table->unique(['fiscal_year_id', 'period_number'], 'uq_fiscal_period');
            });
        }

        // 3. Period Locks (Subledger Level)
        if (! Schema::hasTable('period_locks')) {
            Schema::create('period_locks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('fiscal_period_id')->index();
                $table->string('subledger_module', 30); // 'AR', 'AP', 'GL', 'INVENTORY', 'PAYROLL', 'FIXED_ASSETS', 'PROJECTS'
                $table->boolean('is_locked')->default(true);
                $table->uuid('locked_by')->nullable();
                $table->timestamp('locked_at')->useCurrent();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('fiscal_period_id')->references('id')->on('fiscal_periods')->cascadeOnDelete();
                $table->unique(['fiscal_period_id', 'subledger_module'], 'uq_subledger_lock');
            });
        }

        // 4. Chart of Accounts
        if (! Schema::hasTable('chart_of_accounts')) {
            Schema::create('chart_of_accounts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('parent_account_id')->nullable();
                $table->string('account_code', 50);
                $table->string('name', 255);
                $table->string('account_class', 30)->default('standard'); // 'asset', 'liability', 'equity', 'revenue', 'expense'
                $table->string('account_type', 40); // 'asset', 'liability', 'equity', 'revenue', 'expense', 'cogs', 'wip', 'memo', 'statistical'
                $table->string('normal_balance', 10)->default('debit'); // 'debit', 'credit'
                $table->string('currency', 3)->default('EUR');
                $table->boolean('is_reconciliation_account')->default(false); // AR / AP control account
                $table->string('reconciliation_type', 20)->nullable(); // 'customer', 'supplier', 'bank', 'tax', 'gr_ir', 'wip', 'inventory'
                $table->boolean('allows_manual_posting')->default(true);
                $table->boolean('is_cost_center_mandatory')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('parent_account_id')->references('id')->on('chart_of_accounts')->nullOnDelete();
                $table->unique(['tenant_id', 'company_id', 'account_code'], 'uq_gl_account_code');
            });
        } else {
            Schema::table('chart_of_accounts', function (Blueprint $table): void {
                if (! Schema::hasColumn('chart_of_accounts', 'company_id')) {
                    $table->uuid('company_id')->nullable()->index();
                }
                if (! Schema::hasColumn('chart_of_accounts', 'parent_account_id')) {
                    $table->uuid('parent_account_id')->nullable();
                }
                if (! Schema::hasColumn('chart_of_accounts', 'account_code')) {
                    $table->string('account_code', 50)->nullable()->index();
                }
                if (! Schema::hasColumn('chart_of_accounts', 'account_class')) {
                    $table->string('account_class', 30)->default('standard');
                }
                if (! Schema::hasColumn('chart_of_accounts', 'account_type')) {
                    $table->string('account_type', 40)->default('asset');
                }
                if (! Schema::hasColumn('chart_of_accounts', 'normal_balance')) {
                    $table->string('normal_balance', 10)->default('debit');
                }
                if (! Schema::hasColumn('chart_of_accounts', 'currency')) {
                    $table->string('currency', 3)->default('EUR');
                }
                if (! Schema::hasColumn('chart_of_accounts', 'is_reconciliation_account')) {
                    $table->boolean('is_reconciliation_account')->default(false);
                }
                if (! Schema::hasColumn('chart_of_accounts', 'reconciliation_type')) {
                    $table->string('reconciliation_type', 20)->nullable();
                }
                if (! Schema::hasColumn('chart_of_accounts', 'allows_manual_posting')) {
                    $table->boolean('allows_manual_posting')->default(true);
                }
                if (! Schema::hasColumn('chart_of_accounts', 'is_cost_center_mandatory')) {
                    $table->boolean('is_cost_center_mandatory')->default(false);
                }
            });
        }

        // 5. Cost Centers & Profit Centers
        if (! Schema::hasTable('cost_centers')) {
            Schema::create('cost_centers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 50);
                $table->string('name', 255);
                $table->uuid('department_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'company_id', 'code'], 'uq_cost_center');
            });
        }

        if (! Schema::hasTable('profit_centers')) {
            Schema::create('profit_centers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 50);
                $table->string('name', 255);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'company_id', 'code'], 'uq_profit_center');
            });
        }

        // 6. VAT / Tax Codes
        if (! Schema::hasTable('vat_codes')) {
            Schema::create('vat_codes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 30); // 'IVA22', 'IVA10', 'CH_MWST_81', 'REVERSE_CHARGE'
                $table->string('description', 255);
                $table->decimal('tax_rate_percent', 5, 2)->default(0.00);
                $table->string('country', 3)->default('IT');
                $table->boolean('is_reverse_charge')->default(false);
                $table->boolean('is_split_payment')->default(false);
                $table->boolean('is_non_deductible')->default(false);
                $table->decimal('non_deductible_percentage', 5, 2)->default(0.00);
                $table->uuid('gl_sales_tax_account_id')->nullable();
                $table->uuid('gl_purchase_tax_account_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('gl_sales_tax_account_id')->references('id')->on('chart_of_accounts')->nullOnDelete();
                $table->foreign('gl_purchase_tax_account_id')->references('id')->on('chart_of_accounts')->nullOnDelete();
                $table->unique(['tenant_id', 'company_id', 'code'], 'uq_vat_code');
            });
        }

        // 7. VAT Registers
        if (! Schema::hasTable('vat_registers')) {
            Schema::create('vat_registers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 30); // 'REG_ACQ_1', 'REG_VEN_1'
                $table->string('name', 100);
                $table->string('register_type', 30); // 'purchases', 'sales', 'cash_receipts'
                $table->integer('progressive_protocol_year');
                $table->integer('last_protocol_number')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'company_id', 'code', 'progressive_protocol_year'], 'uq_vat_register');
            });
        }

        // 8. Journal Batches
        if (! Schema::hasTable('journal_batches')) {
            Schema::create('journal_batches', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('batch_number', 50);
                $table->string('description', 255);
                $table->string('source_module', 30)->default('MANUAL'); // 'PROCUREMENT', 'INVENTORY', 'PROJECTS', 'INVOICING', 'MANUAL'
                $table->string('status', 30)->default('open'); // 'open', 'posted', 'rejected'
                $table->decimal('total_debit', 15, 4)->default(0.0000);
                $table->decimal('total_credit', 15, 4)->default(0.0000);
                $table->uuid('created_by')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->uuid('posted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'company_id', 'batch_number'], 'uq_batch_number');
            });
        }

        // 9. Journal Entries
        if (! Schema::hasTable('journal_entries')) {
            Schema::create('journal_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('batch_id')->nullable()->index();
                $table->uuid('fiscal_period_id')->index();
                $table->string('entry_number', 50);
                $table->date('posting_date')->index();
                $table->date('document_date');
                $table->string('ledger_type', 30)->default('statutory'); // 'statutory', 'ifrs', 'management'
                $table->string('journal_type', 40)->default('standard'); // 'standard', 'reversing', 'closing', 'opening', 'recurring', 'adjusting'
                $table->date('reversal_date')->nullable();
                $table->uuid('reversal_journal_entry_id')->nullable();
                $table->text('description');
                $table->string('source_document_type', 50)->nullable(); // 'purchase_order', 'receipt', 'inventory_movement', 'project_cost', etc.
                $table->uuid('source_document_id')->nullable()->index();
                $table->uuid('workflow_instance_id')->nullable();
                $table->string('status', 30)->default('draft'); // 'draft', 'pending_approval', 'posted', 'reversed'
                $table->decimal('total_amount', 15, 4)->default(0.0000);
                $table->uuid('created_by')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->uuid('posted_by')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->uuid('reversed_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('batch_id')->references('id')->on('journal_batches')->nullOnDelete();
                $table->foreign('fiscal_period_id')->references('id')->on('fiscal_periods')->restrictOnDelete();
                $table->unique(['tenant_id', 'company_id', 'entry_number'], 'uq_gl_entry_number');
            });
        }

        // 10. Journal Lines
        if (! Schema::hasTable('journal_lines')) {
            Schema::create('journal_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('journal_entry_id')->index();
                $table->date('posting_date')->nullable()->index();
                $table->integer('line_number');
                $table->uuid('account_id')->index();
                $table->uuid('cost_center_id')->nullable()->index();
                $table->uuid('profit_center_id')->nullable()->index();
                $table->uuid('project_id')->nullable()->index();
                $table->string('partner_type', 30)->nullable(); // 'customer', 'supplier', 'employee'
                $table->uuid('partner_id')->nullable()->index();
                $table->string('description', 255);
                $table->decimal('debit_amount', 15, 4)->default(0.0000);
                $table->decimal('credit_amount', 15, 4)->default(0.0000);
                $table->string('currency', 3)->default('EUR');
                $table->decimal('exchange_rate', 12, 6)->default(1.000000);
                $table->decimal('amount_in_functional_currency', 15, 4)->default(0.0000);
                $table->uuid('vat_code_id')->nullable();
                $table->decimal('vat_base_amount', 15, 4)->default(0.0000);
                $table->decimal('vat_tax_amount', 15, 4)->default(0.0000);
                $table->boolean('is_cleared')->default(false);
                $table->string('clearing_reference', 50)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->cascadeOnDelete();
                $table->foreign('account_id')->references('id')->on('chart_of_accounts')->restrictOnDelete();
                $table->foreign('cost_center_id')->references('id')->on('cost_centers')->nullOnDelete();
                $table->foreign('profit_center_id')->references('id')->on('profit_centers')->nullOnDelete();
                $table->foreign('vat_code_id')->references('id')->on('vat_codes')->nullOnDelete();
            });
        }

        // 11. Customer Subledger (Accounts Receivable Open Items)
        if (! Schema::hasTable('customer_ledger')) {
            Schema::create('customer_ledger', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('customer_id')->index();
                $table->uuid('journal_entry_id')->index();
                $table->date('posting_date');
                $table->date('due_date');
                $table->decimal('original_amount', 15, 4);
                $table->decimal('open_amount', 15, 4);
                $table->string('currency', 3)->default('EUR');
                $table->string('status', 30)->default('open'); // 'open', 'partially_paid', 'settled', 'disputed'
                $table->integer('dunning_level')->default(0);
                $table->date('last_dunning_date')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->cascadeOnDelete();
            });
        }

        // 12. Supplier Subledger (Accounts Payable Open Items)
        if (! Schema::hasTable('supplier_ledger')) {
            Schema::create('supplier_ledger', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('supplier_id')->index();
                $table->uuid('journal_entry_id')->index();
                $table->date('posting_date');
                $table->date('due_date');
                $table->decimal('original_amount', 15, 4);
                $table->decimal('open_amount', 15, 4);
                $table->string('currency', 3)->default('EUR');
                $table->string('status', 30)->default('open'); // 'open', 'partially_paid', 'settled'
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->cascadeOnDelete();
            });
        }

        // 13. Accrual & Prepayment Entries
        if (! Schema::hasTable('accrual_entries')) {
            Schema::create('accrual_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('title', 255);
                $table->string('accrual_type', 40); // 'prepaid_expense', 'deferred_revenue', 'accrued_expense', 'accrued_revenue'
                $table->decimal('total_amount', 15, 4);
                $table->date('start_date');
                $table->date('end_date');
                $table->integer('periods_count');
                $table->decimal('monthly_amount', 15, 4);
                $table->uuid('debit_account_id');
                $table->uuid('credit_account_id');
                $table->string('status', 30)->default('active'); // 'active', 'completed', 'cancelled'
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('debit_account_id')->references('id')->on('chart_of_accounts')->restrictOnDelete();
                $table->foreign('credit_account_id')->references('id')->on('chart_of_accounts')->restrictOnDelete();
            });
        }

        // 14. Posting Templates
        if (! Schema::hasTable('posting_templates')) {
            Schema::create('posting_templates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 50);
                $table->string('name', 255);
                $table->string('source_event_type', 100);
                $table->text('description')->nullable();
                $table->jsonb('template_payload')->default('[]');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'company_id', 'code'], 'uq_posting_template');
            });
        }

        // 15. Reconciliation Runs & Items
        if (! Schema::hasTable('reconciliation_runs')) {
            Schema::create('reconciliation_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('reconciliation_type', 50); // 'bank', 'gr_ir', 'subledger_ar', 'subledger_ap', 'inventory', 'project'
                $table->uuid('period_id')->nullable()->index();
                $table->uuid('account_id')->index();
                $table->string('status', 30)->default('reconciled'); // 'balanced', 'discrepancy', 'reconciled'
                $table->decimal('gl_balance', 15, 4)->default(0.0000);
                $table->decimal('subledger_balance', 15, 4)->default(0.0000);
                $table->decimal('variance_amount', 15, 4)->default(0.0000);
                $table->uuid('reconciled_by')->nullable();
                $table->timestamp('reconciled_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('account_id')->references('id')->on('chart_of_accounts')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('reconciliation_items')) {
            Schema::create('reconciliation_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('reconciliation_run_id')->index();
                $table->string('item_reference', 100);
                $table->uuid('gl_line_id')->nullable();
                $table->uuid('subledger_item_id')->nullable();
                $table->decimal('item_amount', 15, 4)->default(0.0000);
                $table->decimal('variance_amount', 15, 4)->default(0.0000);
                $table->boolean('is_matched')->default(true);
                $table->string('match_type', 30)->default('automatic'); // 'automatic', 'manual'
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('reconciliation_run_id')->references('id')->on('reconciliation_runs')->cascadeOnDelete();
            });
        }

        // 16. Financial Closing Runs (Month-End & Year-End)
        if (! Schema::hasTable('financial_closing_runs')) {
            Schema::create('financial_closing_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('fiscal_period_id')->index();
                $table->string('closing_type', 30); // 'month_end', 'quarter_end', 'year_end'
                $table->string('status', 30)->default('completed'); // 'draft', 'in_progress', 'completed', 'failed'
                $table->uuid('closing_journal_id')->nullable();
                $table->uuid('retained_earnings_journal_id')->nullable();
                $table->decimal('net_income_amount', 15, 4)->default(0.0000);
                $table->uuid('executed_by')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('fiscal_period_id')->references('id')->on('fiscal_periods')->restrictOnDelete();
            });
        }

        // 17. Accounting Anomalies (AI Copilot & Error Detection)
        if (! Schema::hasTable('accounting_anomalies')) {
            Schema::create('accounting_anomalies', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('journal_entry_id')->index();
                $table->string('anomaly_type', 50); // 'out_of_period_entry', 'weekend_posting', 'duplicate_amount', 'unusual_account_pair'
                $table->string('severity', 20)->default('warning'); // 'low', 'warning', 'high', 'critical'
                $table->decimal('confidence_score', 5, 2)->default(90.00);
                $table->text('explanation');
                $table->string('status', 30)->default('open'); // 'open', 'resolved', 'ignored'
                $table->timestamp('detected_at')->useCurrent();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->cascadeOnDelete();
            });
        }

        // 18. Carbon Ledger (ESG Financial Link)
        if (! Schema::hasTable('carbon_ledger')) {
            Schema::create('carbon_ledger', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('journal_entry_id')->nullable()->index();
                $table->string('scope', 10); // 'scope_1', 'scope_2', 'scope_3'
                $table->string('emission_category', 50); // 'fleet_diesel', 'facility_gas', 'electricity', 'supply_chain'
                $table->decimal('activity_data_metric', 12, 4);
                $table->string('activity_uom', 20);
                $table->decimal('emission_factor', 12, 6);
                $table->decimal('total_co2e_kg', 15, 4);
                $table->string('source_module', 30);
                $table->date('posting_date');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 19. VAT Settlements (Liquidazione IVA)
        if (! Schema::hasTable('vat_settlements')) {
            Schema::create('vat_settlements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('fiscal_period_id')->index();
                $table->string('settlement_period', 20); // '2026-M01', '2026-Q1'
                $table->decimal('output_vat_amount', 15, 4)->default(0.0000); // IVA a debito
                $table->decimal('input_vat_amount', 15, 4)->default(0.0000); // IVA a credito
                $table->decimal('prior_credit_used', 15, 4)->default(0.0000);
                $table->decimal('net_vat_payable', 15, 4)->default(0.0000);
                $table->decimal('net_vat_credit', 15, 4)->default(0.0000);
                $table->string('status', 30)->default('calculated'); // 'calculated', 'posted', 'paid'
                $table->uuid('settlement_journal_id')->nullable();
                $table->uuid('calculated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('fiscal_period_id')->references('id')->on('fiscal_periods')->restrictOnDelete();
            });
        } else {
            Schema::table('vat_settlements', function (Blueprint $table): void {
                if (! Schema::hasColumn('vat_settlements', 'company_id')) {
                    $table->uuid('company_id')->nullable()->index();
                }
                if (! Schema::hasColumn('vat_settlements', 'fiscal_period_id')) {
                    $table->uuid('fiscal_period_id')->nullable()->index();
                }
                if (! Schema::hasColumn('vat_settlements', 'settlement_period')) {
                    $table->string('settlement_period', 20)->nullable();
                }
                if (! Schema::hasColumn('vat_settlements', 'output_vat_amount')) {
                    $table->decimal('output_vat_amount', 15, 4)->default(0.0000);
                }
                if (! Schema::hasColumn('vat_settlements', 'input_vat_amount')) {
                    $table->decimal('input_vat_amount', 15, 4)->default(0.0000);
                }
                if (! Schema::hasColumn('vat_settlements', 'prior_credit_used')) {
                    $table->decimal('prior_credit_used', 15, 4)->default(0.0000);
                }
                if (! Schema::hasColumn('vat_settlements', 'net_vat_payable')) {
                    $table->decimal('net_vat_payable', 15, 4)->default(0.0000);
                }
                if (! Schema::hasColumn('vat_settlements', 'net_vat_credit')) {
                    $table->decimal('net_vat_credit', 15, 4)->default(0.0000);
                }
                if (! Schema::hasColumn('vat_settlements', 'status')) {
                    $table->string('status', 30)->default('calculated');
                }
                if (! Schema::hasColumn('vat_settlements', 'settlement_journal_id')) {
                    $table->uuid('settlement_journal_id')->nullable();
                }
                if (! Schema::hasColumn('vat_settlements', 'calculated_by')) {
                    $table->uuid('calculated_by')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_settlements');
        Schema::dropIfExists('carbon_ledger');
        Schema::dropIfExists('accounting_anomalies');
        Schema::dropIfExists('financial_closing_runs');
        Schema::dropIfExists('reconciliation_items');
        Schema::dropIfExists('reconciliation_runs');
        Schema::dropIfExists('posting_templates');
        Schema::dropIfExists('accrual_entries');
        Schema::dropIfExists('supplier_ledger');
        Schema::dropIfExists('customer_ledger');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('journal_batches');
        Schema::dropIfExists('vat_registers');
        Schema::dropIfExists('vat_codes');
        Schema::dropIfExists('profit_centers');
        Schema::dropIfExists('cost_centers');
        Schema::dropIfExists('chart_of_accounts');
        Schema::dropIfExists('period_locks');
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('fiscal_years');
    }
};
