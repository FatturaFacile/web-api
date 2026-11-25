<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Translations\AcademyFrontendTranslations;
use Database\Seeders\Translations\AccountsPayableFrontendTranslations;
use Database\Seeders\Translations\AccrualsDeferralsFrontendTranslations;
use Database\Seeders\Translations\AgentCommissionsFrontendTranslations;
use Database\Seeders\Translations\AiCognitiveFrontendTranslations;
use Database\Seeders\Translations\AiForecastingFrontendTranslations;
use Database\Seeders\Translations\AnalyticalAccountingFrontendTranslations;
use Database\Seeders\Translations\ArtisanalGelatoFrontendTranslations;
use Database\Seeders\Translations\AuditTrailFrontendTranslations;
use Database\Seeders\Translations\BomManagementFrontendTranslations;
use Database\Seeders\Translations\CopilotFrontendTranslations;
use Database\Seeders\Translations\CorporateConventionsFrontendTranslations;
use Database\Seeders\Translations\CpqConfiguratorFrontendTranslations;
use Database\Seeders\Translations\CpqQuotesFrontendTranslations;
use Database\Seeders\Translations\Customer360FrontendTranslations;
use Database\Seeders\Translations\CustomerReturnsReverseLogisticsFrontendTranslations;
use Database\Seeders\Translations\CustomerSatisfactionFrontendTranslations;
use Database\Seeders\Translations\DataSovereigntyFrontendTranslations;
use Database\Seeders\Translations\DebtCollectionFrontendTranslations;
use Database\Seeders\Translations\ElectronicSignatureFrontendTranslations;
use Database\Seeders\Translations\ElectronicSignatureNotificationTranslations;
use Database\Seeders\Translations\EnglishFrontendTranslations;
use Database\Seeders\Translations\ExecutiveDssFrontendTranslations;
use Database\Seeders\Translations\ExpenseManagementFrontendTranslations;
use Database\Seeders\Translations\FieldServiceManagementFrontendTranslations;
use Database\Seeders\Translations\FixedAssetsFrontendTranslations;
use Database\Seeders\Translations\FranchiseNetworkFrontendTranslations;
use Database\Seeders\Translations\FrenchFrontendTranslations;
use Database\Seeders\Translations\GapViewsFrontendTranslations;
use Database\Seeders\Translations\GdoTradeTermsFrontendTranslations;
use Database\Seeders\Translations\GeneralLedgerPrimaNotaFrontendTranslations;
use Database\Seeders\Translations\GermanFrontendTranslations;
use Database\Seeders\Translations\HelpdeskSlaFrontendTranslations;
use Database\Seeders\Translations\HrAtsFrontendTranslations;
use Database\Seeders\Translations\HseFrontendTranslations;
use Database\Seeders\Translations\IdentityFrontendTranslations;
use Database\Seeders\Translations\IndustrialLaundryFrontendTranslations;
use Database\Seeders\Translations\IntercompanyFrontendTranslations;
use Database\Seeders\Translations\InventoryCountingFrontendTranslations;
use Database\Seeders\Translations\InvoiceMatchingFrontendTranslations;
use Database\Seeders\Translations\ItalianFrontendTranslations;
use Database\Seeders\Translations\ItalianPayrollFrontendTranslations;
use Database\Seeders\Translations\ItalianVatFrontendTranslations;
use Database\Seeders\Translations\ItalianVatWorkbenchFrontendTranslations;
use Database\Seeders\Translations\KpiDashboardsFrontendTranslations;
use Database\Seeders\Translations\LeadFrontendTranslations;
use Database\Seeders\Translations\LeaveManagementFrontendTranslations;
use Database\Seeders\Translations\LiquidityPlanningFrontendTranslations;
use Database\Seeders\Translations\LiquidityPlanningTranslations;
use Database\Seeders\Translations\MainFrontendTranslations;
use Database\Seeders\Translations\ManufacturingCommandCenterFrontendTranslations;
use Database\Seeders\Translations\ManufacturingProductionFrontendTranslations;
use Database\Seeders\Translations\MarketingAutomationFrontendTranslations;
use Database\Seeders\Translations\MesOperatorBoardFrontendTranslations;
use Database\Seeders\Translations\MobileFeatureTranslations;
use Database\Seeders\Translations\MobileFrontendTranslations;
use Database\Seeders\Translations\MrpPlanningFrontendTranslations;
use Database\Seeders\Translations\OcrInboxFrontendTranslations;
use Database\Seeders\Translations\PassengerTransportFrontendTranslations;
use Database\Seeders\Translations\PayablesFrontendTranslations;
use Database\Seeders\Translations\PreservationFrontendTranslations;
use Database\Seeders\Translations\PrivacyGovernanceFrontendTranslations;
use Database\Seeders\Translations\ProjectBillingFrontendTranslations;
use Database\Seeders\Translations\ProjectEvmFrontendTranslations;
use Database\Seeders\Translations\ProjectPortfolioFrontendTranslations;
use Database\Seeders\Translations\ProposalPortalFrontendTranslations;
use Database\Seeders\Translations\PurchaseRequisitionFrontendTranslations;
use Database\Seeders\Translations\ReceivablesFrontendTranslations;
use Database\Seeders\Translations\ReleaseAuditFrontendTranslations;
use Database\Seeders\Translations\ResourcePlanningFrontendTranslations;
use Database\Seeders\Translations\RfqFrontendTranslations;
use Database\Seeders\Translations\SalesBoundedContextBatch1FrontendTranslations;
use Database\Seeders\Translations\SalesPipelineFrontendTranslations;
use Database\Seeders\Translations\SecurityCenterFrontendTranslations;
use Database\Seeders\Translations\ShiftPlanningFrontendTranslations;
use Database\Seeders\Translations\SrmFrontendTranslations;
use Database\Seeders\Translations\StatutoryFinancialsFrontendTranslations;
use Database\Seeders\Translations\SupplierRebatesFrontendTranslations;
use Database\Seeders\Translations\SwissQrBillSlipFrontendTranslations;
use Database\Seeders\Translations\SwissVatFrontendTranslations;
use Database\Seeders\Translations\TreasuryBankingFrontendTranslations;
use Database\Seeders\Translations\VanSellingFieldSalesFrontendTranslations;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Global UI strings (it, en, de, fr) of the Angular frontend that TranslationSeeder does not hold.
 *
 * The API serves its own dictionary and only falls back to the static files of the frontend when it is empty, so a key
 * that is missing here shows up as the raw key in the interface. The keys of this seeder never overlap the ones of
 * TranslationSeeder.
 *
 * The unique index on (tenant_id, locale, key) does not catch duplicates while tenant_id is NULL, so the global rows of
 * these keys are replaced instead of upserted: running the seeder again leaves one row per key. Rows of a tenant are
 * never touched.
 */
