<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Deterministic, idempotent demo data of the AI forecasting module (Mod 094) for two tenants: Helvetia Precision AG (CH,
 * CHF) and Meccanica Lombarda Srl (IT, EUR). Every id derives from a name, so a second run deletes and recreates the same
 * rows. Dates are relative to today: 24 months of settled invoices and sales, open receivables around today.
 *
 * Payment profiles (one customer each): low (punctual), moderate (about +10 days), high (about +30 days) and critical
 * (delays worsening from +15 to +55 days, which an exponentially weighted model must pick up).
 * SKUs: SKU-001 seasonal with a 12% yearly trend and a 14 day lead time (sigma 3.5), SKU-002 flat 120 a month with a 21 day
 * lead time (sigma 9), SKU-003 with seeded forecasts of the last three closed months next to their actual sales.
 *
 * The demo user of each tenant is forecast.ch@mod094.test / forecast.it@mod094.test. Their password is random on every run and is not stored anywhere:
 * the API, the tests and forecasting:verify-e2e authenticate directly (no password login).
 */
final class AiForecastingDemoSeeder extends Seeder
{
    private const string UuidNamespace = '7a1c5e0e-0940-4a94-9f6d-3b0a0d1e0940';

    /** Order of the deviations of the flat SKU-002 demand: 24 values, mean 0, sample standard deviation about 10. */
    private const array FlatDemandDeviations = [13, -9, 3, -14, 8, -6, 11, -13, -3, 10, -7, 5, 14, -11, -10, 7, -12, 9, -5, 12, -8, 6, -13, 13];

    /** Seasonal factors from January to December: peaks of +65% in June and November, trough of -40% in February. */
    private const array SeasonalIndex = [0.80, 0.60, 0.85, 1.00, 1.25, 1.65, 1.20, 0.90, 0.95, 1.25, 1.65, 1.30];

    /** Lead time deviations in days: 13 values with mean 0 and sample standard deviation exactly 9. */
    private const array LeadTimeDeviationsWide = [14, -14, 12, -12, 9, -9, 7, -7, 4, -4, 0, 0, 0];

    /** 9 values with mean 0 and sample standard deviation exactly 3.5. */
    private const array LeadTimeDeviationsNarrow = [7, -7, 0, 0, 0, 0, 0, 0, 0];

    /** @var list<string> tables whose rows of a tenant are removed before it is seeded again, children first */
    private const array OwnedTables = [
        'sku_demand_forecasts', 'ai_liquidity_forecast_runs', 'invoice_expected_collections', 'customer_payment_behavior_models',
        'stock_reorder_rules', 'mfg_items', 'stock_movements', 'stock_items', 'stock_item_categories',
        'goods_receipt_items', 'goods_receipts', 'purchase_order_items', 'purchase_orders', 'sales_order_items', 'sales_orders',
        'payments', 'invoices', 'passive_invoices', 'payroll_payslips', 'payroll_runs', 'vat_settlements',
        'treasury_recurring_items', 'bank_accounts', 'warehouses', 'products', 'contacts',
    ];

    private CarbonImmutable $today;

