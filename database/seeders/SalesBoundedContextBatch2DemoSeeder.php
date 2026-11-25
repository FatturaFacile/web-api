<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\SalesBoundedContext\Domain\Enums\BStockConditionGrade;
use Modules\SalesBoundedContext\Domain\Enums\NpsRespondentCategory;
use Modules\SalesBoundedContext\Domain\Enums\RfmSegmentCode;
use Modules\SalesBoundedContext\Domain\Enums\TenderProcurementFramework;
use Modules\SalesBoundedContext\Domain\Enums\WelfareFiscalRegime;
use Modules\SalesBoundedContext\Models\SbcCorporateWelfareConvention;
use Modules\SalesBoundedContext\Models\SbcCpqConfiguration;
use Modules\SalesBoundedContext\Models\SbcCustomerDossier;
use Modules\SalesBoundedContext\Models\SbcDdtInvoice;
use Modules\SalesBoundedContext\Models\SbcFranchisingAffiliate;
use Modules\SalesBoundedContext\Models\SbcGdoRebateAgreement;
use Modules\SalesBoundedContext\Models\SbcMarketingCampaign;
use Modules\SalesBoundedContext\Models\SbcPostDeliverySurvey;
use Modules\SalesBoundedContext\Models\SbcPublicPrivateTender;
use Modules\SalesBoundedContext\Models\SbcReverseLogisticsReturn;
use Modules\SalesBoundedContext\Models\SbcVanSellingRoute;

/**
 * Idempotent cross-border CH/IT demo seeder for Epic [Dominio 04] SD#139 Batch 2 (Mod 155-358).
 */