class FrontendTranslationSeeder extends Seeder
{
    /**
     * The strings of one language that TranslationSeeder does not hold.
     *
     * @return array<string, string>
     */
    public static function dictionary(string $locale): array
    {
        $base = match ($locale) {
            'it' => ItalianFrontendTranslations::all(),
            'en' => EnglishFrontendTranslations::all(),
            'de' => GermanFrontendTranslations::all(),
            'fr' => FrenchFrontendTranslations::all(),
            default => throw new InvalidArgumentException("Unsupported locale [{$locale}]."),
        };

        return $base
            + HrAtsFrontendTranslations::all($locale)
            + LeadFrontendTranslations::all($locale)
            + SalesPipelineFrontendTranslations::all($locale)
            + Customer360FrontendTranslations::all($locale)
            + CopilotFrontendTranslations::all($locale)
            + AiCognitiveFrontendTranslations::all($locale)
            + GapViewsFrontendTranslations::all($locale)
            + MainFrontendTranslations::all($locale)
            + PrivacyGovernanceFrontendTranslations::all($locale)
            + ReleaseAuditFrontendTranslations::all($locale)
            + AuditTrailFrontendTranslations::all($locale)
            + PreservationFrontendTranslations::all($locale)
            + IdentityFrontendTranslations::all($locale)
            + AiForecastingFrontendTranslations::all($locale)
            + OcrInboxFrontendTranslations::all($locale)
            + BomManagementFrontendTranslations::all($locale)
            + GeneralLedgerPrimaNotaFrontendTranslations::all($locale)
            + MesOperatorBoardFrontendTranslations::all($locale)
            + ManufacturingProductionFrontendTranslations::all($locale)
            + ExecutiveDssFrontendTranslations::all($locale)
            + KpiDashboardsFrontendTranslations::all($locale)
            + ItalianPayrollFrontendTranslations::all($locale)
            + ShiftPlanningFrontendTranslations::all($locale)
            + AnalyticalAccountingFrontendTranslations::all($locale)
            + ArtisanalGelatoFrontendTranslations::all($locale)
            + HseFrontendTranslations::all($locale)
            + InventoryCountingFrontendTranslations::all($locale)
            + MrpPlanningFrontendTranslations::all($locale)
            + ProposalPortalFrontendTranslations::all($locale)
            + CpqQuotesFrontendTranslations::all($locale)
            + CpqConfiguratorFrontendTranslations::all($locale)
            + DataSovereigntyFrontendTranslations::all($locale)
            + MobileFrontendTranslations::all($locale)
            + MobileFeatureTranslations::all($locale)
            + SecurityCenterFrontendTranslations::all($locale)
            + RfqFrontendTranslations::all($locale)
            + PurchaseRequisitionFrontendTranslations::all($locale)
            + ManufacturingCommandCenterFrontendTranslations::all($locale)
            + ProjectEvmFrontendTranslations::all($locale)
            + ProjectPortfolioFrontendTranslations::all($locale)
            + FieldServiceManagementFrontendTranslations::all($locale)
            + HelpdeskSlaFrontendTranslations::all($locale)
            + ResourcePlanningFrontendTranslations::all($locale)
            + ProjectBillingFrontendTranslations::all($locale)
            + ReceivablesFrontendTranslations::all($locale)
            + SupplierRebatesFrontendTranslations::all($locale)
            + ItalianVatFrontendTranslations::all($locale)
            + ItalianVatWorkbenchFrontendTranslations::all($locale)
            + AccountsPayableFrontendTranslations::all($locale)
            + InvoiceMatchingFrontendTranslations::all($locale)
            + PayablesFrontendTranslations::all($locale)
            + SwissQrBillSlipFrontendTranslations::all($locale)
            + SwissVatFrontendTranslations::all($locale)
            + LeaveManagementFrontendTranslations::all($locale)
            + MarketingAutomationFrontendTranslations::all($locale)
            + AccrualsDeferralsFrontendTranslations::all($locale)
            + FixedAssetsFrontendTranslations::all($locale)
            + AcademyFrontendTranslations::all($locale)
            + AgentCommissionsFrontendTranslations::all($locale)
            + DebtCollectionFrontendTranslations::all($locale)
            + ExpenseManagementFrontendTranslations::all($locale)
            + IntercompanyFrontendTranslations::all($locale)
            + GdoTradeTermsFrontendTranslations::all($locale)
            + IndustrialLaundryFrontendTranslations::all($locale)
            + StatutoryFinancialsFrontendTranslations::all($locale)
            + PassengerTransportFrontendTranslations::all($locale)
            + CustomerSatisfactionFrontendTranslations::all($locale)
            + FranchiseNetworkFrontendTranslations::all($locale)
            + CorporateConventionsFrontendTranslations::all($locale)
            + ElectronicSignatureFrontendTranslations::all($locale)
            + ElectronicSignatureNotificationTranslations::all($locale)
            + SrmFrontendTranslations::all($locale)
            + TreasuryBankingFrontendTranslations::all($locale)
            + LiquidityPlanningFrontendTranslations::all($locale)
            + LiquidityPlanningTranslations::all($locale)
            + CustomerReturnsReverseLogisticsFrontendTranslations::all($locale)
            + VanSellingFieldSalesFrontendTranslations::all($locale)
            + SalesBoundedContextBatch1FrontendTranslations::all($locale);
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $locales = [];

        foreach (TranslationSeeder::LOCALES as $locale) {
            $locales[$locale] = self::dictionary($locale);
        }

        DB::transaction(function () use ($locales): void {
            $now = now();

            foreach ($locales as $locale => $translations) {
                foreach (array_chunk(array_keys($translations), 1000) as $keys) {
                    DB::table('translations')
                        ->whereNull('tenant_id')
                        ->where('locale', $locale)
                        ->whereIn('key', $keys)
                        ->delete();
                }

                $rows = [];

                foreach ($translations as $key => $value) {
                    $rows[] = [
                        'tenant_id' => null,
                        'locale' => $locale,
                        'key' => $key,
                        'value' => $value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('translations')->insert($chunk);
                }
            }
        });

        foreach (array_keys($locales) as $locale) {
            Cache::forget("translations_global_{$locale}");
        }
    }
}