    public function run(): void
    {
        $this->today = CarbonImmutable::today();

        foreach ($this->profiles() as $profile) {
            DB::transaction(function () use ($profile): void {
                $this->seedTenant($profile);
            });
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function profiles(): array
    {
        return [
            [
                'key' => 'CH', 'name' => 'Tenant CH - Helvetia Precision AG', 'legal_name' => 'Helvetia Precision AG', 'country' => 'CH', 'currency' => 'CHF',
                'vat' => 'CHE-123.456.789 MWST', 'email' => 'forecast.ch@mod094.test', 'iban' => 'CH9300762011623852957', 'bank' => 'Zürcher Kantonalbank',
                'customers' => ['low' => 'Alpenbau AG', 'moderate' => 'Zürcher Metall GmbH', 'high' => 'Ticino Logistica SA', 'critical' => 'Romandie Retail SA'],
                'suppliers' => ['Stahlwerk Gerlafingen AG', 'Elektro Winterthur AG', 'Verpackung Basel GmbH'],
                'settings' => ['forecasting' => ['swiss_thirteenth_salary_month' => 11]],
                'deductions' => 650.0, 'employer' => 700.0, 'vat_payments' => [12200.0, 12800.0, 12500.0, 12500.0],
            ],
            [
                'key' => 'IT', 'name' => 'Tenant IT - Meccanica Lombarda Srl', 'legal_name' => 'Meccanica Lombarda Srl', 'country' => 'IT', 'currency' => 'EUR',
                'vat' => 'IT00811720580', 'email' => 'forecast.it@mod094.test', 'iban' => 'IT60X0542811101000000123456', 'bank' => 'Banca Popolare di Milano',
                'customers' => ['low' => 'Brianza Impianti Srl', 'moderate' => 'Padana Forniture Spa', 'high' => 'Napoli Distribuzione Srl', 'critical' => 'Sicilia Costruzioni Srl'],
                'suppliers' => ['Acciaierie Lombarde Spa', 'Elettrica Bergamasca Srl', 'Imballaggi Veneti Srl'],
                'settings' => ['forecasting' => ['annual_income_tax_advance' => 60000, 'pays_fourteenth_salary' => true]],
                'deductions' => 1000.0, 'employer' => 1500.0, 'vat_payments' => [13200.0, 14800.0, 14000.0, 14000.0],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function seedTenant(array $profile): void
    {
        $key = (string) $profile['key'];
        $tenantId = $this->id($key, 'tenant');

        $this->purge($tenantId);
        DB::table('tenants')->updateOrInsert(['id' => $tenantId], [
            'name' => $profile['name'], 'legal_name' => $profile['legal_name'], 'vat_number' => $profile['vat'], 'country' => $profile['country'],
            'default_currency' => $profile['currency'], 'status' => 'active', 'settings' => json_encode($profile['settings']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::query()->updateOrCreate(['email' => $profile['email']], ['name' => 'Forecast demo '.$key, 'password' => Str::password(32), 'locale' => 'en']);
        $user->tenants()->syncWithoutDetaching([$tenantId => ['id' => (string) Str::uuid(), 'role' => 'owner']]);

        $customers = $this->seedCustomers($profile, $tenantId);
        $suppliers = $this->seedSuppliers($profile, $tenantId);
        $this->seedBankAndRecurring($profile, $tenantId);
        $this->seedPayables($profile, $tenantId, $suppliers);
        $this->seedPayroll($profile, $tenantId);
        $this->seedVat($profile, $tenantId);
        $this->seedInventory($profile, $tenantId, $customers['low'], $suppliers[0]);
    }

    private function purge(string $tenantId): void
    {
        foreach (self::OwnedTables as $table) {
            DB::table($table)->where('tenant_id', $tenantId)->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, string> tier => contact id
     */
    private function seedCustomers(array $profile, string $tenantId): array
    {
        $key = (string) $profile['key'];
        $contacts = [];
        $tiers = [
            'low' => ['delays' => [-3, -2, -1, 0, 1, -1, 0, -2, 1, -3], 'count' => 30, 'step' => 22, 'open' => [[-5, 15000], [10, 15000], [35, 15000]]],
            'moderate' => ['delays' => [3, 17, 8, 12, 10, 5, 15, 9, 11, 10], 'count' => 25, 'step' => 27, 'open' => [[-20, 14000], [5, 16000], [20, 15000], [45, 17000]]],
            'high' => ['delays' => [15, 45, 24, 36, 30, 19, 41, 26, 34, 30], 'count' => 20, 'step' => 34, 'open' => [[-40, 30000], [-10, 28000], [25, 30000]]],
            'critical' => ['delays' => [15, 16, 18, 20, 22, 24, 26, 28, 30, 35, 45, 48, 50, 53, 55], 'count' => 15, 'step' => 0, 'open' => [[-30, 60000], [15, 50000]]],
        ];
        // Settled days ago of the critical customer: spread over a year, the last five in the last three months.
        $criticalAges = [365, 338, 311, 284, 257, 230, 203, 176, 149, 122, 80, 62, 44, 26, 8];

        foreach ($tiers as $tier => $plan) {
            $contactId = $this->id($key, "customer-{$tier}");
            $contacts[$tier] = $contactId;
            DB::table('contacts')->insert($this->row($tenantId, [
                'id' => $contactId, 'type' => 'customer', 'country' => $profile['country'], 'company_name' => $profile['customers'][$tier], 'payment_terms_days' => 30,
            ]));

            $invoices = [];
            $payments = [];

            for ($i = 0; $i < $plan['count']; $i++) {
                $age = $tier === 'critical' ? $criticalAges[$i] : 2 + ($plan['count'] - 1 - $i) * $plan['step'];
                $delay = $plan['delays'][$i % count($plan['delays'])];
                $settled = $this->today->subDays($age);
                $due = $settled->subDays($delay);
                $gross = 4000.0 + ($i % 5) * 750.0;
                $invoiceId = $this->id($key, "invoice-{$tier}-{$i}");
                $invoices[] = $this->invoiceRow($tenantId, $invoiceId, $contactId, "INV-{$key}-".strtoupper($tier).'-'.($i + 1), $due, $gross, $gross, 'paid', (string) $profile['currency']);
                $payments[] = $this->row($tenantId, ['id' => $this->id($key, "payment-{$tier}-{$i}"), 'invoice_id' => $invoiceId, 'amount' => $gross, 'paid_at' => $settled->toDateString(), 'type' => 'payment']);
            }

            foreach ($plan['open'] as $index => [$offset, $gross]) {
                $due = $this->today->addDays($offset);
                $invoices[] = $this->invoiceRow($tenantId, $this->id($key, "invoice-open-{$tier}-{$index}"), $contactId, "INV-{$key}-".strtoupper($tier).'-OPEN-'.($index + 1), $due, (float) $gross, 0.0, $offset < 0 ? 'overdue' : 'unpaid', (string) $profile['currency']);
            }

            foreach (array_chunk($invoices, 50) as $chunk) {
                DB::table('invoices')->insert($chunk);
            }

            foreach (array_chunk($payments, 50) as $chunk) {
                DB::table('payments')->insert($chunk);
            }
        }

        return $contacts;
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceRow(string $tenantId, string $id, string $contactId, string $number, CarbonImmutable $due, float $gross, float $paid, string $status, string $currency): array
    {
        return $this->row($tenantId, [
            'id' => $id, 'contact_id' => $contactId, 'invoice_type' => 'standard', 'invoice_number' => $number, 'date' => $due->subDays(30)->toDateString(),
            'due_date' => $due->toDateString(), 'currency' => $currency, 'total_net' => $gross, 'total_tax' => 0, 'total_gross' => $gross, 'total_paid' => $paid,
            'receivable_amount' => $gross - $paid, 'payment_status' => $status, 'status' => 'posted', 'subtotal' => $gross,
        ]);
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return list<string> supplier contact ids
     */
    private function seedSuppliers(array $profile, string $tenantId): array
    {
        $ids = [];

        foreach ($profile['suppliers'] as $index => $name) {
            $ids[] = $this->id((string) $profile['key'], "supplier-{$index}");
            DB::table('contacts')->insert($this->row($tenantId, ['id' => $ids[$index], 'type' => 'supplier', 'country' => $profile['country'], 'company_name' => $name, 'payment_terms_days' => 30]));
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function seedBankAndRecurring(array $profile, string $tenantId): void
    {
        $key = (string) $profile['key'];
        DB::table('bank_accounts')->insert($this->row($tenantId, [
            'id' => $this->id($key, 'bank'), 'bank_name' => $profile['bank'], 'iban' => $profile['iban'], 'currency' => $profile['currency'],
            'current_balance' => 140000, 'available_balance' => 140000, 'is_default' => true, 'is_active' => true,
        ]));

        $nextMonth = $this->today->addMonthNoOverflow()->startOfMonth();

        foreach ([['Office and workshop rent', 'outflow', 6500], ['Software subscriptions', 'outflow', 1200], ['Maintenance contracts', 'inflow', 4000]] as $index => [$name, $direction, $amount]) {
            DB::table('treasury_recurring_items')->insert($this->row($tenantId, [
                'id' => $this->id($key, "recurring-{$index}"), 'name' => $name, 'category' => $direction === 'inflow' ? 'subscription' : 'other', 'direction' => $direction,
                'amount' => $amount, 'currency' => $profile['currency'], 'frequency' => 'monthly', 'next_date' => $nextMonth->toDateString(), 'is_active' => true,
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  list<string>  $suppliers
     */
    private function seedPayables(array $profile, string $tenantId, array $suppliers): void
    {
        foreach ([[7, 18000], [18, 12000], [30, 25000], [45, 9000], [60, 16000], [75, 14000]] as $index => [$offset, $amount]) {
            $due = $this->today->addDays($offset);
            DB::table('passive_invoices')->insert($this->row($tenantId, [
                'id' => $this->id((string) $profile['key'], "bill-{$index}"), 'supplier_id' => $suppliers[$index % count($suppliers)], 'invoice_number' => "SUP-{$profile['key']}-".($index + 1),
                'date' => $due->subDays(30)->toDateString(), 'due_date' => $due->toDateString(), 'total_amount' => $amount, 'total_net' => $amount, 'total_tax' => 0,
                'total_paid' => 0, 'currency' => $profile['currency'], 'payment_status' => 'unpaid',
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function seedPayroll(array $profile, string $tenantId): void
    {
        $key = (string) $profile['key'];

        for ($back = 1; $back <= 12; $back++) {
            $month = $this->today->subMonthsNoOverflow($back);
            $runId = $this->id($key, "payroll-run-{$back}");
            DB::table('payroll_runs')->insert($this->row($tenantId, [
                'id' => $runId, 'year' => (int) $month->format('Y'), 'month' => (int) $month->format('n'), 'country' => $profile['country'], 'currency' => $profile['currency'], 'status' => 'Finalized',
            ]));

            for ($employee = 1; $employee <= 4; $employee++) {
                DB::table('payroll_payslips')->insert($this->row($tenantId, [
                    'id' => $this->id($key, "payslip-{$back}-{$employee}"), 'payroll_run_id' => $runId, 'employee_id' => $this->id($key, "employee-{$employee}"),
                    'year' => (int) $month->format('Y'), 'month' => (int) $month->format('n'), 'employee_name' => "Employee {$employee}", 'paid_days' => 30,
                    'gross_salary' => 5000, 'employee_deductions' => $profile['deductions'], 'net_salary' => 5000 - $profile['deductions'],
                    'payout_amount' => 5000 - $profile['deductions'], 'employer_contributions' => $profile['employer'], 'currency' => $profile['currency'],
                ]));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function seedVat(array $profile, string $tenantId): void
    {
        $quarter = $this->today->subQuarterNoOverflow();

        foreach ($profile['vat_payments'] as $back => $amount) {
            $period = $quarter->subQuarters(3 - $back);
            DB::table('vat_settlements')->insert($this->row($tenantId, [
                'id' => $this->id((string) $profile['key'], "vat-{$back}"), 'period_type' => 'quarterly', 'year' => (int) $period->format('Y'), 'period' => (int) $period->quarter,
                'payable_amount' => $amount, 'net_vat_payable' => $amount, 'country' => $profile['country'], 'currency' => $profile['currency'], 'status' => 'filed',
            ]));
        }
    }

    private function seedInventory(array $profile, string $tenantId, string $customerId, string $supplierId): void
    {
        $key = (string) $profile['key'];
        $warehouseId = $this->id($key, 'warehouse');
        DB::table('warehouses')->insert($this->row($tenantId, ['id' => $warehouseId, 'code' => 'MAIN', 'name' => 'Main warehouse', 'is_primary' => true]));
        DB::table('stock_item_categories')->insert($this->row($tenantId, ['id' => $this->id($key, 'category'), 'code' => 'GEN', 'name' => 'General']));

        $skus = [
            'SKU-001' => ['Precision housing (seasonal)', 300, self::LeadTimeDeviationsNarrow, 14],
            'SKU-002' => ['Industrial bearing (steady)', 200, self::LeadTimeDeviationsWide, 21],
            'SKU-003' => ['Sensor kit (baseline)', 100, [0, 0, 0, 0, 0], 10],
        ];
        $products = [];

        foreach ($skus as $sku => [$name, $stock]) {
            $productId = $this->id($key, "product-{$sku}");
            $products[$sku] = $productId;
            DB::table('products')->insert($this->row($tenantId, ['id' => $productId, 'sku' => $sku, 'name' => $name, 'cost_price' => 60, 'sale_price' => 100]));
            DB::table('stock_items')->insert($this->row($tenantId, ['id' => $this->id($key, "item-{$sku}"), 'category_id' => $this->id($key, 'category'), 'sku' => $sku, 'name' => $name, 'product_id' => $productId]));
            DB::table('stock_movements')->insert($this->row($tenantId, ['id' => $this->id($key, "movement-{$sku}"), 'product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => $stock, 'type' => 'receipt', 'movement_type' => 'receipt']));
        }

        DB::table('stock_reorder_rules')->insert($this->row($tenantId, [
            'id' => $this->id($key, 'rule-SKU-001'), 'warehouse_id' => $warehouseId, 'stock_item_id' => $this->id($key, 'item-SKU-001'),
            'min_stock_level' => 20, 'max_stock_level' => 400, 'reorder_point' => 60, 'economic_order_quantity' => 100, 'lead_time_days' => 7,
        ]));
        DB::table('mfg_items')->insert($this->row($tenantId, [
            'id' => $this->id($key, 'mfg-SKU-001'), 'item_code' => 'SKU-001', 'name' => $skus['SKU-001'][0], 'item_type' => 'finished_good', 'stock_item_id' => $this->id($key, 'item-SKU-001'),
            'product_id' => $products['SKU-001'], 'safety_stock' => 15, 'lead_time_days' => 14,
        ]));

        $this->seedSales($tenantId, $key, $customerId, $warehouseId, $products);

        foreach ($skus as $sku => [, , $deviations, $meanLeadTime]) {
            $this->seedPurchases($tenantId, $key, $supplierId, $products[$sku], $sku, $deviations, $meanLeadTime);
        }
    }

    /**
     * @param  array<string, string>  $products
     */
    private function seedSales(string $tenantId, string $key, string $customerId, string $warehouseId, array $products): void
    {
        $closedActuals = [3 => 82.0, 2 => 91.0, 1 => 77.0];
        $closedForecasts = [3 => 80.0, 2 => 95.0, 1 => 72.0];
        $noise = [1.02, 0.97, 1.01, 0.99, 1.03, 0.98, 1.00, 1.02, 0.98];

        for ($back = 24; $back >= 1; $back--) {
            $month = $this->today->startOfMonth()->subMonthsNoOverflow($back);
            $position = 24 - $back;
            $quantities = [
                'SKU-001' => round(100 * self::SeasonalIndex[(int) $month->format('n') - 1] * (1 + 0.12 * $position / 12) * $noise[$position % count($noise)]),
                'SKU-002' => 120.0 + self::FlatDemandDeviations[$position],
                'SKU-003' => $closedActuals[$back] ?? 80.0 + [0, 3, -2, 4, -3, 1][$position % 6],
            ];

            foreach ($quantities as $sku => $quantity) {
                $orderId = $this->id($key, "so-{$sku}-{$month->format('Y-m')}");
                DB::table('sales_orders')->insert($this->row($tenantId, [
                    'id' => $orderId, 'contact_id' => $customerId, 'order_number' => "SO-{$key}-{$sku}-{$month->format('Ym')}", 'date' => $month->addDays(14)->toDateString(),
                    'status' => 'confirmed', 'warehouse_id' => $warehouseId, 'total_net' => $quantity * 100, 'total_gross' => $quantity * 100,
                ]));
                DB::table('sales_order_items')->insert($this->row($tenantId, [
                    'id' => $this->id($key, "soi-{$sku}-{$month->format('Y-m')}"), 'sales_order_id' => $orderId, 'product_id' => $products[$sku], 'description' => $sku,
                    'quantity' => $quantity, 'unit_price' => 100, 'line_total' => $quantity * 100, 'warehouse_id' => $warehouseId,
                ]));
            }

            if (isset($closedForecasts[$back])) {
                DB::table('sku_demand_forecasts')->insert($this->row($tenantId, [
                    'id' => $this->id($key, "forecast-SKU-003-{$month->format('Y-m')}"), 'product_id' => $products['SKU-003'], 'warehouse_id' => $warehouseId,
                    'forecast_period_start' => $month->toDateString(), 'forecast_period_end' => $month->endOfMonth()->toDateString(), 'predicted_demand_qty' => $closedForecasts[$back],
                    'confidence_lower_bound' => $closedForecasts[$back] - 10, 'confidence_upper_bound' => $closedForecasts[$back] + 10, 'recommended_reorder_point' => 30,
                    'recommended_safety_stock' => 12, 'seasonality_index' => 1, 'supplier_lead_time_avg_days' => 10, 'supplier_lead_time_std_dev_days' => 0, 'model' => 'seeded',
                ]));
            }
        }
    }

    /**
     * @param  list<int>  $deviations
     */
    private function seedPurchases(string $tenantId, string $key, string $supplierId, string $productId, string $sku, array $deviations, int $meanLeadTime): void
    {
        foreach ($deviations as $index => $deviation) {
            $receipt = $this->today->subDays(20 + $index * 30);
            $order = $receipt->subDays($meanLeadTime + $deviation);
            $orderId = $this->id($key, "po-{$sku}-{$index}");
            $itemId = $this->id($key, "poi-{$sku}-{$index}");
            $receiptId = $this->id($key, "gr-{$sku}-{$index}");

            DB::table('purchase_orders')->insert($this->row($tenantId, ['id' => $orderId, 'supplier_id' => $supplierId, 'order_number' => "PO-{$key}-{$sku}-".($index + 1), 'date' => $order->toDateString(), 'status' => 'received']));
            DB::table('purchase_order_items')->insert($this->row($tenantId, ['id' => $itemId, 'purchase_order_id' => $orderId, 'product_id' => $productId, 'description' => $sku, 'quantity' => 100, 'quantity_received' => 100]));
            DB::table('goods_receipts')->insert($this->row($tenantId, ['id' => $receiptId, 'supplier_id' => $supplierId, 'purchase_order_id' => $orderId, 'receipt_number' => "GR-{$key}-{$sku}-".($index + 1), 'date' => $receipt->toDateString()]));
            DB::table('goods_receipt_items')->insert($this->row($tenantId, ['id' => $this->id($key, "gri-{$sku}-{$index}"), 'goods_receipt_id' => $receiptId, 'purchase_order_item_id' => $itemId, 'product_id' => $productId, 'description' => $sku, 'quantity' => 100]));
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function row(string $tenantId, array $values): array
    {
        return ['tenant_id' => $tenantId, 'created_at' => now(), 'updated_at' => now()] + $values;
    }

    /**
     * The id of a seeded record: tenant 'tenant', customers 'customer-{low|moderate|high|critical}', products 'product-SKU-001', 'warehouse'.
     */
    public static function demoId(string $tenantKey, string $name): string
    {
        return Uuid::uuid5(self::UuidNamespace, "{$tenantKey}:{$name}")->toString();
    }

    private function id(string $tenantKey, string $name): string
    {
        return self::demoId($tenantKey, $name);
    }
}
