<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Bank Branches
        if (! Schema::hasTable('bank_branches')) {
            Schema::create('bank_branches', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('bank_name', 150);
                $table->string('branch_code', 50)->nullable()->index();
                $table->string('branch_name', 150);
                $table->string('bic_swift', 11)->nullable()->index();
                $table->string('routing_number', 50)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('city', 100)->nullable();
                $table->string('country', 2)->default('IT');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'bank_name']);
            });
        }

        // 2. Bank Account Signatories
        if (! Schema::hasTable('bank_account_signatories')) {
            Schema::create('bank_account_signatories', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('bank_account_id')->index();
                $table->uuid('user_id')->index();
                $table->string('authorization_level', 30)->default('sole'); // sole, joint_a, joint_b, view_only
                $table->decimal('single_transaction_limit', 15, 2)->default(0.00);
                $table->decimal('daily_transaction_limit', 15, 2)->default(0.00);
                $table->date('valid_from');
                $table->date('valid_until')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'bank_account_id', 'is_active']);
            });
        }

        // 3. Bank Account Balances (Historical balance snapshots)
        if (! Schema::hasTable('bank_account_balances')) {
            Schema::create('bank_account_balances', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('bank_account_id')->index();
                $table->date('balance_date')->index();
                $table->decimal('ledger_balance', 15, 2);
                $table->decimal('available_balance', 15, 2);
                $table->string('currency', 3)->default('EUR');
                $table->string('source', 30)->default('statement'); // statement, manual, api_polling, eod_calculation
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'bank_account_id', 'balance_date'], 'uq_tenant_bank_balance_date');
            });
        }

        // 4. Bank Connections (Host-to-host, Open Banking, EBICS)
        if (! Schema::hasTable('bank_connections')) {
            Schema::create('bank_connections', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('protocol', 30); // ebics_3, open_banking_psd2, swift_alliance, sftp_host_to_host
                $table->string('provider_name', 100);
                $table->text('endpoint_url')->nullable();
                $table->string('partner_id', 100)->nullable();
                $table->string('host_id', 100)->nullable();
                $table->text('client_certificate_encrypted')->nullable();
                $table->text('private_key_encrypted')->nullable();
                $table->jsonb('auth_credentials_json')->nullable();
                $table->unsignedInteger('polling_frequency_minutes')->default(60);
                $table->timestamp('last_poll_at')->nullable();
                $table->string('status', 30)->default('active'); // active, degraded, error, suspended
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 5. Bank Reconciliations
        if (! Schema::hasTable('bank_reconciliations')) {
            Schema::create('bank_reconciliations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('bank_account_id')->index();
                $table->string('reconciliation_number', 50)->index();
                $table->date('period_start_date');
                $table->date('period_end_date');
                $table->decimal('bank_statement_balance', 15, 2);
                $table->decimal('gl_book_balance', 15, 2);
                $table->decimal('difference_amount', 15, 2)->default(0.00);
                $table->string('status', 30)->default('in_progress'); // in_progress, completed, approved, cancelled
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'bank_account_id', 'period_end_date']);
            });
        }

        // 6. Reconciliation Matches
        if (! Schema::hasTable('reconciliation_matches')) {
            Schema::create('reconciliation_matches', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('bank_reconciliation_id')->index();
                $table->uuid('bank_statement_line_id')->index();
                $table->string('matched_entity_type', 50); // customer_payment, supplier_payment, journal_entry, bank_fee, receivable_collection
                $table->uuid('matched_entity_id')->index();
                $table->string('match_type', 30)->default('exact_ref'); // exact_ref, exact_amount_date, ai_fuzzy, manual
                $table->decimal('allocated_amount', 15, 2);
                $table->decimal('confidence_score', 5, 2)->default(100.00);
                $table->uuid('journal_entry_id')->nullable()->index();
                $table->uuid('reconciled_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 7. Payment Lines
        if (! Schema::hasTable('payment_lines')) {
            Schema::create('payment_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('payment_batch_id')->index();
                $table->unsignedInteger('line_number')->default(1);
                $table->string('recipient_type', 30)->default('supplier'); // supplier, customer, employee, tax_authority
                $table->uuid('recipient_id')->nullable()->index();
                $table->string('recipient_name', 255);
                $table->string('recipient_iban', 34)->index();
                $table->string('recipient_bic', 11)->nullable();
                $table->decimal('amount', 15, 2);
                $table->string('currency', 3)->default('EUR');
                $table->string('instruction_id', 50)->nullable()->index();
                $table->string('end_to_end_id', 50)->nullable()->index();
                $table->string('remittance_reference', 140)->nullable();
                $table->string('structured_creditor_ref', 50)->nullable();
                $table->uuid('invoice_id')->nullable()->index();
                $table->uuid('payroll_payment_id')->nullable()->index();
                $table->string('status', 30)->default('pending')->index(); // pending, approved, executed, cleared, failed, cancelled
                $table->date('execution_date')->index();
                $table->string('error_code', 30)->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status', 'execution_date']);
            });
        }

        // 8. Payment Approvals
        if (! Schema::hasTable('payment_approvals')) {
            Schema::create('payment_approvals', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('payment_batch_id')->index();
                $table->uuid('approver_user_id')->index();
                $table->unsignedInteger('approval_tier')->default(1);
                $table->string('signature_hash', 128)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('decision', 20)->default('approved'); // approved, rejected
                $table->text('decision_reason')->nullable();
                $table->timestamp('approved_at')->useCurrent();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 9. Payment Executions
        if (! Schema::hasTable('payment_executions')) {
            Schema::create('payment_executions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('payment_batch_id')->index();
                $table->string('protocol', 30)->default('sepa_pain001');
                $table->string('bank_transmission_reference', 100)->nullable()->index();
                $table->string('pain002_status_code', 20)->nullable(); // ACCP, ACWC, RJCT, PDNG
                $table->timestamp('clearing_timestamp')->nullable();
                $table->jsonb('response_payload_json')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 10. Forecast Scenarios
        if (! Schema::hasTable('forecast_scenarios')) {
            Schema::create('forecast_scenarios', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 50)->index();
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->boolean('is_baseline')->default(false);
                $table->integer('collection_delay_days')->default(0);
                $table->integer('disbursement_acceleration_days')->default(0);
                $table->decimal('sales_growth_factor', 5, 4)->default(1.0000);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code'], 'uq_tenant_forecast_scenario_code');
            });
        }

        // 11. Cash Forecasts
        if (! Schema::hasTable('cash_forecasts')) {
            Schema::create('cash_forecasts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('forecast_scenario_id')->index();
                $table->string('forecast_number', 50)->index();
                $table->date('calculation_date')->index();
                $table->unsignedInteger('horizon_days')->default(90); // 30, 60, 90, 180, 360
                $table->decimal('opening_cash_balance', 15, 2)->default(0.00);
                $table->decimal('projected_inflows', 15, 2)->default(0.00);
                $table->decimal('projected_outflows', 15, 2)->default(0.00);
                $table->decimal('closing_cash_balance', 15, 2)->default(0.00);
                $table->decimal('min_liquidity_threshold', 15, 2)->default(50000.00);
                $table->boolean('deficit_detected')->default(false);
                $table->date('deficit_date')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'calculation_date']);
            });
        }

        // 12. Cash Forecast Lines
        if (! Schema::hasTable('cash_forecast_lines')) {
            Schema::create('cash_forecast_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('cash_forecast_id')->index();
                $table->date('projected_date')->index();
                $table->string('source_category', 40); // ar_invoices, ap_procurement, payroll, tax_f24, loan_service, recurring_contracts, project_forecast
                $table->string('flow_type', 10); // inflow, outflow
                $table->decimal('confidence_weight', 3, 2)->default(1.00); // 1.00 = confirmed, 0.70 = expected
                $table->decimal('expected_amount', 15, 2);
                $table->decimal('weighted_amount', 15, 2);
                $table->string('currency', 3)->default('EUR');
                $table->string('source_reference', 100)->nullable();
                $table->uuid('source_entity_id')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'projected_date', 'flow_type']);
            });
        }

        // 13. Cash Pools
        if (! Schema::hasTable('cash_pools')) {
            Schema::create('cash_pools', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('pool_name', 150);
                $table->string('pool_structure', 30)->default('zero_balance_account'); // zero_balance_account, target_balance, notional_pooling
                $table->uuid('header_bank_account_id')->index();
                $table->string('currency', 3)->default('EUR');
                $table->string('sweeping_frequency', 20)->default('daily'); // daily, weekly, biweekly
                $table->decimal('target_balance_floor', 15, 2)->default(0.00);
                $table->decimal('target_balance_ceiling', 15, 2)->default(0.00);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 14. Intercompany Funding
        if (! Schema::hasTable('intercompany_funding')) {
            Schema::create('intercompany_funding', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('cash_pool_id')->nullable()->index();
                $table->uuid('source_bank_account_id')->index();
                $table->uuid('destination_bank_account_id')->index();
                $table->decimal('transfer_amount', 15, 2);
                $table->date('transfer_date')->index();
                $table->decimal('intercompany_interest_rate', 6, 4)->default(0.0250);
                $table->uuid('gl_journal_entry_id')->nullable()->index();
                $table->string('status', 30)->default('executed'); // pending, executed, cancelled
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 15. Working Capital Metrics
        if (! Schema::hasTable('working_capital_metrics')) {
            Schema::create('working_capital_metrics', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->date('metric_date')->index();
                $table->decimal('accounts_receivable_total', 15, 2)->default(0.00);
                $table->decimal('accounts_payable_total', 15, 2)->default(0.00);
                $table->decimal('inventory_valuation_total', 15, 2)->default(0.00);
                $table->decimal('trailing_12m_revenue', 15, 2)->default(0.00);
                $table->decimal('trailing_12m_cogs', 15, 2)->default(0.00);
                $table->decimal('dso_days', 8, 2)->default(0.00); // Days Sales Outstanding
                $table->decimal('dpo_days', 8, 2)->default(0.00); // Days Payable Outstanding
                $table->decimal('dio_days', 8, 2)->default(0.00); // Days Inventory Outstanding
                $table->decimal('cash_conversion_cycle_days', 8, 2)->default(0.00); // CCC = DSO + DIO - DPO
                $table->decimal('current_ratio', 6, 3)->default(1.000);
                $table->decimal('quick_ratio', 6, 3)->default(1.000);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'metric_date'], 'uq_tenant_working_capital_date');
            });
        }

        // 16. FX Positions
        if (! Schema::hasTable('fx_positions')) {
            Schema::create('fx_positions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('currency', 3)->index();
                $table->decimal('total_cash_holdings', 15, 2)->default(0.00);
                $table->decimal('open_ar_exposure', 15, 2)->default(0.00);
                $table->decimal('open_ap_exposure', 15, 2)->default(0.00);
                $table->decimal('net_exposure', 15, 2)->default(0.00);
                $table->string('base_currency', 3)->default('EUR');
                $table->decimal('latest_spot_rate', 12, 6)->default(1.000000);
                $table->decimal('var_95_daily', 15, 2)->nullable();
                $table->date('as_of_date')->index();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'currency', 'as_of_date'], 'uq_tenant_fx_position_currency_date');
            });
        }

        // 17. FX Revaluations
        if (! Schema::hasTable('fx_revaluations')) {
            Schema::create('fx_revaluations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->date('period_end_date')->index();
                $table->string('currency', 3)->index();
                $table->decimal('foreign_amount', 15, 2);
                $table->decimal('book_value_base', 15, 2);
                $table->decimal('revalued_market_base', 15, 2);
                $table->decimal('unrealized_gain_loss', 15, 2);
                $table->uuid('gl_journal_entry_id')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 18. Loan Agreements
        if (! Schema::hasTable('loan_agreements')) {
            Schema::create('loan_agreements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('loan_reference', 50)->index();
                $table->uuid('lender_bank_account_id')->nullable()->index();
                $table->string('loan_type', 40)->default('term_loan'); // term_loan, revolving_credit, mortgage, esg_green_loan
                $table->decimal('principal_amount', 15, 2);
                $table->string('currency', 3)->default('EUR');
                $table->string('interest_rate_type', 20)->default('fixed'); // fixed, floating_euribor_3m, floating_saron
                $table->decimal('nominal_rate', 6, 4);
                $table->decimal('spread_rate', 6, 4)->default(0.0000);
                $table->date('origination_date');
                $table->date('maturity_date');
                $table->string('repayment_structure', 30)->default('french_amortization'); // french_amortization, bullet, equal_principal
                $table->jsonb('covenant_clause_json')->nullable();
                $table->string('status', 30)->default('active'); // draft, active, repaid, defaulted
                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'loan_reference'], 'uq_tenant_loan_ref');
            });
        }

        // 19. Loan Drawdowns
        if (! Schema::hasTable('loan_drawdowns')) {
            Schema::create('loan_drawdowns', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('loan_agreement_id')->index();
                $table->string('drawdown_number', 30);
                $table->decimal('amount', 15, 2);
                $table->date('drawdown_date')->index();
                $table->uuid('destination_bank_account_id')->nullable()->index();
                $table->uuid('gl_journal_entry_id')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 20. Interest Schedules
        if (! Schema::hasTable('interest_schedules')) {
            Schema::create('interest_schedules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('loan_agreement_id')->index();
                $table->date('period_start_date');
                $table->date('period_end_date');
                $table->date('due_date')->index();
                $table->decimal('principal_due', 15, 2)->default(0.00);
                $table->decimal('interest_due', 15, 2)->default(0.00);
                $table->decimal('total_due', 15, 2);
                $table->decimal('remaining_balance', 15, 2);
                $table->string('status', 30)->default('accrued'); // accrued, paid, overdue
                $table->uuid('payment_line_id')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 21. Bank Guarantees
        if (! Schema::hasTable('bank_guarantees')) {
            Schema::create('bank_guarantees', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('guarantee_number', 50)->index();
                $table->uuid('issuing_bank_account_id')->index();
                $table->string('beneficiary_name', 255);
                $table->string('beneficiary_tax_id', 50)->nullable();
                $table->string('guarantee_type', 40)->default('performance_bond'); // bid_bond, performance_bond, advance_payment, retention_bond
                $table->decimal('amount', 15, 2);
                $table->string('currency', 3)->default('EUR');
                $table->decimal('commission_rate_annual', 6, 4)->default(0.0150);
                $table->date('issue_date');
                $table->date('expiration_date')->index();
                $table->decimal('collateral_cash_blocked', 15, 2)->default(0.00);
                $table->uuid('project_id')->nullable()->index();
                $table->string('status', 30)->default('issued'); // issued, claimed, released, expired
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'guarantee_number'], 'uq_tenant_guarantee_no');
            });
        }

        // 22. Letters of Credit
        if (! Schema::hasTable('letters_of_credit')) {
            Schema::create('letters_of_credit', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('lc_reference', 50)->index();
                $table->string('type', 30)->default('import_documentary'); // import_documentary, export_documentary, standby
                $table->string('issuing_bank', 150);
                $table->string('advising_bank', 150)->nullable();
                $table->string('applicant_name', 255);
                $table->string('beneficiary_name', 255);
                $table->decimal('amount', 15, 2);
                $table->string('currency', 3)->default('EUR');
                $table->date('issue_date');
                $table->date('expiry_date')->index();
                $table->string('incoterms', 10)->default('FOB');
                $table->string('status', 30)->default('opened'); // opened, documents_presented, settled, cancelled
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'lc_reference'], 'uq_tenant_lc_ref');
            });
        }

        // 23. Treasury Alerts
        if (! Schema::hasTable('treasury_alerts')) {
            Schema::create('treasury_alerts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('alert_type', 50)->index(); // liquidity_deficit, overdraft_warning, large_disbursement, fx_var_breach, unmapped_statement
                $table->string('severity', 20)->default('warning'); // info, warning, critical, emergency
                $table->string('title', 200);
                $table->text('message');
                $table->decimal('threshold_value', 15, 2)->nullable();
                $table->decimal('actual_value', 15, 2)->nullable();
                $table->boolean('is_acknowledged')->default(false)->index();
                $table->uuid('acknowledged_by')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
                $table->jsonb('payload_json')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'severity', 'is_acknowledged']);
            });
        }

        // 24. Receivable Collections (Collection forecasting & dunning tracking)
        if (! Schema::hasTable('receivable_collections')) {
            Schema::create('receivable_collections', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('customer_id')->index();
                $table->uuid('invoice_id')->nullable()->index();
                $table->decimal('expected_amount', 15, 2);
                $table->decimal('collected_amount', 15, 2)->default(0.00);
                $table->date('due_date')->index();
                $table->date('collection_date')->nullable()->index();
                $table->string('payment_method', 30)->default('bank_transfer'); // sepa_direct_debit, bank_transfer, riba, credit_card
                $table->string('status', 30)->default('pending')->index(); // pending, collected, partial, overdue, defaulted
                $table->string('aging_bucket', 20)->default('current'); // current, 1_30, 31_60, 61_90, over_90
                $table->unsignedInteger('dunning_level')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status', 'due_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_collections');
        Schema::dropIfExists('treasury_alerts');
        Schema::dropIfExists('letters_of_credit');
        Schema::dropIfExists('bank_guarantees');
        Schema::dropIfExists('interest_schedules');
        Schema::dropIfExists('loan_drawdowns');
        Schema::dropIfExists('loan_agreements');
        Schema::dropIfExists('fx_revaluations');
        Schema::dropIfExists('fx_positions');
        Schema::dropIfExists('working_capital_metrics');
        Schema::dropIfExists('intercompany_funding');
        Schema::dropIfExists('cash_pools');
        Schema::dropIfExists('cash_forecast_lines');
        Schema::dropIfExists('cash_forecasts');
        Schema::dropIfExists('forecast_scenarios');
        Schema::dropIfExists('payment_executions');
        Schema::dropIfExists('payment_approvals');
        Schema::dropIfExists('payment_lines');
        Schema::dropIfExists('reconciliation_matches');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('bank_connections');
        Schema::dropIfExists('bank_account_balances');
        Schema::dropIfExists('bank_account_signatories');
        Schema::dropIfExists('bank_branches');
    }
};
