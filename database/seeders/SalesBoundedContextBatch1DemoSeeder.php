<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\SalesBoundedContext\Domain\Enums\CommissionAccrualBasis;
use Modules\SalesBoundedContext\Domain\Enums\CustomerHierarchyRole;
use Modules\SalesBoundedContext\Domain\Enums\ElectronicSignatureStandard;
use Modules\SalesBoundedContext\Domain\Enums\ForecastCategory;
use Modules\SalesBoundedContext\Domain\Enums\InsolvencyRiskStatus;
use Modules\SalesBoundedContext\Domain\Enums\LeadLifecycleStage;
use Modules\SalesBoundedContext\Domain\Enums\PortalPaymentProvider;
use Modules\SalesBoundedContext\Domain\ValueObjects\FiscalIdentity;
use Modules\SalesBoundedContext\Models\SbcCommissionStatement;
use Modules\SalesBoundedContext\Models\SbcCpqQuote;
use Modules\SalesBoundedContext\Models\SbcCustomerDossier;
use Modules\SalesBoundedContext\Models\SbcDdtInvoice;
use Modules\SalesBoundedContext\Models\SbcFrameworkOrder;
use Modules\SalesBoundedContext\Models\SbcOmnichannelLead;
use Modules\SalesBoundedContext\Models\SbcOpportunityForecast;
use Modules\SalesBoundedContext\Models\SbcPortalTransaction;
use Modules\SalesBoundedContext\Models\SbcPricingGuardrail;
use Modules\SalesBoundedContext\Models\SbcRecurringSubscription;

/**
 * Idempotent cross-border CH/IT demo seeder for Epic [Dominio 04] SD#139 Batch 1 (Mod 033-042).
 */
