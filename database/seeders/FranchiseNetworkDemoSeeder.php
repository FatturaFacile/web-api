<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Modules\FranchiseNetwork\Models\AuditTemplate;
use Modules\FranchiseNetwork\Models\FranchiseFormula;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Services\AgreementService;
use Modules\FranchiseNetwork\Services\AuditService;
use Modules\FranchiseNetwork\Services\BenchmarkService;
use Modules\FranchiseNetwork\Services\CatalogPushService;
use Modules\FranchiseNetwork\Services\LoyaltyLedgerService;
use Modules\FranchiseNetwork\Services\PurchaseCommitmentService;
use Modules\FranchiseNetwork\Services\RoyaltyBillingService;
use Modules\FranchiseNetwork\Services\SellOutService;
use Modules\FranchiseNetwork\Services\StoreService;

/**
 * Demo franchise network for the first company of the installation, acting as franchisor: four Italian franchisee stores (Milano,
 * Roma, Torino, Bologna), one Swiss franchisee store (Lugano, CHF, recommended prices only) and one directly operated flagship (Como),
 * with their affiliation agreements (L. 129/2004 term, exclusivity zones, deferred entry fee, minimum purchases, bank guarantee,
 * SEPA SDD / LSV+ mandates), thirteen months of sell-out, purchases, shared loyalty points, a catalogue and a promotion push, a
 * store audit, the benchmark snapshot and the royalty run of the last month (left uninvoiced). Idempotent by store code; does nothing
 * without a company or a user. Names are demo content; every interface text comes from the dictionary and catalogue seeders.
 */
