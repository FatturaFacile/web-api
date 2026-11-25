<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\VanSalesAndFieldForce\Domain\Enums\LoadManifestStatus;
use Modules\VanSalesAndFieldForce\Domain\Enums\SettlementStatus;
use Modules\VanSalesAndFieldForce\Domain\Enums\ThermalPrinterProfile;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanFiscalDocumentType;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanWarehouseStatus;
use Modules\VanSalesAndFieldForce\Domain\Enums\VisitMode;
use Modules\VanSalesAndFieldForce\Domain\Enums\VisitOutcomeStatus;
use Modules\VanSalesAndFieldForce\Models\FieldRoutePlan;
use Modules\VanSalesAndFieldForce\Models\VanEodSettlement;
use Modules\VanSalesAndFieldForce\Models\VanLoadManifest;
use Modules\VanSalesAndFieldForce\Models\VanVisitOutcome;
use Modules\VanSalesAndFieldForce\Models\VanWarehouse;

/**
 * Idempotent demo & reference seeder for Mod 233 (Issue #479):
 * Van Selling (Tentata Vendita su Furgone) & Offline Field Sales across IT & CH scenarios.
 */
final class VanSellingFieldSalesDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('created_at')->first();
        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->runWithTenant($tenant, function () use ($tenant): void {
            $tenantId = (string) $tenant->id;

            $depot = Warehouse::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'code' => 'MAIN'],
                ['name' => 'Central Cold-Chain Depot', 'is_primary' => true]
            );

            $vanWhIt = Warehouse::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'code' => 'VAN-MI01'],
                ['name' => 'Furgone Tentata Vendita MI-94821-FS', 'is_primary' => false]
            );
            $vanWhCh = Warehouse::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'code' => 'VAN-TI01'],
                ['name' => 'Lieferwagen TI-233480 Lugano', 'is_primary' => false]
            );

            $cheeseProduct = Product::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'sku' => 'FORM-DOP-KG'],
                [
                    'barcode' => '08001234567893',
                    'name' => 'Formaggio Alpino DOP Stagionato (Peso Variabile)',
                    'unit_measure' => 'kg',
                    'cost_price' => 14.50,
                    'sale_price' => 24.80,
                    'tax_rate' => 4.00,
                    'track_inventory' => true,
                    'is_active' => true,
                ]
            );
            $salumiProduct = Product::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'sku' => 'SAL-PROSC-KG'],
                [
                    'barcode' => '08009876543210',
                    'name' => 'Prosciutto Crudo Riserva (Peso Variabile)',
                    'unit_measure' => 'kg',
                    'cost_price' => 18.00,
                    'sale_price' => 31.50,
                    'tax_rate' => 10.00,
                    'track_inventory' => true,
                    'is_active' => true,
                ]
            );

            $customerIt = Contact::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'company_name' => 'Bottega del Gusto Milano Srl'],
                [
                    'type' => 'customer',
                    'country' => 'IT',
                    'vat_number' => 'IT08923410962',
                    'sdi_code' => 'M5UXCR1',
                    'payment_terms_days' => 30,
                ]
            );
            $customerCh = Contact::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'company_name' => 'Caseificio & Gastronomia Ticino SA'],
                [
                    'type' => 'customer',
                    'country' => 'CH',
                    'uid_number' => 'CHE-412.889.301',
                    'payment_terms_days' => 30,
                ]
            );

            // Seed historical invoice for Fast Reorder (Ultimi 5 Acquisti) & overdue invoice example
            $histInv = Invoice::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'invoice_number' => 'FV-MI01/2026/00001'],
                [
                    'contact_id' => $customerIt->id,
                    'invoice_type' => 'standard',
                    'document_type' => 'TD01',
                    'date' => now()->subDays(45)->toDateString(),
                    'due_date' => now()->subDays(15)->toDateString(),
                    'currency' => 'EUR',
                    'jurisdiction' => 'IT',
                    'total_net' => 124.00,
                    'total_tax' => 4.96,
                    'total_gross' => 128.96,
                    'total_paid' => 0.00,
                    'payment_status' => 'overdue',
                ]
            );
            InvoiceItem::query()->firstOrCreate(
                ['invoice_id' => $histInv->id, 'product_id' => $cheeseProduct->id],
                [
                    'description' => $cheeseProduct->name,
                    'quantity' => 5.00,
                    'unit_price' => 24.80,
                    'tax_rate' => 4.00,
                    'line_total' => 124.00,
                ]
            );

            $vanIt = VanWarehouse::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'license_plate' => 'MI-94821-FS'],
                [
                    'warehouse_id' => $vanWhIt->id,
                    'depot_warehouse_id' => $depot->id,
                    'code' => 'VAN-MI01',
                    'name' => 'Furgone Frigo Milano Centro',
                    'vehicle_make_model' => 'Iveco Daily 35S16 Frigo',
                    'driver_name' => 'Marco Bellini',
                    'has_cold_chain' => true,
                    'min_temp_celsius' => 0.00,
                    'max_temp_celsius' => 4.00,
                    'current_temp_celsius' => 2.40,
                    'last_temp_recorded_at' => now(),
                    'temp_alert_active' => false,
                    'invoice_sectional_code' => 'FV-MI01',
                    'invoice_next_number' => 2,
                    'ddt_sectional_code' => 'DDTV-MI01',
                    'ddt_next_number' => 2,
                    'receipt_sectional_code' => 'RTV-MI01',
                    'receipt_next_number' => 1,
                    'fiscal_year' => 2026,
                    'printer_profile' => ThermalPrinterProfile::EscPos80mm,
                    'printer_brand' => 'Bixolon SPP-R310',
                    'status' => VanWarehouseStatus::OnRoute,
                    'current_stock_snapshot' => [
                        [
                            'product_id' => (string) $cheeseProduct->id,
                            'sku' => $cheeseProduct->sku,
                            'description' => $cheeseProduct->name,
                            'lot_number' => 'LOT-IT2611',
                            'expiry_date' => now()->addDays(45)->toDateString(),
                            'unit_measure' => 'kg',
                            'is_variable_weight' => true,
                            'unit_price' => 24.80,
                            'quantity_onboard' => 18.50,
                        ],
                        [
                            'product_id' => (string) $salumiProduct->id,
                            'sku' => $salumiProduct->sku,
                            'description' => $salumiProduct->name,
                            'lot_number' => 'LOT-PR2611',
                            'expiry_date' => now()->addDays(60)->toDateString(),
                            'unit_measure' => 'kg',
                            'is_variable_weight' => true,
                            'unit_price' => 31.50,
                            'quantity_onboard' => 12.00,
                        ],
                    ],
                    'empties_onboard_snapshot' => [
                        ['item_code' => 'EMP-CASSA-151', 'item_name' => 'Cassa Plastica Alimentare (Mod 151)', 'quantity_onboard' => 14],
                    ],
                    'temperature_log' => [
                        ['recorded_at' => now()->subHours(2)->toIso8601String(), 'temp_celsius' => 2.1, 'source' => 'sensor'],
                        ['recorded_at' => now()->toIso8601String(), 'temp_celsius' => 2.4, 'source' => 'sensor'],
                    ],
                ]
            );

            VanWarehouse::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'license_plate' => 'TI-233480'],
                [
                    'warehouse_id' => $vanWhCh->id,
                    'depot_warehouse_id' => $depot->id,
                    'code' => 'VAN-TI01',
                    'name' => 'Furgone Frigo Lugano / Bellinzona',
                    'vehicle_make_model' => 'Mercedes Sprinter 317 CDI Frigo',
                    'driver_name' => 'Luca Bernasconi',
                    'has_cold_chain' => true,
                    'min_temp_celsius' => 0.00,
                    'max_temp_celsius' => 4.00,
                    'current_temp_celsius' => 2.80,
                    'last_temp_recorded_at' => now(),
                    'temp_alert_active' => false,
                    'invoice_sectional_code' => 'FV-TI01',
                    'invoice_next_number' => 1,
                    'ddt_sectional_code' => 'DDTV-TI01',
                    'ddt_next_number' => 1,
                    'receipt_sectional_code' => 'RTV-TI01',
                    'receipt_next_number' => 1,
                    'fiscal_year' => 2026,
                    'printer_profile' => ThermalPrinterProfile::Zpl104mm,
                    'printer_brand' => 'Zebra ZQ521',
                    'status' => VanWarehouseStatus::Active,
                    'current_stock_snapshot' => [
                        [
                            'product_id' => (string) $cheeseProduct->id,
                            'sku' => $cheeseProduct->sku,
                            'description' => $cheeseProduct->name,
                            'lot_number' => 'LOT-CH2611',
                            'unit_measure' => 'kg',
                            'is_variable_weight' => true,
                            'unit_price' => 24.80,
                            'quantity_onboard' => 25.00,
                        ],
                    ],
                    'empties_onboard_snapshot' => [],
                    'temperature_log' => [],
                ]
            );

            $manifest = VanLoadManifest::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'manifest_number' => 'VLM-2026-MI01'],
                [
                    'van_warehouse_id' => $vanIt->id,
                    'load_date' => now()->toDateString(),
                    'weekday_iso' => (int) now()->isoWeekday(),
                    'route_code' => 'GIRO-MI-OVEST',
                    'depot_warehouse_id' => $depot->id,
                    'global_ddt_number' => 'DDTV-MI01/2026/00001',
                    'global_ddt_causal' => 'Tentata Vendita',
                    'regulatory_reference' => 'DPR 472/1996 & CM 249/E',
                    'self_addressed_company_name' => $tenant->legal_name ?? $tenant->name,
                    'departure_temp_celsius' => 2.10,
                    'cold_chain_compliant' => true,
                    'status' => LoadManifestStatus::DdtIssued,
                    'lines' => [
                        [
                            'product_id' => (string) $cheeseProduct->id,
                            'sku' => $cheeseProduct->sku,
                            'description' => $cheeseProduct->name,
                            'unit_measure' => 'kg',
                            'quantity' => 25.00,
                            'lot_number' => 'LOT-IT2611',
                            'is_variable_weight' => true,
                            'unit_price' => 24.80,
                            'line_value' => 620.00,
                        ],
                        [
                            'product_id' => (string) $salumiProduct->id,
                            'sku' => $salumiProduct->sku,
                            'description' => $salumiProduct->name,
                            'unit_measure' => 'kg',
                            'quantity' => 15.00,
                            'lot_number' => 'LOT-PR2611',
                            'is_variable_weight' => true,
                            'unit_price' => 31.50,
                            'line_value' => 472.50,
                        ],
                    ],
                    'total_packages' => 8,
                    'total_gross_weight_kg' => 40.000,
                    'total_net_value' => 1092.50,
                    'currency' => 'EUR',
                    'dispatched_at' => now()->subHours(3),
                ]
            );

            $routePlan = FieldRoutePlan::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'route_code' => 'GIRO-MI-OVEST', 'plan_date' => now()->toDateString()],
                [
                    'van_warehouse_id' => $vanIt->id,
                    'van_load_manifest_id' => $manifest->id,
                    'name' => 'Giro Milano Ovest & Ticino HoReCa',
                    'weekday_iso' => (int) now()->isoWeekday(),
                    'is_recurring' => true,
                    'salesperson_name' => 'Marco Bellini',
                    'territory_zone' => 'Milano Ovest / Ticino',
                    'country' => 'IT',
                    'status' => 'active',
                    'stops' => [
                        [
                            'sequence' => 1,
                            'contact_id' => (string) $customerIt->id,
                            'customer_name' => $customerIt->company_name,
                            'address' => 'Via Tortona 31, Milano',
                            'visit_mode' => VisitMode::VanSelling->value,
                        ],
                        [
                            'sequence' => 2,
                            'contact_id' => (string) $customerCh->id,
                            'customer_name' => $customerCh->company_name,
                            'address' => 'Via Nassa 18, Lugano',
                            'visit_mode' => VisitMode::VanSelling->value,
                        ],
                    ],
                    'total_stops' => 2,
                    'completed_stops' => 1,
                ]
            );

            VanVisitOutcome::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'client_idempotency_key' => 'DEMO-VISIT-MI01-001'],
                [
                    'field_route_plan_id' => $routePlan->id,
                    'van_warehouse_id' => $vanIt->id,
                    'van_load_manifest_id' => $manifest->id,
                    'contact_id' => $customerIt->id,
                    'stop_sequence' => 1,
                    'visit_mode' => VisitMode::VanSelling,
                    'outcome_status' => VisitOutcomeStatus::CompletedSale,
                    'document_type' => VanFiscalDocumentType::ImmediateInvoice,
                    'fiscal_sectional_code' => 'FV-MI01',
                    'definitive_document_number' => 'FV-MI01/2026/00001',
                    'sdi_transmission_status' => 'queued_for_sdi',
                    'credit_check_passed' => true,
                    'sale_lines' => [
                        [
                            'product_id' => (string) $cheeseProduct->id,
                            'sku' => $cheeseProduct->sku,
                            'description' => $cheeseProduct->name,
                            'unit_measure' => 'kg',
                            'quantity' => 6.50,
                            'lot_number' => 'LOT-IT2611',
                            'gs1_barcode' => '(01)08001234567893(3103)006500(10)LOT-IT2611(17)261231',
                            'is_variable_weight' => true,
                            'unit_price' => 24.80,
                            'tax_rate' => 4.00,
                            'line_total' => 161.20,
                        ],
                    ],
                    'empties_movements' => [
                        ['item_code' => 'EMP-CASSA-151', 'item_name' => 'Cassa Plastica Alimentare (Mod 151)', 'delivered_qty' => 2, 'returned_qty' => 1, 'unit_deposit' => 12.00],
                    ],
                    'collections' => [
                        ['method' => 'pos_card', 'amount' => 167.65],
                    ],
                    'total_net' => 161.20,
                    'total_tax' => 6.45,
                    'total_gross' => 167.65,
                    'empties_net_deposit_amount' => 12.00,
                    'total_collected_amount' => 167.65,
                    'payment_method' => 'pos_card',
                    'currency' => 'EUR',
                    'printer_format' => ThermalPrinterProfile::EscPos80mm,
                    'synced_at' => now(),
                    'visited_at' => now()->subHour(),
                ]
            );

            VanEodSettlement::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'settlement_number' => 'EOD-VAN-MI01-DEMO'],
                [
                    'van_warehouse_id' => $vanIt->id,
                    'van_load_manifest_id' => $manifest->id,
                    'field_route_plan_id' => $routePlan->id,
                    'settlement_date' => now()->toDateString(),
                    'status' => SettlementStatus::Reconciled,
                    'stock_reconciliation_lines' => [
                        [
                            'product_id' => (string) $cheeseProduct->id,
                            'sku' => $cheeseProduct->sku,
                            'description' => $cheeseProduct->name,
                            'lot_number' => 'LOT-IT2611',
                            'unit_measure' => 'kg',
                            'loaded_qty' => 25.00,
                            'sold_qty' => 6.50,
                            'freebies_qty' => 0.00,
                            'returned_damaged_qty' => 0.00,
                            'remaining_qty' => 18.50,
                            'keep_onboard_qty' => 18.50,
                            'unload_central_qty' => 0.00,
                        ],
                    ],
                    'total_loaded_qty' => 25.00,
                    'total_sold_qty' => 6.50,
                    'total_remaining_qty' => 18.50,
                    'total_keep_onboard_qty' => 18.50,
                    'total_unload_central_qty' => 0.00,
                    'opening_cash_float' => 150.00,
                    'collected_cash_amount' => 0.00,
                    'collected_pos_card_amount' => 167.65,
                    'expected_cash_remittance' => 150.00,
                    'counted_cash_remittance' => 150.00,
                    'cash_discrepancy_amount' => 0.00,
                    'total_remittance_amount' => 317.65,
                    'deposit_slip_number' => 'VERS-2026-MI01',
                    'treasury_deposited' => true,
                    'currency' => 'EUR',
                    'reconciled_at' => now(),
                ]
            );
        });
    }
}
