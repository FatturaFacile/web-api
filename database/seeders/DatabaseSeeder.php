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
            ErpLookupsSeeder::class,
            UnitOfMeasureSeeder::class,

            ProductCategorySeeder::class,
            ProductBrandSeeder::class,
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

            EiInboundLogSeeder::class,
            EiOutboundLogSeeder::class,
            SdiUuidLinkSeeder::class,

            TenantBackupSeeder::class,
        ]);
    }
}