final class SalesBoundedContextBatch2DemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('created_at')->first();
        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->runWithTenant($tenant, function () use ($tenant): void {
            $tenantId = (string) $tenant->id;
            $dossier = SbcCustomerDossier::query()->where('tenant_id', $tenantId)->first();
            $invoice = SbcDdtInvoice::query()->where('tenant_id', $tenantId)->first();

            // Mod 155: RFM Marketing Campaign
            SbcMarketingCampaign::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'campaign_code' => 'MKT-RFM-2026-01'],
                [
                    'dossier_id' => $dossier?->id,
                    'name' => 'Q4 CH/IT Enterprise Renewal DEM',
                    'channel' => 'dem_email',
                    'rfm_recency_score' => 5,
                    'rfm_frequency_score' => 4,
                    'rfm_monetary_score' => 5,
                    'rfm_segment' => RfmSegmentCode::Champions,
                    'journey_definition' => [['step' => 1, 'action' => 'rfm_segment_entry', 'segment' => 'champions']],
                    'exclude_insolvent_overdue' => true,
                    'excluded_insolvent_count' => 2,
                    'gdpr_nlpd_lcsl_consent_verified' => true,
                    'opted_out_suppressed_count' => 5,
                    'eligible_recipients_count' => 318,
                    'campaign_cost' => '1800.00',
                    'attributed_revenue' => '28400.00',
                    'roi_pct' => '1477.78',
                    'currency' => 'CHF',
                    'status' => 'active',
                ]
            );

            // Mod 169: Public & Private Tender (ANAC MePA & simap.ch)
            SbcPublicPrivateTender::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'tender_code' => 'TND-SIMAP-2026-01'],
                [
                    'dossier_id' => $dossier?->id,
                    'title' => 'Canton Ticino Digital ERP & E-Invoicing Lot 1',
                    'procurement_framework' => TenderProcurementFramework::SimapChLapub,
                    'contracting_authority' => 'Repubblica e Cantone Ticino',
                    'cig_code' => null,
                    'cup_code' => null,
                    'edgue_status' => 'verified',
                    'qualification_vault' => ['avs_valid' => true, 'iso_9001' => true],
                    'admin_envelope_status' => 'verified',
                    'technical_envelope_score' => '64.50',
                    'economic_envelope_score' => '27.00',
                    'total_award_score' => '91.50',
                    'bid_amount' => '320000.00',
                    'currency' => 'CHF',
                    'pipeline_stage' => 'awarded',
                ]
            );

            // Mod 230: Technical-Commercial CPQ Configuration
            SbcCpqConfiguration::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'configuration_code' => 'CFG-BOM-2026-01'],
                [
                    'dossier_id' => $dossier?->id,
                    'product_family' => 'Swiss Precision CNC Cell',
                    'selected_options' => ['voltage' => '400V', 'chiller' => 'high_capacity', 'plc' => 'siemens_s7'],
                    'compatibility_rules_evaluated' => [['rule' => 'RULE_VOLTAGE_CHILLER_COMPAT', 'passed' => true]],
                    'has_constraint_conflict' => false,
                    'super_bom_items' => [
                        ['sku' => 'CMP-CHASSIS-400', 'qty' => 1, 'unit_cost' => 4200, 'unit_price' => 6800],
                        ['sku' => 'CMP-CHILLER-HC', 'qty' => 1, 'unit_cost' => 2800, 'unit_price' => 4500],
                    ],
                    'total_bom_cost' => '7000.00',
                    'configured_list_price' => '11300.00',
                    'net_configured_price' => '10735.00',
                    'calculated_margin_pct' => '34.79',
                    'currency' => 'CHF',
                    'status' => 'validated',
                ]
            );

            // Mod 231: GDO Framework Contract & Tiered PFA Rebate
            SbcGdoRebateAgreement::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'agreement_code' => 'GDO-PFA-2026-01'],
                [
                    'dossier_id' => $dossier?->id,
                    'gdo_chain_name' => 'Coop / Esselunga Cross-Border Retail',
                    'contract_year' => 2026,
                    'currency' => 'EUR',
                    'tier_brackets' => [['min_volume' => 100000, 'rate_pct' => 2.5], ['min_volume' => 250000, 'rate_pct' => 4.0]],
                    'sell_in_volume_amount' => '310000.00',
                    'sell_out_promo_contribution' => '4500.00',
                    'resolved_tier_rate_pct' => '4.00',
                    'annual_pfa_rebate_amount' => '16900.00',
                    'monthly_accrual_rateo' => '1408.33',
                    'accrued_months_count' => 11,
                    'total_provisioned_accrual' => '15491.63',
                    'settlement_status' => 'accruing',
                ]
            );

            // Mod 232: Reverse Logistics Return & Art. 26 Check
            SbcReverseLogisticsReturn::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'rma_number' => 'RMA-2026-001'],
                [
                    'dossier_id' => $dossier?->id,
                    'original_invoice_id' => $invoice?->id,
                    'return_reason' => 'Transit damage - Advance cross-shipping dispatched',
                    'is_advance_cross_shipping' => true,
                    'replacement_shipment_ref' => 'XSHIP-2026-881',
                    'b_stock_grade' => BStockConditionGrade::GradeBRefurbished,
                    'inventory_disposition' => 'b_stock_outlet',
                    'original_invoice_date' => now()->subDays(30)->toDateString(),
                    'days_since_invoice' => 30,
                    'art26_dpr633_72_vat_recoverable' => true,
                    'art26_compliance_note' => 'Art. 26 c.2 DPR 633/72 compliant: VAT recovery permitted (<= 365 days).',
                    'return_net_amount' => '1200.00',
                    'return_vat_amount' => '264.00',
                    'credit_note_gross_amount' => '1464.00',
                    'currency' => 'EUR',
                    'status' => 'inspected_graded',
                ]
            );

            // Mod 233: Van Selling & End-of-Route Reconciliation
            SbcVanSellingRoute::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'route_code' => 'VAN-TI-2026-01'],
                [
                    'driver_agent_name' => 'Matteo Fontana',
                    'vehicle_plate' => 'TI-448291',
                    'traveling_warehouse_code' => 'WH-VAN-TI01',
                    'document_series_sezionale' => 'VAN-TI-01',
                    'loaded_stock_qty' => '120.000',
                    'sold_stock_qty' => '95.000',
                    'returned_unsold_qty' => '25.000',
                    'stock_variance_qty' => '0.000',
                    'expected_cash_amount' => '2450.00',
                    'collected_cash_amount' => '2450.00',
                    'electronic_payment_amount' => '1890.00',
                    'cash_discrepancy_amount' => '0.00',
                    'currency' => 'CHF',
                    'bluetooth_escpos_payload' => "FATTURAFACILE VAN RECEIPT\nROUTE: VAN-TI-2026-01",
                    'offline_sync_batch_id' => 'SYNC-20261125-01',
                    'reconciliation_status' => 'reconciled_balanced',
                ]
            );

            // Mod 244: Franchising Affiliate & Royalty
            SbcFranchisingAffiliate::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'affiliate_code' => 'FRN-ZUR-01'],
                [
                    'dossier_id' => $dossier?->id,
                    'franchisor_brand' => 'Alpine Retail Group',
                    'store_name' => 'Zurich Flagship Store',
                    'country' => 'CH',
                    'currency' => 'CHF',
                    'sell_out_turnover' => '140000.00',
                    'royalty_rate_pct' => '5.00',
                    'marketing_fund_rate_pct' => '2.00',
                    'calculated_royalty_amount' => '7000.00',
                    'calculated_marketing_fund_amount' => '2800.00',
                    'total_fee_invoiceable' => '9800.00',
                    'pushed_price_list_version' => 'PL-FRANCHISE-2026-V2',
                    'network_avg_turnover' => '100000.00',
                    'benchmark_index_pct' => '140.00',
                    'status' => 'royalties_invoiced',
                ]
            );

            // Mod 245: Post-Delivery Survey (NPS / CSAT / ISO 9001)
            SbcPostDeliverySurvey::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'survey_code' => 'SRV-ISO-2026-01'],
                [
                    'dossier_id' => $dossier?->id,
                    'ddt_invoice_id' => $invoice?->id,
                    'trigger_event' => 'post_delivery_ddt',
                    'nps_score' => 9,
                    'csat_score' => 5,
                    'ces_score' => 6,
                    'nps_classification' => NpsRespondentCategory::Promoter,
                    'detractor_recovery_alert_triggered' => false,
                    'recovery_resolution_status' => 'none',
                    'iso9001_clause_ref' => 'ISO-9001:2015-9.1.2',
                    'iso9001_satisfaction_index' => '95.00',
                    'verbatim_feedback' => 'On-time Swiss QR-Bill and DDT delivery.',
                ]
            );

            // Mod 358: Corporate Welfare & CRAL Split Billing
            SbcCorporateWelfareConvention::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'convention_code' => 'WLF-CRAL-2026-01'],
                [
                    'partner_dossier_id' => $dossier?->id,
                    'partner_company_name' => 'Alpine Precision Italia Srl',
                    'corporate_email_domain' => 'alpineprecision.it',
                    'employee_email' => 'giulia.verdi@alpineprecision.it',
                    'cral_badge_number' => 'CRAL-MI-7742',
                    'domain_and_cral_verified' => true,
                    'welfare_regime' => WelfareFiscalRegime::ItArt51Tuir,
                    'voucher_provider' => 'Edenred Art.51',
                    'gross_basket_amount' => '500.00',
                    'convention_discount_pct' => '10.00',
                    'discounted_net_amount' => '450.00',
                    'employer_welfare_quota_amount' => '270.00',
                    'employee_copay_quota_amount' => '180.00',
                    'regime_annual_limit' => '1000.00',
                    'within_statutory_exemption' => true,
                    'currency' => 'EUR',
                    'status' => 'split_billed',
                ]
            );
        });
    }
}
