<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mod 155 (#401): Marketing Automation, DEM Newsletter, RFM Segmentation & Omnichannel Campaigns
        Schema::create('sbc_marketing_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('dossier_id')->nullable()->index();
            $table->string('campaign_code', 40);
            $table->string('name', 180);
            $table->string('channel', 32)->default('dem_email');
            $table->unsignedTinyInteger('rfm_recency_score')->default(5);
            $table->unsignedTinyInteger('rfm_frequency_score')->default(4);
            $table->unsignedTinyInteger('rfm_monetary_score')->default(5);
            $table->string('rfm_segment', 32)->default('champions');
            $table->json('journey_definition')->nullable();
            $table->boolean('exclude_insolvent_overdue')->default(true);
            $table->unsignedInteger('excluded_insolvent_count')->default(0);
            $table->boolean('gdpr_nlpd_lcsl_consent_verified')->default(true);
            $table->unsignedInteger('opted_out_suppressed_count')->default(0);
            $table->unsignedInteger('eligible_recipients_count')->default(0);
            $table->decimal('campaign_cost', 14, 2)->default(0);
            $table->decimal('attributed_revenue', 14, 2)->default(0);
            $table->decimal('roi_pct', 8, 2)->default(0);
            $table->string('currency', 3)->default('CHF');
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'campaign_code']);
        });

        // Mod 169 (#415): Public & Private Tenders (simap.ch LAPub CH / ANAC MePA D.Lgs. 36/2023 IT)
        Schema::create('sbc_public_private_tenders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('dossier_id')->nullable()->index();
            $table->uuid('converted_order_id')->nullable()->index();
            $table->string('tender_code', 40);
            $table->string('title', 180);
            $table->string('procurement_framework', 40)->default('simap_ch_lapub');
            $table->string('contracting_authority', 180);
            $table->string('cig_code', 24)->nullable();
            $table->string('cup_code', 24)->nullable();
            $table->string('soa_category', 32)->nullable();
            $table->string('edgue_status', 32)->default('verified');
            $table->string('fvoe_passoe_ref', 64)->nullable();
            $table->json('qualification_vault')->nullable();
            $table->string('admin_envelope_status', 32)->default('sealed');
            $table->decimal('technical_envelope_score', 6, 2)->default(0);
            $table->decimal('economic_envelope_score', 6, 2)->default(0);
            $table->decimal('total_award_score', 6, 2)->default(0);
            $table->decimal('bid_amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('CHF');
            $table->string('pipeline_stage', 32)->default('submitted');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'tender_code']);
        });

        // Mod 230 (#476): Technical-Commercial CPQ Configurator
        Schema::create('sbc_cpq_configurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('dossier_id')->nullable()->index();
            $table->uuid('quote_id')->nullable()->index();
            $table->string('configuration_code', 40);
            $table->string('product_family', 120);
            $table->json('selected_options')->nullable();
            $table->json('compatibility_rules_evaluated')->nullable();
            $table->boolean('has_constraint_conflict')->default(false);
            $table->json('conflict_messages')->nullable();
            $table->json('super_bom_items')->nullable();
            $table->json('routing_operations')->nullable();
            $table->decimal('total_bom_cost', 14, 2)->default(0);
            $table->decimal('configured_list_price', 14, 2)->default(0);
            $table->decimal('net_configured_price', 14, 2)->default(0);
            $table->decimal('calculated_margin_pct', 6, 2)->default(0);
            $table->string('currency', 3)->default('CHF');
            $table->string('status', 32)->default('validated');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'configuration_code']);
        });

        // Mod 231 (#477): GDO Framework Contracts, Tiered Year-End Rebates (PFA), Sell-In/Sell-Out & Accruals
        Schema::create('sbc_gdo_rebate_agreements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('dossier_id')->nullable()->index();
            $table->uuid('settlement_credit_note_id')->nullable()->index();
            $table->string('agreement_code', 40);
            $table->string('gdo_chain_name', 160);
            $table->unsignedSmallInteger('contract_year')->default(2026);
            $table->string('currency', 3)->default('EUR');
            $table->json('tier_brackets')->nullable();
            $table->decimal('sell_in_volume_amount', 14, 2)->default(0);
            $table->decimal('sell_out_promo_contribution', 14, 2)->default(0);
            $table->decimal('resolved_tier_rate_pct', 5, 2)->default(0);
            $table->decimal('annual_pfa_rebate_amount', 14, 2)->default(0);
            $table->decimal('monthly_accrual_rateo', 14, 2)->default(0);
            $table->unsignedTinyInteger('accrued_months_count')->default(1);
            $table->decimal('total_provisioned_accrual', 14, 2)->default(0);
            $table->string('settlement_status', 32)->default('accruing');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'agreement_code']);
        });

        // Mod 232 (#478): Advanced Returns (Reverse Logistics), Cross-Shipping, B-Stock Grading & Art. 26 Credit Notes
        Schema::create('sbc_reverse_logistics_returns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('dossier_id')->nullable()->index();
            $table->uuid('original_invoice_id')->nullable()->index();
            $table->uuid('credit_note_document_id')->nullable()->index();
            $table->string('rma_number', 40);
            $table->string('return_reason', 120);
            $table->boolean('is_advance_cross_shipping')->default(false);
            $table->string('replacement_shipment_ref', 64)->nullable();
            $table->string('b_stock_grade', 32)->default('grade_b_refurbished');
            $table->string('inventory_disposition', 32)->default('b_stock_outlet');
            $table->date('original_invoice_date')->nullable();
            $table->unsignedInteger('days_since_invoice')->default(0);
            $table->boolean('art26_dpr633_72_vat_recoverable')->default(true);
            $table->string('art26_compliance_note', 180)->nullable();
            $table->decimal('return_net_amount', 14, 2)->default(0);
            $table->decimal('return_vat_amount', 14, 2)->default(0);
            $table->decimal('credit_note_gross_amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->string('status', 32)->default('rma_authorized');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'rma_number']);
        });

        // Mod 233 (#479): Van Selling & Offline Field Sales
        Schema::create('sbc_van_selling_routes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('route_code', 40);
            $table->string('driver_agent_name', 120);
            $table->string('vehicle_plate', 32);
            $table->string('traveling_warehouse_code', 40);
            $table->string('document_series_sezionale', 24);
            $table->decimal('loaded_stock_qty', 12, 3)->default(0);
            $table->decimal('sold_stock_qty', 12, 3)->default(0);
            $table->decimal('returned_unsold_qty', 12, 3)->default(0);
            $table->decimal('stock_variance_qty', 12, 3)->default(0);
            $table->decimal('expected_cash_amount', 14, 2)->default(0);
            $table->decimal('collected_cash_amount', 14, 2)->default(0);
            $table->decimal('electronic_payment_amount', 14, 2)->default(0);
            $table->decimal('cash_discrepancy_amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('CHF');
            $table->text('bluetooth_escpos_payload')->nullable();
            $table->string('offline_sync_batch_id', 64)->nullable();
            $table->string('reconciliation_status', 32)->default('reconciled_balanced');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'route_code']);
        });

        // Mod 244 (#490): Franchising Portal & Affiliate Network
        Schema::create('sbc_franchising_affiliates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('dossier_id')->nullable()->index();
            $table->string('affiliate_code', 40);
            $table->string('franchisor_brand', 120);
            $table->string('store_name', 160);
            $table->string('country', 2)->default('CH');
            $table->string('currency', 3)->default('CHF');
            $table->decimal('sell_out_turnover', 14, 2)->default(0);
            $table->decimal('royalty_rate_pct', 5, 2)->default(5.00);
            $table->decimal('marketing_fund_rate_pct', 5, 2)->default(2.00);
            $table->decimal('calculated_royalty_amount', 14, 2)->default(0);
            $table->decimal('calculated_marketing_fund_amount', 14, 2)->default(0);
            $table->decimal('total_fee_invoiceable', 14, 2)->default(0);
            $table->string('pushed_price_list_version', 40)->default('PL-FRANCHISE-2026-V1');
            $table->decimal('network_avg_turnover', 14, 2)->default(100000);
            $table->decimal('benchmark_index_pct', 6, 2)->default(100.00);
            $table->string('status', 32)->default('royalties_invoiced');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'affiliate_code']);
        });

        // Mod 245 (#491): Automated Post-Ticket / Post-Delivery Surveys (NPS / CSAT / CES)
        Schema::create('sbc_post_delivery_surveys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('dossier_id')->nullable()->index();
            $table->uuid('ddt_invoice_id')->nullable()->index();
            $table->string('survey_code', 40);
            $table->string('trigger_event', 40)->default('post_delivery_ddt');
            $table->unsignedTinyInteger('nps_score')->default(9);
            $table->unsignedTinyInteger('csat_score')->default(5);
            $table->unsignedTinyInteger('ces_score')->default(6);
            $table->string('nps_classification', 24)->default('promoter');
            $table->boolean('detractor_recovery_alert_triggered')->default(false);
            $table->string('recovery_action_owner', 120)->nullable();
            $table->string('recovery_resolution_status', 32)->default('none');
            $table->string('iso9001_clause_ref', 40)->default('ISO-9001:2015-9.1.2');
            $table->decimal('iso9001_satisfaction_index', 5, 2)->default(90.00);
            $table->text('verbatim_feedback')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'survey_code']);
        });

        // Mod 358 (#604): Corporate Conventions, Partner Employee Discounts & B2B Welfare Cards
        Schema::create('sbc_corporate_welfare_conventions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('partner_dossier_id')->nullable()->index();
            $table->string('convention_code', 40);
            $table->string('partner_company_name', 160);
            $table->string('corporate_email_domain', 120);
            $table->string('employee_email', 180);
            $table->string('cral_badge_number', 64);
            $table->boolean('domain_and_cral_verified')->default(true);
            $table->string('welfare_regime', 32)->default('it_art51_tuir');
            $table->string('voucher_provider', 64)->default('Edenred / Reka');
            $table->decimal('gross_basket_amount', 14, 2)->default(0);
            $table->decimal('convention_discount_pct', 5, 2)->default(10.00);
            $table->decimal('discounted_net_amount', 14, 2)->default(0);
            $table->decimal('employer_welfare_quota_amount', 14, 2)->default(0);
            $table->decimal('employee_copay_quota_amount', 14, 2)->default(0);
            $table->decimal('regime_annual_limit', 14, 2)->default(1000.00);
            $table->boolean('within_statutory_exemption')->default(true);
            $table->string('currency', 3)->default('EUR');
            $table->string('status', 32)->default('split_billed');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'convention_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbc_corporate_welfare_conventions');
        Schema::dropIfExists('sbc_post_delivery_surveys');
        Schema::dropIfExists('sbc_franchising_affiliates');
        Schema::dropIfExists('sbc_van_selling_routes');
        Schema::dropIfExists('sbc_reverse_logistics_returns');
        Schema::dropIfExists('sbc_gdo_rebate_agreements');
        Schema::dropIfExists('sbc_cpq_configurations');
        Schema::dropIfExists('sbc_public_private_tenders');
        Schema::dropIfExists('sbc_marketing_campaigns');
    }
};
