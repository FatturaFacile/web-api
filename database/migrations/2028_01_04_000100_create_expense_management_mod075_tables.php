<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 075 — Expense and travel management (expense reports, receipts with OCR and duplicate detection, mileage claims,
 * per-diem allowances, corporate card reconciliation, payout batches and the approval trail).
 * Dialect-agnostic: runs identically on SQLite and PostgreSQL. Rows with a null tenant_id are global reference data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->categories();
        $this->policies();
        $this->vehicleRates();
        $this->cardAccounts();
        $this->reports();
        $this->receipts();
        $this->cardTransactions();
        $this->lines();
        $this->mileageClaims();
        $this->perDiems();
        $this->payouts();
        $this->auditLogs();
    }

    public function down(): void
    {
        foreach (['exm_audit_logs', 'exm_payouts', 'exm_per_diems', 'exm_mileage_claims', 'exm_lines', 'exm_card_transactions', 'exm_receipts', 'exm_reports', 'exm_card_accounts', 'exm_aci_vehicle_rates', 'exm_policy_rules', 'exm_policies', 'exm_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function categories(): void
    {
        if (Schema::hasTable('exm_categories')) {
            return;
        }

        Schema::create('exm_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 160)->nullable(); // custom label of a tenant category; seeded ones use the translation key
            $table->boolean('is_meal')->default(false);
            $table->boolean('is_lodging')->default(false);
            $table->boolean('is_transport')->default(false);
            $table->boolean('is_mileage')->default(false);
            $table->boolean('vat_recoverable')->default(true);
            $table->boolean('requires_receipt')->default(true);
            $table->string('default_gl_account_code', 20)->nullable();
            $table->string('default_vat_code', 20)->nullable();
            $table->decimal('default_vat_rate', 7, 4)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $this->audit($table);

            $table->unique(['tenant_id', 'code'], 'exm_categories_code_uq');
        });
    }

    private function policies(): void
    {
        if (! Schema::hasTable('exm_policies')) {
            Schema::create('exm_policies', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('name', 160);
                $table->string('jurisdiction', 2); // CH | IT
                $table->boolean('is_active')->default(true);
                $table->boolean('duplicate_detection')->default(true);
                $table->boolean('block_non_traceable')->default(false);
                $table->decimal('receipt_threshold', 15, 2)->default(0); // a receipt is mandatory above this amount
                $table->decimal('mileage_rate_per_km', 10, 4)->nullable(); // Swiss regulation rate; null = jurisdiction default
                $table->decimal('per_diem_daily_limit', 15, 2)->nullable(); // Swiss regulation tax-free daily limit
                $table->unsignedSmallInteger('max_age_days')->nullable();
                $this->audit($table);

                $table->unique(['tenant_id', 'code'], 'exm_policies_code_uq');
            });
        }

        if (! Schema::hasTable('exm_policy_rules')) {
            Schema::create('exm_policy_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('policy_id')->constrained('exm_policies')->cascadeOnDelete();
                $table->string('category_code', 40)->nullable(); // null = every category
                $table->string('rule_type', 30); // max_per_item | max_per_day
                $table->decimal('limit_amount', 15, 2);
                $table->string('severity', 10)->default('warning'); // warning | blocking
                $this->audit($table);

                $table->index(['tenant_id', 'policy_id'], 'exm_policy_rules_policy_ix');
            });
        }
    }

    private function vehicleRates(): void
    {
        if (Schema::hasTable('exm_aci_vehicle_rates')) {
            return;
        }

        Schema::create('exm_aci_vehicle_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('source', 20)->default('IT_ACI_TABLE'); // IT_ACI_TABLE | CH_TCS
            $table->string('vehicle_code', 40);
            $table->string('make_model', 160);
            $table->string('fuel', 20)->nullable();
            $table->string('vehicle_class', 40)->nullable();
            $table->unsignedInteger('annual_km_bracket')->default(15000);
            $table->decimal('rate_per_km', 10, 4);
            $table->string('currency', 3)->default('EUR');
            $table->string('rate_period', 20); // e.g. 2028-H1
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'source', 'vehicle_code'], 'exm_vehicle_rates_lookup_ix');
            $table->unique(['tenant_id', 'source', 'vehicle_code', 'annual_km_bracket', 'valid_from'], 'exm_vehicle_rates_uq');
        });
    }

    private function cardAccounts(): void
    {
        if (Schema::hasTable('exm_card_accounts')) {
            return;
        }

        Schema::create('exm_card_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('company_id')->nullable()->index();
            $table->uuid('employee_id')->nullable()->index();
            $table->string('holder_name', 160);
            $table->string('card_last4', 4);
            $table->string('issuer', 120)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->boolean('is_active')->default(true);
            $this->audit($table);

            $table->unique(['tenant_id', 'card_last4', 'holder_name'], 'exm_card_accounts_uq');
        });
    }

    private function reports(): void
    {
        if (Schema::hasTable('exm_reports')) {
            return;
        }

        Schema::create('exm_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('company_id')->nullable()->index(); // legal entity
            $table->string('report_number', 30);
            $table->string('title', 200)->nullable();
            $table->uuid('employee_id')->nullable()->index();
            $table->uuid('user_id')->index();
            $table->uuid('cost_center_id')->nullable()->index();
            $table->string('jurisdiction', 2)->default('IT');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('currency', 3)->default('EUR');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('approved_amount', 15, 2)->default(0);
            $table->decimal('reimbursable_amount', 15, 2)->default(0);
            $table->decimal('non_reimbursable_amount', 15, 2)->default(0);
            $table->decimal('taxable_amount', 15, 2)->default(0);
            $table->string('status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reimbursed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'company_id', 'report_number'], 'exm_reports_number_uq');
            $table->index(['tenant_id', 'status'], 'exm_reports_status_ix');
            $table->index(['tenant_id', 'employee_id', 'period_start'], 'exm_reports_employee_ix');
        });
    }

    private function receipts(): void
    {
        if (Schema::hasTable('exm_receipts')) {
            return;
        }

        Schema::create('exm_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('document_id')->nullable()->index(); // Mod 007 document, when the document store is the DMS
            $table->string('sha256', 64);
            $table->string('disk', 40)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('ocr_provider', 40)->nullable();
            $table->decimal('ocr_confidence', 5, 4)->nullable();
            $table->json('ocr_payload')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'sha256'], 'exm_receipts_sha_ix');
        });
    }

    private function cardTransactions(): void
    {
        if (Schema::hasTable('exm_card_transactions')) {
            return;
        }

        Schema::create('exm_card_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('card_account_id')->constrained('exm_card_accounts')->cascadeOnDelete();
            $table->string('external_id', 80)->nullable();
            $table->string('merchant_name', 200);
            $table->date('transaction_date');
            $table->date('posting_date')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('status', 20)->default('unmatched');
            $table->decimal('matched_amount', 15, 2)->default(0);
            $this->audit($table);

            $table->index(['tenant_id', 'status'], 'exm_card_tx_status_ix');
            $table->unique(['tenant_id', 'card_account_id', 'external_id'], 'exm_card_tx_external_uq');
        });
    }

    private function lines(): void
    {
        if (Schema::hasTable('exm_lines')) {
            return;
        }

        Schema::create('exm_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('expense_report_id')->constrained('exm_reports')->cascadeOnDelete();
            $table->date('expense_date');
            $table->foreignUuid('category_id')->constrained('exm_categories')->restrictOnDelete();
            $table->string('merchant_name', 200);
            $table->string('merchant_tax_id', 40)->nullable();
            $table->string('description', 500)->nullable();
            $table->decimal('original_amount', 15, 2);
            $table->string('original_currency', 3);
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->date('exchange_rate_date')->nullable();
            $table->decimal('amount_in_base_currency', 15, 2);
            $table->string('vat_code_id', 20)->nullable();
            $table->decimal('vat_rate', 7, 4)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('vat_recoverable_amount', 15, 2)->default(0);
            $table->string('payment_method', 30)->default('employee_out_of_pocket');
            $table->foreignUuid('corporate_card_transaction_id')->nullable()->constrained('exm_card_transactions')->nullOnDelete();
            $table->boolean('is_traceable_payment')->default(true);
            $table->boolean('is_taxable_benefit')->default(false);
            $table->boolean('is_reimbursable')->default(true);
            $table->json('policy_violations')->nullable();
            $table->uuid('project_id')->nullable()->index();
            $table->boolean('billable_to_customer')->default(false);
            $table->foreignUuid('receipt_id')->nullable()->constrained('exm_receipts')->nullOnDelete();
            $table->uuid('receipt_document_id')->nullable();
            $table->string('receipt_sha256', 64)->nullable();
            $table->string('normalized_merchant', 200)->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'receipt_sha256'], 'exm_lines_sha_ix');
            $table->index(['tenant_id', 'normalized_merchant', 'expense_date', 'amount_in_base_currency'], 'exm_lines_dup_ix');
            $table->index(['tenant_id', 'expense_report_id'], 'exm_lines_report_ix');
        });
    }

    private function mileageClaims(): void
    {
        if (Schema::hasTable('exm_mileage_claims')) {
            return;
        }

        Schema::create('exm_mileage_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('expense_line_id')->constrained('exm_lines')->cascadeOnDelete();
            $table->string('origin_address', 300);
            $table->string('destination_address', 300);
            $table->json('waypoint_addresses')->nullable();
            $table->decimal('distance_km', 10, 2);
            $table->boolean('is_round_trip')->default(false);
            $table->uuid('vehicle_id')->nullable();
            $table->string('vehicle_make_model', 160)->nullable();
            $table->string('vehicle_class_code', 40);
            $table->decimal('rate_per_km', 10, 4);
            $table->decimal('calculated_amount', 15, 2);
            $table->string('calculation_source', 30);
            $table->string('rate_period', 20)->nullable();
            $table->date('rate_effective_date')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'expense_line_id'], 'exm_mileage_line_ix');
        });
    }

    private function perDiems(): void
    {
        if (Schema::hasTable('exm_per_diems')) {
            return;
        }

        Schema::create('exm_per_diems', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('expense_report_id')->constrained('exm_reports')->cascadeOnDelete();
            $table->string('country_code', 2);
            $table->string('destination_type', 20);
            $table->string('allowance_method', 20);
            $table->dateTime('trip_start_at');
            $table->dateTime('trip_end_at');
            $table->decimal('duration_hours', 8, 2);
            $table->decimal('days_count', 6, 2);
            $table->decimal('allowance_rate_per_day', 15, 2);
            $table->decimal('gross_allowance_amount', 15, 2);
            $table->decimal('daily_tax_free_limit', 15, 2);
            $table->decimal('tax_free_amount', 15, 2);
            $table->decimal('taxable_excess_amount', 15, 2);
            $table->decimal('meals_lodging_reductions', 15, 2)->default(0);
            $table->boolean('lodging_reimbursed')->default(false);
            $table->boolean('meals_reimbursed')->default(false);
            $table->json('warnings')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'expense_report_id'], 'exm_per_diems_report_ix');
        });
    }

    private function payouts(): void
    {
        if (Schema::hasTable('exm_payouts')) {
            return;
        }

        Schema::create('exm_payouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('expense_report_id')->constrained('exm_reports')->cascadeOnDelete();
            $table->string('channel', 20); // pain001 | payroll
            $table->string('status', 20)->default('exported');
            $table->string('message_id', 40)->nullable();
            $table->decimal('total_amount', 15, 2);
            $table->decimal('taxable_amount', 15, 2)->default(0);
            $table->decimal('exempt_amount', 15, 2)->default(0);
            $table->string('currency', 3);
            $table->date('execution_date')->nullable();
            $table->string('file_name', 160)->nullable();
            $table->string('disk', 40)->nullable();
            $table->string('path', 500)->nullable();
            $table->json('payload')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'expense_report_id'], 'exm_payouts_report_ix');
        });
    }

    private function auditLogs(): void
    {
        if (Schema::hasTable('exm_audit_logs')) {
            return;
        }

        Schema::create('exm_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('expense_report_id')->constrained('exm_reports')->cascadeOnDelete();
            $table->uuid('expense_line_id')->nullable();
            $table->string('action', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->uuid('actor_id')->nullable();
            $table->json('payload')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'expense_report_id'], 'exm_audit_report_ix');
        });
    }
};