final class FranchiseNetworkDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('created_at')->first();
        $user = $tenant?->users()->first();

        if ($tenant === null || $user === null) {
            return;
        }

        $this->call(FranchiseNetworkReferenceSeeder::class);

        app(TenantContext::class)->runWithTenant($tenant, function () use ($user): void {
            if (FranchiseStore::query()->where('code', 'MI-001')->exists()) {
                return;
            }

            $userId = (string) $user->id;
            $stores = $this->stores($userId);
            $this->agreements($stores, $userId);
            $this->operations($stores, $userId);
            $this->pushes($userId);
            $this->audit($stores['MI-001'], $userId);

            $period = now()->subMonthNoOverflow()->format('Y-m');
            app(BenchmarkService::class)->snapshot($period);
            app(RoyaltyBillingService::class)->calculate($period, $userId);
        });
    }

    /**
     * @return array<string, FranchiseStore>
     */
    private function stores(string $userId): array
    {
        $service = app(StoreService::class);
        $rows = [
            ['code' => 'MI-001', 'name' => 'Milano Corso Buenos Aires', 'store_type' => 'franchisee', 'country' => 'IT', 'city' => 'Milano', 'postal_code' => '20124', 'size_cluster' => 'medium', 'opened_on' => now()->subMonths(30)->toDateString(), 'vat_number' => 'IT09876540961'],
            ['code' => 'RM-001', 'name' => 'Roma Prati', 'store_type' => 'franchisee', 'country' => 'IT', 'city' => 'Roma', 'postal_code' => '00192', 'size_cluster' => 'medium', 'opened_on' => now()->subMonths(26)->toDateString(), 'vat_number' => 'IT10203040587'],
            ['code' => 'TO-001', 'name' => 'Torino Lingotto', 'store_type' => 'franchisee', 'country' => 'IT', 'city' => 'Torino', 'postal_code' => '10126', 'size_cluster' => 'medium', 'opened_on' => now()->subMonths(18)->toDateString(), 'vat_number' => 'IT11223344017'],
            ['code' => 'BO-001', 'name' => 'Bologna Centro', 'store_type' => 'franchisee', 'country' => 'IT', 'city' => 'Bologna', 'postal_code' => '40121', 'size_cluster' => 'small', 'opened_on' => now()->subMonths(2)->startOfMonth()->toDateString(), 'vat_number' => 'IT05566778901'],
            ['code' => 'LU-001', 'name' => 'Lugano Centro', 'store_type' => 'franchisee', 'country' => 'CH', 'city' => 'Lugano', 'postal_code' => '6900', 'size_cluster' => 'small', 'opened_on' => now()->subMonths(20)->toDateString(), 'uid_number' => 'CHE-123.456.789'],
            ['code' => 'CO-001', 'name' => 'Como Flagship (DOS)', 'store_type' => 'dos', 'country' => 'IT', 'city' => 'Como', 'postal_code' => '22100', 'size_cluster' => 'large', 'opened_on' => now()->subMonths(40)->toDateString()],
        ];

        $stores = [];

        foreach ($rows as $row) {
            $stores[$row['code']] = $service->save($row, null, $userId);
        }

        $service->issueLinkCode($stores['MI-001']);
        $service->issueLinkCode($stores['RM-001']);

        return $stores;
    }

    /**
     * @param  array<string, FranchiseStore>  $stores
     */
    private function agreements(array $stores, string $userId): void
    {
        $service = app(AgreementService::class);
        $formula = fn (string $code): string => (string) FranchiseFormula::query()->where('code', $code)->value('id');
        $today = CarbonImmutable::today();

        $rows = [
            ['MI-001', 'RETAIL-PROGRESSIVE-IT', ['jurisdiction' => 'IT', 'start_date' => $today->subMonths(30)->toDateString(), 'end_date' => $today->subMonths(30)->addYears(5)->subDay()->toDateString(), 'disclosure_delivered_on' => $today->subMonths(32)->toDateString(), 'signed_on' => $today->subMonths(30)->toDateString(), 'exclusivity_zone' => ['label' => 'Milano Porta Venezia', 'postal_codes' => ['20124', '20129'], 'radius_km' => 2], 'entry_fee' => 30000, 'entry_fee_deferral_months' => 60, 'min_purchase_amount' => 12000, 'guarantee_type' => 'bank_guarantee', 'guarantee_amount' => 25000, 'guarantee_reference' => 'FID-2024-0451', 'guarantee_expires_on' => $today->addDays(45)->toDateString(), 'payment_method' => 'sepa_sdd', 'mandate_reference' => 'SDD-MI-001', 'mandate_signed_on' => $today->subMonths(30)->toDateString(), 'currency' => 'EUR']],
            ['RM-001', 'RETAIL-PROGRESSIVE-IT', ['jurisdiction' => 'IT', 'start_date' => $today->subMonths(26)->toDateString(), 'end_date' => $today->subMonths(26)->addYears(3)->subDay()->toDateString(), 'disclosure_delivered_on' => $today->subMonths(28)->toDateString(), 'signed_on' => $today->subMonths(26)->toDateString(), 'exclusivity_zone' => ['label' => 'Roma Prati', 'postal_codes' => ['00192', '00193'], 'radius_km' => 1.5], 'entry_fee' => 24000, 'entry_fee_deferral_months' => 36, 'min_purchase_amount' => 10000, 'guarantee_type' => 'bank_guarantee', 'guarantee_amount' => 20000, 'guarantee_reference' => 'FID-2024-0502', 'guarantee_expires_on' => $today->addMonths(14)->toDateString(), 'payment_method' => 'sepa_sdd', 'mandate_reference' => 'SDD-RM-001', 'mandate_signed_on' => $today->subMonths(26)->toDateString(), 'currency' => 'EUR', 'renewal_notice_days' => 365]],
            ['TO-001', 'RETAIL-FLAT-6-2', ['jurisdiction' => 'IT', 'start_date' => $today->subMonths(18)->toDateString(), 'end_date' => $today->subMonths(18)->addYears(3)->subDay()->toDateString(), 'disclosure_delivered_on' => $today->subMonths(20)->toDateString(), 'signed_on' => $today->subMonths(18)->toDateString(), 'exclusivity_zone' => ['label' => 'Torino Lingotto', 'postal_codes' => ['10126', '10127'], 'radius_km' => 2], 'entry_fee' => 18000, 'entry_fee_deferral_months' => 36, 'min_purchase_amount' => 8000, 'guarantee_type' => 'deposit', 'guarantee_amount' => 10000, 'payment_method' => 'bank_transfer', 'currency' => 'EUR']],
            ['BO-001', 'FOOD-MIN-GUARANTEE', ['jurisdiction' => 'IT', 'start_date' => $today->subMonths(2)->startOfMonth()->toDateString(), 'end_date' => $today->subMonths(2)->startOfMonth()->addYears(3)->subDay()->toDateString(), 'disclosure_delivered_on' => $today->subMonths(4)->toDateString(), 'signed_on' => $today->subMonths(2)->startOfMonth()->toDateString(), 'exclusivity_zone' => ['label' => 'Bologna Centro storico', 'postal_codes' => ['40121', '40124'], 'radius_km' => 1], 'entry_fee' => 15000, 'entry_fee_deferral_months' => 36, 'min_purchase_amount' => 6000, 'guarantee_type' => 'insurance', 'guarantee_amount' => 8000, 'guarantee_expires_on' => $today->addYears(2)->toDateString(), 'payment_method' => 'sepa_sdd', 'mandate_reference' => 'SDD-BO-001', 'mandate_signed_on' => $today->subMonths(2)->toDateString(), 'currency' => 'EUR']],
            ['LU-001', 'RETAIL-SWISS-CHF', ['jurisdiction' => 'CH', 'start_date' => $today->subMonths(20)->toDateString(), 'end_date' => $today->subMonths(20)->addYears(5)->subDay()->toDateString(), 'signed_on' => $today->subMonths(20)->toDateString(), 'exclusivity_zone' => ['label' => 'Lugano Centro', 'postal_codes' => ['6900', '6901'], 'radius_km' => 3], 'entry_fee' => 40000, 'entry_fee_deferral_months' => 60, 'min_purchase_amount' => 15000, 'guarantee_type' => 'bank_guarantee', 'guarantee_amount' => 30000, 'guarantee_reference' => 'BG-CH-7781', 'guarantee_expires_on' => $today->addYears(1)->toDateString(), 'payment_method' => 'lsv_plus', 'mandate_reference' => 'LSV-LU-001', 'mandate_signed_on' => $today->subMonths(20)->toDateString(), 'currency' => 'CHF', 'non_binding_pricing' => true]],
        ];

        foreach ($rows as [$code, $formulaCode, $data]) {
            $agreement = $service->save([...$data, 'store_id' => $stores[$code]->id, 'formula_id' => $formula($formulaCode)], null, $userId);
            $service->activate($agreement, $userId);
        }
    }

    /**
     * @param  array<string, FranchiseStore>  $stores
     */
    private function operations(array $stores, string $userId): void
    {
        $sellOut = app(SellOutService::class);
        $purchases = app(PurchaseCommitmentService::class);
        $loyalty = app(LoyaltyLedgerService::class);
        $base = ['MI-001' => 92000, 'RM-001' => 78000, 'TO-001' => 61000, 'BO-001' => 34000, 'LU-001' => 70000, 'CO-001' => 150000];
        $ticket = ['MI-001' => 41, 'RM-001' => 38, 'TO-001' => 34, 'BO-001' => 29, 'LU-001' => 58, 'CO-001' => 47];
        $first = now()->subMonthsNoOverflow(13)->startOfMonth();

        for ($i = 0; $i < 13; $i++) {
            $month = $first->addMonthsNoOverflow($i);
            $period = $month->format('Y-m');
            $season = 1 + 0.12 * sin(($month->month - 3) / 12 * 2 * M_PI) + ($month->month === 12 ? 0.25 : 0);
            $growth = 1 + 0.004 * $i;

            foreach ($stores as $code => $store) {
                if ($store->opened_on !== null && $store->opened_on->startOfMonth()->greaterThan($month)) {
                    continue;
                }

                $gross = round($base[$code] * $season * $growth * (1 + (crc32($code.$period) % 9 - 4) / 100), 2);
                $tickets = (int) round($gross / $ticket[$code]);
                $sellOut->upsert($store, $period, [
                    'gross_sales' => $gross,
                    'returns' => round($gross * 0.02, 2),
                    'discounts' => round($gross * 0.03, 2),
                    'tickets_count' => $tickets,
                    'visitors_count' => (int) round($tickets / (0.30 + (crc32($period.$code) % 8) / 100)),
                    'cost_of_goods_sold' => round($gross * 0.52, 2),
                    'average_inventory_value' => round($base[$code] * 1.4, 2),
                    'source' => $code === 'LU-001' ? 'pos' : 'fiscal_printer',
                    'pos_reference' => 'POS-'.$code,
                ], $userId);

                if ($store->store_type->value === 'franchisee') {
                    $required = (float) ($purchases->agreementFor($store, $period)->min_purchase_amount ?? 0);
                    $purchases->record($store, $period, $required * (0.9 + (crc32($code.$period) % 30) / 100), $userId);
                }
            }
        }

        $period = now()->subMonthNoOverflow()->format('Y-m');

        foreach ([['c-1001', 'MI-001', 'MI-001', 'earn', 1200], ['c-1001', 'RM-001', 'MI-001', 'redeem', 500], ['c-2002', 'TO-001', 'TO-001', 'earn', 800], ['c-2002', 'MI-001', 'TO-001', 'redeem', 300]] as [$customer, $storeCode, $earned, $type, $points]) {
            $loyalty->record($stores[$storeCode], ['customer_ref' => $customer, 'type' => $type, 'points' => $points, 'earned_store_id' => $stores[$earned]->id, 'occurred_at' => CarbonImmutable::parse($period.'-15 11:00')->toIso8601String()], $userId);
        }
    }

    private function pushes(string $userId): void
    {
        $service = app(CatalogPushService::class);

        $service->dispatch($service->create([
            'push_type' => 'catalog',
            'title' => 'Collezione autunno/inverno / Autumn-winter collection',
            'target_mode' => 'all',
            'effective_from' => now()->addDays(10)->toDateString(),
            'payload' => ['items' => [
                ['sku' => 'AW-JKT-001', 'name' => 'Giacca imbottita / Padded jacket', 'price' => 189.0, 'price_type' => 'maximum', 'variants' => [['size' => 'M', 'color' => 'navy'], ['size' => 'L', 'color' => 'navy']], 'images' => [['url' => 'assets/aw/jkt-001-front.jpg']], 'recipe' => []],
                ['sku' => 'AW-KNT-014', 'name' => 'Maglia in lana / Wool jumper', 'price' => 89.0, 'price_type' => 'recommended', 'variants' => [['size' => 'S', 'color' => 'grey']], 'images' => [['url' => 'assets/aw/knt-014.jpg']], 'recipe' => []],
            ]],
        ], $userId), $userId);

        $service->dispatch($service->create([
            'push_type' => 'promotion',
            'title' => 'Black Friday',
            'target_mode' => 'all',
            'payload' => ['promotion' => ['name' => 'Black Friday', 'valid_from' => now()->addDays(20)->toDateString(), 'valid_to' => now()->addDays(23)->toDateString(), 'discount_percent' => 20]],
        ], $userId), $userId);
    }

    private function audit(FranchiseStore $store, string $userId): void
    {
        $service = app(AuditService::class);
        $template = AuditTemplate::query()->where('code', 'RETAIL-STANDARD')->firstOrFail();
        $audit = $service->start($store, $template, $userId, now()->subDays(12)->toDateString());
        $scores = ['STOCK-EXPIRY' => 3, 'VM-SEASONAL' => 2, 'CLEAN-STAFFAREA' => 3];
        $answers = array_map(static fn (array $criterion): array => ['criterion_code' => $criterion['code'], 'score' => $scores[$criterion['code']] ?? 5, 'notes' => isset($scores[$criterion['code']]) ? 'Da sistemare entro il prossimo ciclo di visita / To fix before the next visit' : null], $template->criteria);

        $service->submit($service->saveAnswers($audit, $answers, null, $userId), $userId);
    }
}
