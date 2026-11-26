<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            SettingSeeder::class,

            TenantSeeder::class,
            UserSeeder::class,

            TranslationSeeder::class,
            FrontendTranslationSeeder::class,
            AgentCommissionsReferenceSeeder::class,
            DebtCollectionReferenceSeeder::class,
            ExpenseManagementReferenceSeeder::class,
            ExpenseManagementDemoSeeder::class,
            TenderManagementDemoSeeder::class,
            GdoTradeTermsDemoSeeder::class,
            IndustrialLaundryDemoSeeder::class,
            StatutoryFinancialsReferenceSeeder::class,
            PassengerTransportDemoSeeder::class,
            CustomerReturnsReverseLogisticsDemoSeeder::class,
            VanSellingFieldSalesDemoSeeder::class,
            CustomerSatisfactionDemoSeeder::class,
            SalesBoundedContextBatch1LocalizationSeeder::class,
            SalesBoundedContextBatch1DemoSeeder::class,
            SalesBoundedContextBatch2LocalizationSeeder::class,
            SalesBoundedContextBatch2DemoSeeder::class,
            FranchiseNetworkReferenceSeeder::class,
            FranchiseNetworkDemoSeeder::class,
            CorporateConventionsDemoSeeder::class,
            ProductCatalogDemoSeeder::class,
            TreasuryBankingReferenceSeeder::class,
            CpqLocalizationSeeder::class,
            CpqConfiguratorLocalizationSeeder::class,
            ErpLookupsSeeder::class,
            ComprehensiveLookupMasterSeeder::class,
            UnitOfMeasureSeeder::class,
            CustomsReferenceSeeder::class,

            ProductCategorySeeder::class,
            ProductBrandSeeder::class,
            CustomsAndPemOriginSeeder::class,
            ProductSeeder::class,

            ContactSeeder::class,
            ContactAddressSeeder::class,

            PaymentTermSeeder::class,
            PaymentTermInstallmentSeeder::class,

            PriceListSeeder::class,
            PriceListItemSeeder::class,

            WarehouseSeeder::class,
            StockMovementSeeder::class,

            DocumentSequenceSeeder::class,

            CpqDefaultCatalogAndRulesSeeder::class,
            CpqConfiguratorDemoSeeder::class,
            QuoteSeeder::class,
            QuoteItemSeeder::class,

            SalesOrderSeeder::class,
            SalesOrderItemSeeder::class,

            DeliveryNoteSeeder::class,
            DeliveryNoteItemSeeder::class,

            InvoiceSeeder::class,
            InvoiceItemSeeder::class,
            InvoiceQrBillDataSeeder::class,
            InvoiceSdiMetadataSeeder::class,

            PassiveInvoiceSeeder::class,

            PurchaseOrderSeeder::class,
            PurchaseOrderItemSeeder::class,

            GoodsReceiptSeeder::class,
            GoodsReceiptItemSeeder::class,

            ProcurementLookupSeeder::class,
            ExpenseLookupSeeder::class,
            ProcurementDemoDataSeeder::class,
            InvoiceMatchingDemoSeeder::class,

            PaymentSeeder::class,
            SupplierPaymentSeeder::class,
            PaymentReminderSeeder::class,

            BankStatementSeeder::class,
            BankStatementLineSeeder::class,

            PaymentImportSeeder::class,
            PaymentImportLineSeeder::class,

            SubscriptionSeeder::class,
            SubscriptionItemSeeder::class,
            SubscriptionRunSeeder::class,

            WorkOrderSeeder::class,
            WorkOrderLineSeeder::class,
            TimeEntrySeeder::class,

            PosTerminalSeeder::class,
            PosSessionSeeder::class,
            PosSaleSeeder::class,
            PosSaleItemSeeder::class,
            RtFiscalReceiptSeeder::class,

            EcommerceChannelSeeder::class,
            EcommerceMappingSeeder::class,

            LoyaltyCardSeeder::class,
            LoyaltyTransactionSeeder::class,

            AlertSeeder::class,

            AttachmentSeeder::class,
            DocumentEmailSeeder::class,
            DocumentEventSeeder::class,

            DashboardLayoutSeeder::class,
            ChartOfAccountTemplateSeeder::class,

            EiInboundLogSeeder::class,
            EiOutboundLogSeeder::class,
            SdiUuidLinkSeeder::class,

            TenantBackupSeeder::class,
            ProjectDatabaseSeeder::class,
            GeneralLedgerDatabaseSeeder::class,
            TreasuryDatabaseSeeder::class,
            InvoicingDatabaseSeeder::class,
            CRMReferenceSeeder::class,
            CRMDemoDataSeeder::class,
            AssetReferenceSeeder::class,
            AssetDemoDataSeeder::class,
            HRReferenceSeeder::class,
            HRDemoDataSeeder::class,
            ServiceReferenceSeeder::class,
            ServiceDemoDataSeeder::class,
            GlobalEnterpriseReferenceDataSeeder::class,
            SubscriptionReferenceSeeder::class,
            SubscriptionDemoDataSeeder::class,
            ManufacturingReferenceSeeder::class,
            AssociationsReferenceSeeder::class,
            ManufacturingDemoDataSeeder::class,
            PlmReferenceAndDemoSeeder::class,
            NewerModulesReferenceSeeder::class,
            GelatoReferenceSeeder::class,
            EnterpriseDemoMasterSeeder::class,
            AcademyLearningContentSeeder::class,
            AcademyContextualHelpSeeder::class,
            SovereignRegionNodeSeeder::class,
            SwissVatSeeder::class,
            LeaveManagementSeeder::class,
            MarketingAutomationSeeder::class,
        ]);
    }
}
