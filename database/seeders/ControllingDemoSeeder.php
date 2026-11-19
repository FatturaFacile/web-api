<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Controlling\Models\CostAllocationRule;
use Modules\Controlling\Services\FinancialKpiService;
use Modules\Controlling\Services\MarginFactSynchronizer;
use Modules\Controlling\Support\Decimal;

/**
 * Deterministic demo company for the controlling suite (no random numbers): the cost center tree CC-100 .. CC-400, three
 * jobs, four product families, twelve products (one loss maker, one volume trap), six customers in CH and IT, three sales
 * agents, a full prior year (2025) and the first eight months of 2026 as margin facts and analytical ledger lines, the
 * monthly working capital snapshots and the two allocation rules (CC-100 fixed percentages, CC-200 labor hours).
 *
 * The sales are written straight to the read-models (facts and analytical ledger) so that the dataset does not depend on
 * the invoicing, procurement and inventory tables; `bi:rebuild-facts` rebuilds the facts from real posted invoices.
 */
final class ControllingDemoSeeder extends Seeder
{
    public const int PriorYear = 2025;

    public const int Year = 2026;

    public const int ActualMonths = 8;

    private const array Seasonality = [0.80, 0.85, 0.95, 1.00, 1.05, 1.10, 0.90, 0.70, 1.10, 1.15, 1.20, 1.30];

