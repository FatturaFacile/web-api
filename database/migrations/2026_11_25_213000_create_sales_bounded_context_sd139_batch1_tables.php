<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mod 033: Customer & Contact 360° Management
        Schema::create('sbc_customer_dossiers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('parent_dossier_id')->nullable()->index();
            $table->uuid('contact_id')->nullable()->index();
            $table->string('customer_code', 40);
            $table->string('company_name', 180);
            $table->string('hierarchy_role', 32)->default('b2c_consumer');
            $table->string('country', 2)->default('CH');
            $table->string('currency', 3)->default('CHF');
            $table->string('uid_number', 32)->nullable();
            $table->string('vat_number', 32)->nullable();
            $table->string('fiscal_code', 32)->nullable();
            $table->string('sdi_code', 16)->nullable();
            $table->string('pec_email', 180)->nullable();
            $table->string('qr_iban', 34)->nullable();
            $table->json('addresses')->nullable();
            $table->json('contacts')->nullable();
            $table->decimal('credit_limit', 14, 2)->default(0);
            $table->decimal('current_exposure', 14, 2)->default(0);
            $table->decimal('overdue_balance', 14, 2)->default(0);
            $table->string('insolvency_status', 32)->default('performing');
            $table->unsignedInteger('risk_score')->default(20);
            $table->json('summary_360')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'customer_code']);
        });

        // Mod 034: Lead Management & Scoring
        Schema::create('sbc_omnichannel_leads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('duplicate_of_id')->nullable()->index();
            $table->uuid('converted_dossier_id')->nullable()->index();
            $table->string('lead_code', 40);
            $table->string('source_channel', 64)->default('web_form');
            $table->string('full_name', 160);
            $table->string('company_name', 180)->nullable();
            $table->string('email', 180)->nullable();
            $table->string('phone', 48)->nullable();
            $table->string('country', 2)->default('CH');
            $table->string('fiscal_identifier', 40)->nullable();
            $table->string('dedup_hash', 64)->index();
            $table->boolean('is_duplicate')->default(false);
            $table->unsignedInteger('demographic_score')->default(0);
            $table->unsignedInteger('behavioral_score')->default(0);
            $table->unsignedInteger('firmographic_score')->default(0);
            $table->unsignedInteger('total_score')->default(0);
            $table->string('lifecycle_stage', 32)->default('captured');
            $table->string('assigned_rep_name', 120)->nullable();
            $table->string('assigned_territory', 80)->nullable();
            $table->string('routing_rule_applied', 80)->nullable();
            $table->json('utm_metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'lead_code']);
        });

        // Mod 035: Opportunity Pipeline & Sales Forecasting
        Schema::create('sbc_opportunity_forecasts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('dossier_id')->nullable()->index();
            $table->uuid('lead_id')->nullable()->index();
            $table->string('opportunity_code', 40);
            $table->string('title', 180);
            $table->string('kanban_stage', 40)->default('prospecting');
            $table->decimal('win_probability_pct', 5, 2)->default(20.00);
            $table->decimal('gross_amount', 14, 2)->default(0);
            $table->decimal('weighted_amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('CHF');
            $table->string('forecast_category', 32)->default('pipeline');
            $table->decimal('sales_quota_target', 14, 2)->default(100000);
            $table->decimal('quota_attainment_pct', 6, 2)->default(0);
            $table->string('owner_name', 120)->nullable();
            $table->date('expected_close_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'opportunity_code']);
        });

        // Mod 036: Interactive Quotes & Proposals (CPQ + e-Signature)
        Schema::create('sbc_cpq_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('parent_quote_id')->nullable()->index();
            $table->uuid('dossier_id')->nullable()->index();
            $table->uuid('opportunity_id')->nullable()->index();
            $table->string('quote_number', 40);
            $table->unsignedInteger('version_number')->default(1);
            $table->string('title', 180);
            $table->date('valid_until')->nullable();
            $table->string('currency', 3)->default('CHF');
            $table->string('payment_terms_code', 40)->default('NET_30');
            $table->json('lines')->nullable();
            $table->decimal('total_net', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('total_gross', 14, 2)->default(0);
            $table->string('signature_standard', 32)->default('zertes_qes');
            $table->string('signature_status', 32)->default('pending');
            $table->json('signature_audit_trail')->nullable();
            $table->uuid('converted_order_id')->nullable()->index();
            $table->uuid('converted_invoice_id')->nullable()->index();
            $table->string('status', 32)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'quote_number', 'version_number']);
        });

        // Mod 037: Sales Orders & Framework Agreements
        Schema::create('sbc_framework_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('framework_order_id')->nullable()->index();
            $table->uuid('dossier_id')->nullable()->index();
            $table->uuid('quote_id')->nullable()->index();
            $table->string('order_number', 40);
            $table->string('order_kind', 32)->default('sales_order');
            $table->string('currency', 3)->default('CHF');
            $table->decimal('ceiling_amount', 14, 2)->default(0);
            $table->decimal('released_amount', 14, 2)->default(0);
            $table->decimal('remaining_ceiling', 14, 2)->default(0);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->decimal('atp_qty', 12, 3)->default(0);
            $table->decimal('ctp_qty', 12, 3)->default(0);
            $table->decimal('requested_qty', 12, 3)->default(0);
            $table->decimal('reserved_stock_qty', 12, 3)->default(0);
            $table->decimal('shipped_qty', 12, 3)->default(0);
            $table->decimal('backorder_qty', 12, 3)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('fulfillment_status', 32)->default('reserved');
            $table->json('lines')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'order_number']);
        });

        // Mod 038: Advanced Pricing, Discounts & Promotions Engine
        Schema::create('sbc_pricing_guardrails', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('rule_code', 40);
            $table->string('name', 160);
            $table->string('currency', 3)->default('CHF');
            $table->string('customer_segment', 64)->default('enterprise');
            $table->decimal('base_unit_price', 14, 4)->default(0);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->json('cascading_discounts')->nullable();
            $table->json('tier_breaks')->nullable();
            $table->string('promo_code', 40)->nullable();
            $table->decimal('promo_discount_pct', 5, 2)->default(0);
            $table->decimal('resolved_net_price', 14, 4)->default(0);
            $table->decimal('calculated_margin_pct', 6, 2)->default(0);
            $table->decimal('min_margin_pct', 6, 2)->default(15.00);
            $table->boolean('guardrail_breached')->default(false);
            $table->string('approval_status', 32)->default('auto_approved');
            $table->string('approved_by_name', 120)->nullable();
            $table->json('breach_audit_log')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'rule_code']);
        });

        // Mod 039: Recurring Billing & Subscriptions
        Schema::create('sbc_recurring_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('dossier_id')->nullable()->index();
            $table->string('contract_code', 40);
            $table->string('plan_name', 160);
            $table->string('billing_cycle', 24)->default('monthly');
            $table->string('currency', 3)->default('CHF');
            $table->decimal('base_recurring_amount', 14, 2)->default(0);
            $table->decimal('pro_rata_adjustment_amount', 14, 2)->default(0);
            $table->string('indexation_type', 24)->default('SWISS_LIK');
            $table->decimal('last_index_rate_pct', 6, 3)->default(0);
            $table->decimal('revalued_amount', 14, 2)->default(0);
            $table->decimal('mrr_amount', 14, 2)->default(0);
            $table->decimal('arr_amount', 14, 2)->default(0);
            $table->string('lifecycle_status', 32)->default('active');
            $table->string('churn_reason', 160)->nullable();
            $table->date('next_billing_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'contract_code']);
        });

        // Mod 040: DDT (Lieferschein) & Invoicing
        Schema::create('sbc_ddt_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('dossier_id')->nullable()->index();
            $table->uuid('sales_order_id')->nullable()->index();
            $table->uuid('related_document_id')->nullable()->index();
            $table->string('document_kind', 40)->default('ddt_it');
            $table->string('document_number', 40);
            $table->string('sdi_doc_type', 8)->nullable();
            $table->string('qr_bill_reference', 32)->nullable();
            $table->json('grouped_ddt_numbers')->nullable();
            $table->boolean('is_drop_shipping')->default(false);
            $table->string('drop_ship_supplier_name', 160)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('total_net', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('total_gross', 14, 2)->default(0);
            $table->date('issue_date');
            $table->string('status', 32)->default('issued');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'document_number']);
        });

        // Mod 041: Sales Commissions & Agent Management
        Schema::create('sbc_commission_statements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('statement_number', 40);
            $table->string('agent_name', 160);
            $table->string('agent_jurisdiction', 2)->default('IT');
            $table->string('accrual_basis', 24)->default('collected');
            $table->string('period_label', 24);
            $table->string('currency', 3)->default('EUR');
            $table->decimal('commissionable_base', 14, 2)->default(0);
            $table->json('tier_rules')->nullable();
            $table->decimal('split_override_pct', 5, 2)->default(100.00);
            $table->decimal('gross_commission_amount', 14, 2)->default(0);
            $table->decimal('enasarco_company_amount', 14, 2)->default(0);
            $table->decimal('enasarco_agent_withholding', 14, 2)->default(0);
            $table->decimal('firr_accrual_amount', 14, 2)->default(0);
            $table->decimal('net_payable_amount', 14, 2)->default(0);
            $table->string('status', 32)->default('calculated');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'statement_number']);
        });

        // Mod 042: Customer Self-Service Portal
        Schema::create('sbc_portal_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('dossier_id')->nullable()->index();
            $table->uuid('ddt_invoice_id')->nullable()->index();
            $table->string('transaction_code', 40);
            $table->string('transaction_type', 32)->default('online_payment');
            $table->string('payment_provider', 24)->default('twint');
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('CHF');
            $table->decimal('open_balance_after', 14, 2)->default(0);
            $table->string('qr_bill_reference', 32)->nullable();
            $table->string('payment_reference', 80)->nullable();
            $table->string('support_subject', 180)->nullable();
            $table->string('support_status', 32)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'transaction_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbc_portal_transactions');
        Schema::dropIfExists('sbc_commission_statements');
        Schema::dropIfExists('sbc_ddt_invoices');
        Schema::dropIfExists('sbc_recurring_subscriptions');
        Schema::dropIfExists('sbc_pricing_guardrails');
        Schema::dropIfExists('sbc_framework_orders');
        Schema::dropIfExists('sbc_cpq_quotes');
        Schema::dropIfExists('sbc_opportunity_forecasts');
        Schema::dropIfExists('sbc_omnichannel_leads');
        Schema::dropIfExists('sbc_customer_dossiers');
    }
};