final class SalesBoundedContextBatch1DemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('created_at')->first();
        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->runWithTenant($tenant, function () use ($tenant): void {
            $tenantId = (string) $tenant->id;

            // Mod 033: CH Holding & IT Subsidiary Dossiers
            $chHolding = SbcCustomerDossier::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'customer_code' => 'CUST-CH-001'],
                [
                    'company_name' => 'Helvetia Alpine Precision AG',
                    'hierarchy_role' => CustomerHierarchyRole::Holding,
                    'country' => 'CH',
                    'currency' => 'CHF',
                    'uid_number' => 'CHE-116.281.710',
                    'qr_iban' => 'CH4431999123000889012',
                    'addresses' => [
                        ['type' => 'billing', 'street' => 'Bahnhofstrasse 42', 'city' => 'Zürich', 'postal_code' => '8001', 'country' => 'CH'],
                        ['type' => 'shipping', 'street' => 'Via Nassa 15', 'city' => 'Lugano', 'postal_code' => '6900', 'country' => 'CH'],
                    ],
                    'credit_limit' => '150000.00',
                    'current_exposure' => '42500.00',
                    'overdue_balance' => '0.00',
                    'insolvency_status' => InsolvencyRiskStatus::Performing,
                    'risk_score' => 12,
                ]
            );

            SbcCustomerDossier::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'customer_code' => 'CUST-IT-002'],
                [
                    'parent_dossier_id' => $chHolding->id,
                    'company_name' => 'Alpine Precision Italia Srl',
                    'hierarchy_role' => CustomerHierarchyRole::Subsidiary,
                    'country' => 'IT',
                    'currency' => 'EUR',
                    'vat_number' => 'IT08923410962',
                    'fiscal_code' => '08923410962',
                    'sdi_code' => 'M5UXCR1',
                    'pec_email' => 'amministrazione@alpineprecision.pec.it',
                    'addresses' => [
                        ['type' => 'billing', 'street' => 'Via Tortona 31', 'city' => 'Milano', 'postal_code' => '20144', 'country' => 'IT'],
                    ],
                    'credit_limit' => '80000.00',
                    'current_exposure' => '18000.00',
                    'overdue_balance' => '0.00',
                    'insolvency_status' => InsolvencyRiskStatus::Performing,
                    'risk_score' => 18,
                ]
            );

            // Mod 034: Lead
            $lead = SbcOmnichannelLead::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'lead_code' => 'LEAD-SD139-01'],
                [
                    'source_channel' => 'quote_request',
                    'full_name' => 'Matteo Bernasconi',
                    'company_name' => 'Ticino MedTech SA',
                    'email' => 'm.bernasconi@ticinomedtech.ch',
                    'country' => 'CH',
                    'fiscal_identifier' => 'CHE-116.281.710',
                    'dedup_hash' => hash('sha256', $tenantId.'|m.bernasconi@ticinomedtech.ch'),
                    'is_duplicate' => false,
                    'demographic_score' => 25,
                    'behavioral_score' => 40,
                    'firmographic_score' => 25,
                    'total_score' => 90,
                    'lifecycle_stage' => LeadLifecycleStage::Sql,
                    'assigned_rep_name' => 'Lukas Meier',
                    'assigned_territory' => 'CH-TI-ZURICH',
                    'routing_rule_applied' => 'AUTO_TERRITORY_CH',
                ]
            );

            // Mod 035: Opportunity
            $opp = SbcOpportunityForecast::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'opportunity_code' => 'OPP-SD139-01'],
                [
                    'dossier_id' => $chHolding->id,
                    'lead_id' => $lead->id,
                    'title' => 'Enterprise Cross-Border Rollout (CH + IT)',
                    'kanban_stage' => 'negotiation',
                    'win_probability_pct' => '75.00',
                    'gross_amount' => '120000.00',
                    'weighted_amount' => '90000.00',
                    'currency' => 'CHF',
                    'forecast_category' => ForecastCategory::Commit,
                    'sales_quota_target' => '250000.00',
                    'quota_attainment_pct' => '36.00',
                    'owner_name' => 'Lukas Meier',
                    'expected_close_date' => now()->addDays(21)->toDateString(),
                ]
            );

            // Mod 036: CPQ Quote
            SbcCpqQuote::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'quote_number' => 'CPQ-2026-001', 'version_number' => 1],
                [
                    'dossier_id' => $chHolding->id,
                    'opportunity_id' => $opp->id,
                    'title' => 'Enterprise ERP + Hardware Bundle',
                    'valid_until' => now()->addDays(30)->toDateString(),
                    'currency' => 'CHF',
                    'payment_terms_code' => 'NET_30',
                    'lines' => [
                        ['description' => 'Core ERP Suite (50 Seats)', 'quantity' => 1, 'unit_price' => 45000, 'discount_pct' => 10, 'tax_rate' => 8.1, 'is_optional' => false, 'is_selected' => true, 'is_bundle' => true],
                        ['description' => 'ZertES Signing Add-on', 'quantity' => 1, 'unit_price' => 5000, 'discount_pct' => 0, 'tax_rate' => 8.1, 'is_optional' => true, 'is_selected' => true, 'is_bundle' => false],
                    ],
                    'total_net' => '45500.00',
                    'total_tax' => '3685.50',
                    'total_gross' => '49185.50',
                    'signature_standard' => ElectronicSignatureStandard::ZertesQes,
                    'signature_status' => 'signed',
                    'signature_audit_trail' => [['standard' => 'zertes_qes', 'signer_name' => 'H. Keller', 'signed_at' => now()->toIso8601String()]],
                    'status' => 'accepted',
                ]
            );

            // Mod 037: Framework Agreement + Call-off Order
            $fw = SbcFrameworkOrder::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'order_number' => 'FA-2026-CH01'],
                [
                    'dossier_id' => $chHolding->id,
                    'order_kind' => 'framework_agreement',
                    'currency' => 'CHF',
                    'ceiling_amount' => '200000.00',
                    'released_amount' => '35000.00',
                    'remaining_ceiling' => '165000.00',
                    'valid_from' => now()->subMonth()->toDateString(),
                    'valid_to' => now()->addMonths(11)->toDateString(),
                    'fulfillment_status' => 'active',
                ]
            );

            SbcFrameworkOrder::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'order_number' => 'SO-2026-CALL01'],
                [
                    'framework_order_id' => $fw->id,
                    'dossier_id' => $chHolding->id,
                    'order_kind' => 'sales_order',
                    'currency' => 'CHF',
                    'atp_qty' => '20.000',
                    'ctp_qty' => '10.000',
                    'requested_qty' => '25.000',
                    'reserved_stock_qty' => '25.000',
                    'shipped_qty' => '20.000',
                    'backorder_qty' => '5.000',
                    'total_amount' => '35000.00',
                    'fulfillment_status' => 'partial_shipped',
                ]
            );

            // Mod 038: Pricing & Margin Guardrail
            SbcPricingGuardrail::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'rule_code' => 'PRC-GDO-2026'],
                [
                    'name' => 'GDO Enterprise Cascading 10+5% + Promo',
                    'currency' => 'CHF',
                    'customer_segment' => 'enterprise',
                    'base_unit_price' => '1000.0000',
                    'unit_cost' => '740.0000',
                    'cascading_discounts' => [10, 5],
                    'promo_code' => 'WINTER26',
                    'promo_discount_pct' => '3.00',
                    'resolved_net_price' => '829.3500',
                    'calculated_margin_pct' => '10.77',
                    'min_margin_pct' => '15.00',
                    'guardrail_breached' => true,
                    'approval_status' => 'pending_manager_approval',
                ]
            );

            // Mod 039: Recurring Subscription
            SbcRecurringSubscription::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'contract_code' => 'SUB-CH-2026-01'],
                [
                    'dossier_id' => $chHolding->id,
                    'plan_name' => 'FatturaFacile Enterprise Cloud',
                    'billing_cycle' => 'monthly',
                    'currency' => 'CHF',
                    'base_recurring_amount' => '2400.00',
                    'pro_rata_adjustment_amount' => '1200.00',
                    'indexation_type' => 'SWISS_LIK',
                    'last_index_rate_pct' => '1.400',
                    'revalued_amount' => '2433.60',
                    'mrr_amount' => '2433.60',
                    'arr_amount' => '29203.20',
                    'lifecycle_status' => 'active',
                    'next_billing_date' => now()->addMonth()->toDateString(),
                ]
            );

            // Mod 040: DDT / Lieferschein & Deferred Summary Invoice
            SbcDdtInvoice::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'document_number' => 'FD-TD24-2026-001'],
                [
                    'dossier_id' => $chHolding->id,
                    'document_kind' => 'deferred_summary_invoice',
                    'sdi_doc_type' => 'TD24',
                    'grouped_ddt_numbers' => ['DDT-2026-101', 'DDT-2026-102'],
                    'is_drop_shipping' => false,
                    'currency' => 'EUR',
                    'total_net' => '12500.00',
                    'total_tax' => '2750.00',
                    'total_gross' => '15250.00',
                    'issue_date' => now()->toDateString(),
                    'status' => 'issued',
                ]
            );

            // Mod 041: Agent Commission Statement with Enasarco
            SbcCommissionStatement::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'statement_number' => 'EC-2026-11-IT01'],
                [
                    'agent_name' => 'Marco Rossi (Agente Monomandatario)',
                    'agent_jurisdiction' => 'IT',
                    'accrual_basis' => CommissionAccrualBasis::Collected,
                    'period_label' => '2026-11',
                    'currency' => 'EUR',
                    'commissionable_base' => '100000.00',
                    'tier_rules' => [['up_to' => 50000, 'rate_pct' => 5], ['up_to' => 150000, 'rate_pct' => 7]],
                    'split_override_pct' => '100.00',
                    'gross_commission_amount' => '6000.00',
                    'enasarco_company_amount' => '510.00',
                    'enasarco_agent_withholding' => '510.00',
                    'firr_accrual_amount' => '240.00',
                    'net_payable_amount' => '5490.00',
                    'status' => 'calculated',
                ]
            );

            // Mod 042: Portal Transaction (TWINT + QR-Bill)
            SbcPortalTransaction::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'transaction_code' => 'PRT-TWINT-2026-01'],
                [
                    'dossier_id' => $chHolding->id,
                    'transaction_type' => 'online_payment',
                    'payment_provider' => PortalPaymentProvider::Twint,
                    'amount' => '2433.60',
                    'currency' => 'CHF',
                    'open_balance_after' => '40066.40',
                    'qr_bill_reference' => FiscalIdentity::buildSwissQrReference('20261125001'),
                    'payment_reference' => 'TWINT-CH-99887766',
                    'settled_at' => now(),
                ]
            );
        });
    }
}