    /**
     * @return array<string, mixed> ids by name, for the verification command
     */
    public function run(?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::query()->where('name', 'Controlling Demo')->first()
            ?? Tenant::create(['name' => 'Controlling Demo', 'legal_name' => 'Controlling Demo AG', 'country' => 'CH', 'default_currency' => 'EUR']);
        $tenantId = (string) $tenant->id;
        app(TenantContext::class)->setTenant($tenant);
        $now = now();

        return DB::transaction(function () use ($tenantId, $now): array {
            $uuid = static fn (): string => (string) Str::uuid7();
            // Cost center tree.
            $centers = [];
            foreach ([
                ['CC-100', 'General Admin', 'administrative', null], ['CC-200', 'IT & Facility Auxiliary', 'auxiliary_service', null],
                ['CC-300', 'Production Plant A', 'production', null], ['CC-310', 'CNC Machining', 'production', 'CC-300'],
                ['CC-320', 'Assembly', 'production', 'CC-300'], ['CC-400', 'Commercial & Sales', 'commercial', null],
            ] as [$code, $name, $type, $parent]) {
                $centers[$code] = $uuid();
                DB::table('analytical_cost_centers')->insert(['id' => $centers[$code], 'tenant_id' => $tenantId, 'parent_id' => $parent === null ? null : $centers[$parent], 'code' => $code, 'name' => $name, 'center_type' => $type, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }

            // Families, products, customers, agents.
            $families = [];
            foreach (['Machined Parts', 'Assemblies', 'Spare Parts', 'Services Kits'] as $name) {
                $families[] = $uuid();
                DB::table('product_categories')->insert(['id' => end($families), 'tenant_id' => $tenantId, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            }
            $products = [];
            foreach (range(0, 11) as $i) {
                // Index 10 is the loss maker (sold below cost), 11 the volume trap (huge volume, thin margin).
                $price = match ($i) {
                    10 => 80.0, 11 => 10.5, default => 100.0 + $i * 25,
                };
                $cost = match ($i) {
                    10 => 95.0, 11 => 10.0, default => 60.0 + $i * 12,
                };
                $products[$i] = ['id' => $uuid(), 'price' => $price, 'cost' => $cost, 'family' => $families[$i % 4], 'volume' => $i === 11 ? 4000 : ($i === 10 ? 60 : 40 + $i * 5)];
                DB::table('products')->insert(['id' => $products[$i]['id'], 'tenant_id' => $tenantId, 'sku' => sprintf('SKU-%03d', $i + 1), 'name' => ($i === 10 ? 'Loss Maker ' : ($i === 11 ? 'Volume Trap ' : 'Product ')).($i + 1), 'cost_price' => $cost, 'sale_price' => $price, 'category_id' => $families[$i % 4], 'created_at' => $now, 'updated_at' => $now]);
            }
            $customers = [];
            foreach ([['Alpina SA', 'CH', 'TI', 'LU'], ['Zurich Tools AG', 'CH', 'ZH', 'ZH'], ['Geneve Industrie', 'CH', 'GE', 'GE'], ['Lombarda Srl', 'IT', 'LO', 'MI'], ['Latium Spa', 'IT', 'LA', 'RM'], ['Veneta Srl', 'IT', 'VE', 'VR']] as $i => [$name, $country, $region, $province]) {
                $customers[$i] = ['id' => $uuid(), 'country' => $country, 'region' => $region, 'province' => $province];
                DB::table('contacts')->insert(['id' => $customers[$i]['id'], 'tenant_id' => $tenantId, 'type' => 'customer', 'country' => $country, 'company_name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            }
            $agents = [];
            foreach ([['REP-1', 'Marco Rossi', 3.0], ['REP-2', 'Anna Keller', 2.5], ['REP-3', 'Luca Bianchi', 4.0]] as $i => [$code, $name, $rate]) {
                $agents[$i] = ['id' => $uuid(), 'rate' => $rate];
                DB::table('sales_representatives')->insert(['id' => $agents[$i]['id'], 'tenant_id' => $tenantId, 'rep_code' => $code, 'name' => $name, 'default_commission_rate' => $rate, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }

            // Jobs.
            $jobs = [];
            foreach ([['JOB-001', 'Gearbox housing series', 'CC-310', 180000, 120000], ['JOB-002', 'Custom assembly line', 'CC-320', 240000, 170000], ['JOB-003', 'Retrofit programme', 'CC-310', 90000, 62000]] as $i => [$code, $name, $cc, $contract, $budget]) {
                $jobs[$i] = $uuid();
                DB::table('analytical_jobs')->insert(['id' => $jobs[$i], 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'customer_id' => $customers[$i]['id'], 'cost_center_id' => $centers[$cc], 'status' => 'in_progress', 'start_date' => self::PriorYear.'-10-01', 'contract_value' => $contract, 'estimated_cost_budget' => $budget, 'created_at' => $now, 'updated_at' => $now]);
            }

            // Sales facts and analytical ledger.
            $facts = app(MarginFactSynchronizer::class);
            $factRows = [];
            $ledger = [];
            $monthly = [];
            foreach ([self::PriorYear => 12, self::Year => self::ActualMonths] as $year => $months) {
                foreach (range(1, $months) as $month) {
                    $date = sprintf('%04d-%02d-%02d', $year, $month, 28);
                    $monthly[$year][$month] = ['gross' => '0.0000', 'cogs' => '0.0000'];
                    $season = self::Seasonality[$month - 1] * ($year === self::Year ? 1.06 : 1.0);
                    foreach ($products as $i => $product) {
                        foreach ([0, 3] as $slot) {
                            $customer = $customers[($i + $month + $slot) % 6];
                            $agent = $agents[($i + $month + $slot) % 3];
                            $department = $centers[$i % 2 === 0 ? 'CC-310' : 'CC-320'];
                            $job = $i < 3 ? $jobs[$i] : null;
                            $quantity = (string) round($product['volume'] * $season / 2, 2);
                            $row = $this->saleRow($facts, $tenantId, $date, 'invoice', $quantity, $product, $customer, $agent, $department, $job, $i % 4 === 0 ? 0.04 : 0.0);
                            $factRows[] = $row;
                            array_push($ledger, ...$this->ledgerRows($tenantId, $date, $row, 'sales_invoice'));
                            $monthly[$year][$month]['gross'] = Decimal::add($monthly[$year][$month]['gross'], $row['gross_revenue']);
                            $monthly[$year][$month]['cogs'] = Decimal::add($monthly[$year][$month]['cogs'], $row['direct_cogs_amount']);
                        }
                    }
                    // One credit note a month (5% of a product's monthly quantity).
                    $i = $month % 12;
                    $product = $products[$i];
                    $quantity = (string) round($product['volume'] * $season * 0.05, 2);
                    $row = $this->saleRow($facts, $tenantId, $date, 'credit_note', Decimal::neg($quantity), $product, $customers[$i % 6], $agents[$i % 3], $centers[$i % 2 === 0 ? 'CC-310' : 'CC-320'], $i < 3 ? $jobs[$i] : null, 0.0);
                    $factRows[] = $row;
                    array_push($ledger, ...$this->ledgerRows($tenantId, $date, $row, 'credit_note'));
                    $monthly[$year][$month]['gross'] = Decimal::add($monthly[$year][$month]['gross'], $row['gross_revenue']);
                    $monthly[$year][$month]['cogs'] = Decimal::add($monthly[$year][$month]['cogs'], $row['direct_cogs_amount']);

                    // Overhead cost lines of the indirect centers.
                    foreach ([['CC-100', 'fixed_overhead', 14000], ['CC-200', 'fixed_overhead', 9000], ['CC-300', 'variable_overhead', 5000], ['CC-400', 'opex', 7500]] as [$code, $nature, $amount]) {
                        $ledger[] = $this->ledgerRow($tenantId, $date, 'manual_analytical', $nature, Decimal::norm($amount * (0.95 + $month / 100)), '0', ['cost_center_id' => $centers[$code], 'description' => 'Period cost '.$code]);
                    }
                }
            }
            $facts->insert($factRows);
            foreach (array_chunk($ledger, 200) as $chunk) {
                DB::table('analytical_ledger_entries')->insert(array_map(static function (array $r): array {
                    ksort($r);

                    return $r;
                }, $chunk));
            }

            // Working capital snapshots: receivables about 45 days of sales, payables 30 days of purchases, stock 60 days of COGS.
            $kpi = app(FinancialKpiService::class);
            foreach ($monthly as $year => $byMonth) {
                foreach ($byMonth as $month => $figures) {
                    $date = CarbonImmutable::create($year, $month, 1)->endOfMonth();
                    $purchases = Decimal::mul($figures['cogs'], '1.05');
                    $ar = Decimal::mul($figures['gross'], '1.5');
                    $kpi->store($tenantId, $date, [
                        'accounts_receivable_balance' => $ar, 'overdue_receivables_balance' => Decimal::mul($ar, '0.12'), 'accounts_payable_balance' => $purchases,
                        'inventory_valuation_balance' => Decimal::mul($figures['cogs'], '2'), 'cash_and_bank_balance' => Decimal::add(250000, $month * 4000),
                        'credit_sales_period' => $figures['gross'], 'credit_purchases_period' => $purchases, 'cogs_period' => $figures['cogs'],
                    ]);
                }
            }

            // Allocation rules: CC-100 by fixed percentages (step 1), CC-200 by direct labor hours (step 2).
            CostAllocationRule::create(['tenant_id' => $tenantId, 'name' => 'General admin by percentage', 'source_cost_center_id' => $centers['CC-100'], 'driver_type' => 'fixed_percentage', 'step_order' => 1, 'is_active' => true, 'recipients' => [
                ['type' => 'cost_center', 'id' => $centers['CC-310'], 'weight' => 40], ['type' => 'cost_center', 'id' => $centers['CC-320'], 'weight' => 35],
                ['type' => 'job', 'id' => $jobs[0], 'weight' => 10], ['type' => 'job', 'id' => $jobs[1], 'weight' => 8], ['type' => 'job', 'id' => $jobs[2], 'weight' => 7],
            ]]);
            CostAllocationRule::create(['tenant_id' => $tenantId, 'name' => 'IT and facility by labor hours', 'source_cost_center_id' => $centers['CC-200'], 'driver_type' => 'direct_labor_hours', 'step_order' => 2, 'is_active' => true, 'recipients' => [
                ['type' => 'cost_center', 'id' => $centers['CC-310']], ['type' => 'cost_center', 'id' => $centers['CC-320']],
                ['type' => 'job', 'id' => $jobs[0]], ['type' => 'job', 'id' => $jobs[1]], ['type' => 'job', 'id' => $jobs[2]],
            ]]);

            return ['tenant_id' => $tenantId, 'centers' => $centers, 'jobs' => $jobs, 'families' => $families, 'products' => array_column($products, 'id'), 'customers' => array_column($customers, 'id'), 'agents' => array_column($agents, 'id')];
        });
    }

    /**
     * @param  array{id: string, price: float, cost: float, family: string}  $product
     * @param  array{id: string, country: string, region: string, province: string}  $customer
     * @param  array{id: string, rate: float}  $agent
     * @return array<string, mixed>
     */
    private function saleRow(MarginFactSynchronizer $facts, string $tenantId, string $date, string $type, string $quantity, array $product, array $customer, array $agent, string $department, ?string $job, float $discountRate): array
    {
        $gross = Decimal::mul($quantity, $product['price']);
        $discount = Decimal::mul($gross, $discountRate);
        $net = Decimal::sub($gross, $discount);

        return $facts->row($tenantId, $date, [
            'document_type' => $type, 'product_id' => $product['id'], 'product_family_id' => $product['family'], 'customer_id' => $customer['id'],
            'country_code' => $customer['country'], 'region_code' => $customer['region'], 'province_code' => $customer['province'], 'sales_agent_id' => $agent['id'],
            'production_department_id' => $department, 'cost_center_id' => $department, 'job_id' => $job, 'quantity_sold' => $quantity,
            'gross_revenue' => $gross, 'discount_amount' => $discount, 'direct_cogs_amount' => Decimal::mul($quantity, $product['cost']),
            'commission_amount' => Decimal::mul($net, $agent['rate'] / 100), 'logistics_cost_amount' => Decimal::mul($net, '0.015'),
        ]);
    }

    /**
     * Analytical lines of a fact row: net revenue, direct material (70% of COGS) and direct labor (30%, 40 per hour).
     *
     * @param  array<string, mixed>  $fact
     * @return list<array<string, mixed>>
     */
    private function ledgerRows(string $tenantId, string $date, array $fact, string $source): array
    {
        $dims = ['cost_center_id' => $fact['cost_center_id'], 'job_id' => $fact['job_id'], 'product_id' => $fact['product_id'], 'product_family_id' => $fact['product_family_id'], 'customer_id' => $fact['customer_id'], 'sales_agent_id' => $fact['sales_agent_id']];
        $cogs = Decimal::norm($fact['direct_cogs_amount']);
        $labor = Decimal::mul($cogs, '0.3');

        return [
            $this->ledgerRow($tenantId, $date, $source, 'revenue', Decimal::norm($fact['net_revenue']), Decimal::norm($fact['quantity_sold']), $dims),
            $this->ledgerRow($tenantId, $date, $source, 'direct_material', Decimal::sub($cogs, $labor), '0', $dims),
            $this->ledgerRow($tenantId, $date, $source, 'direct_labor', $labor, Decimal::div($labor, 40), $dims),
        ];
    }

    /**
     * @param  array<string, mixed>  $dims
     * @return array<string, mixed>
     */
    private function ledgerRow(string $tenantId, string $date, string $source, string $nature, string $amount, string $quantity, array $dims): array
    {
        return $dims + [
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'posting_date' => $date, 'fiscal_year' => (int) substr($date, 0, 4), 'fiscal_month' => (int) substr($date, 5, 2),
            'source_type' => $source, 'source_id' => null, 'account_code' => null, 'nature_type' => $nature, 'amount' => $amount, 'quantity' => $quantity,
            'is_allocated' => false, 'created_at' => now(), 'updated_at' => now(),
        ] + ['cost_center_id' => null, 'job_id' => null, 'product_id' => null, 'product_family_id' => null, 'customer_id' => null, 'sales_agent_id' => null, 'description' => $dims['description'] ?? null];
    }
}
