<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\GdoTradeTerms\Domain\Enums\LineStatus;
use Modules\GdoTradeTerms\Domain\Enums\RunStatus;
use Modules\GdoTradeTerms\Domain\Enums\SettlementKind;
use Modules\GdoTradeTerms\Domain\Enums\SourceType;
use Modules\GdoTradeTerms\Models\GdoAccrualLine;
use Modules\GdoTradeTerms\Models\GdoAccrualRun;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Services\ContractService;
use Modules\GdoTradeTerms\Services\DeliveryPointService;
use Modules\GdoTradeTerms\Services\PromotionService;
use Modules\GdoTradeTerms\Services\ScaleService;
use Modules\GdoTradeTerms\Services\SettlementService;

/**
 * Demo GDO trade terms for the first company of the installation: an Italian chain (Conad) and a Swiss one (Migros) with
 * on-invoice discounts, retroactive and progressive year-end rebate scales (turnover and quantity, total and incremental),
 * sell-in flyers, sell-out, new store, listing, display and Ce.Di. logistics contributions, delivery points, a sample accrual
 * run of the previous month and the settlement proposal it feeds. Idempotent by contract code; does nothing without a company
 * or a user. Every name and description below is demo content, the interface texts are in the frontend dictionary seeders.
 */
final class GdoTradeTermsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('created_at')->first();
        $user = $tenant?->users()->first();

        if ($tenant === null || $user === null) {
            return;
        }

        app(TenantContext::class)->runWithTenant($tenant, function () use ($tenant, $user): void {
            $year = (int) now()->format('Y');
            $first = $this->conad($tenant, (string) $user->id, $year);
            $this->migros($tenant, (string) $user->id, $year);
            $this->sampleAccrualAndSettlement($tenant, $first, (string) $user->id);
        });
    }

    private function chain(string $name, string $country): Contact
    {
        return Contact::query()->firstOrCreate(['company_name' => $name], ['type' => 'customer', 'country' => $country]);
    }

    private function conad(Tenant $tenant, string $userId, int $year): GdoContract
    {
        $code = "GDO-IT-CONAD-{$year}";
        $existing = GdoContract::query()->where('code', $code)->first();

        if ($existing !== null) {
            return $existing;
        }

        $contracts = app(ContractService::class);
        $scales = app(ScaleService::class);
        $promotions = app(PromotionService::class);
        $points = app(DeliveryPointService::class);
        $retailer = $this->chain('Conad Società Cooperativa', 'IT');

        $contract = $contracts->create([
            'code' => $code, 'name' => "Conad — accordo quadro {$year}", 'contact_id' => $retailer->id, 'jurisdiction' => 'IT', 'currency' => 'EUR',
            'starts_on' => "{$year}-01-01", 'ends_on' => "{$year}-12-31",
            'discount_1_percent' => '3', 'discount_2_percent' => '2', 'discount_3_percent' => '1.5', 'cedi_discount_percent' => '1', 'pallet_discount_amount' => '4.50',
            'tolerance_percent' => '2', 'notes' => 'Primo, secondo e terzo sconto in fattura, sconto Ce.Di. per consegna a pallet intero.',
        ], (string) $tenant->id, $userId);

        $cedi = $points->save(['contact_id' => $retailer->id, 'code' => 'CEDI-BO', 'name' => 'Ce.Di. Bologna', 'point_type' => 'cedi', 'city' => 'Bologna', 'postal_code' => '40128', 'country' => 'IT'], (string) $tenant->id, $userId);
        $store = $points->save(['contact_id' => $retailer->id, 'code' => 'PV-0412', 'name' => 'Conad Superstore Modena', 'point_type' => 'store', 'city' => 'Modena', 'postal_code' => '41121', 'country' => 'IT'], (string) $tenant->id, $userId);

        $scales->save($contract, null, [
            'code' => 'PFA-FAT', 'name' => 'Premio fine anno sul fatturato (retroattivo)', 'tier_mode' => 'retroactive', 'basis' => 'turnover', 'volume_mode' => 'total',
            'tiers' => [
                ['threshold' => '500000', 'rate_percent' => '1.00', 'flat_amount' => null, 'label' => 'Bronzo'],
                ['threshold' => '1000000', 'rate_percent' => '1.50', 'flat_amount' => null, 'label' => 'Argento'],
                ['threshold' => '2000000', 'rate_percent' => '2.25', 'flat_amount' => '5000', 'label' => 'Oro'],
            ],
        ], $userId);
        $scales->save($contract, null, [
            'code' => 'PFA-CRE', 'name' => 'Premio di crescita sui colli venduti (progressivo)', 'tier_mode' => 'progressive', 'basis' => 'quantity', 'volume_mode' => 'incremental',
            'baseline_source' => 'manual', 'baseline_value' => '80000',
            'tiers' => [
                ['threshold' => '5000', 'rate_percent' => '1.00', 'flat_amount' => null, 'label' => 'Crescita base'],
                ['threshold' => '15000', 'rate_percent' => '2.00', 'flat_amount' => null, 'label' => 'Crescita alta'],
            ],
        ], $userId);

        $this->promotion($promotions, $contract, $userId, ['code' => 'VOL-PRIMAVERA', 'name' => 'Volantino primavera', 'promotion_type' => 'sell_in_flyer', 'date_basis' => 'order', 'starts_on' => "{$year}-03-01", 'ends_on' => "{$year}-03-31", 'contribution_mode' => 'percent_of_sales', 'rate_percent' => '4', 'notes' => 'Sconto promozionale sul venduto ordinato nella finestra del volantino.']);
        $this->promotion($promotions, $contract, $userId, ['code' => 'SELL-OUT-Q2', 'name' => 'Contributo sul sell-out del secondo trimestre', 'promotion_type' => 'sell_out', 'date_basis' => 'delivery', 'starts_on' => "{$year}-04-01", 'ends_on' => "{$year}-06-30", 'contribution_mode' => 'percent_of_sell_out', 'rate_percent' => '2', 'cap_amount' => '20000']);
        $this->promotion($promotions, $contract, $userId, ['code' => 'APERTURA-MO', 'name' => 'Apertura punto vendita Modena', 'promotion_type' => 'new_store_opening', 'date_basis' => 'delivery', 'starts_on' => "{$year}-05-01", 'ends_on' => "{$year}-05-31", 'contribution_mode' => 'fixed_amount', 'fixed_amount' => '6000', 'fixed_accrual' => 'on_start', 'delivery_point_ids' => [$store->id]]);
        $this->promotion($promotions, $contract, $userId, ['code' => 'REF-ANNO', 'name' => 'Referenziamento annuale', 'promotion_type' => 'listing_fee', 'date_basis' => 'order', 'starts_on' => "{$year}-01-01", 'ends_on' => "{$year}-12-31", 'contribution_mode' => 'fixed_amount', 'fixed_amount' => '12000', 'fixed_accrual' => 'linear']);
        $this->promotion($promotions, $contract, $userId, ['code' => 'GONDOLA-NAT', 'name' => 'Testata di gondola — periodo natalizio', 'promotion_type' => 'display', 'date_basis' => 'delivery', 'starts_on' => "{$year}-11-15", 'ends_on' => "{$year}-12-24", 'contribution_mode' => 'fixed_amount', 'fixed_amount' => '8000', 'fixed_accrual' => 'linear']);
        $this->promotion($promotions, $contract, $userId, ['code' => 'LOG-CEDI', 'name' => 'Contributo logistico Ce.Di. Bologna', 'promotion_type' => 'cedi_logistics', 'date_basis' => 'delivery', 'starts_on' => "{$year}-01-01", 'ends_on' => "{$year}-12-31", 'contribution_mode' => 'percent_of_sales', 'rate_percent' => '0.8', 'delivery_point_ids' => [$cedi->id]]);

        return $contracts->transition($contract, 'activate', $userId);
    }

    private function migros(Tenant $tenant, string $userId, int $year): void
    {
        $code = "GDO-CH-MIGROS-{$year}";

        if (GdoContract::query()->where('code', $code)->exists()) {
            return;
        }

        $contracts = app(ContractService::class);
        $scales = app(ScaleService::class);
        $promotions = app(PromotionService::class);
        $retailer = $this->chain('Migros-Genossenschafts-Bund', 'CH');

        $contract = $contracts->create([
            'code' => $code, 'name' => "Migros — Jahresvereinbarung {$year}", 'contact_id' => $retailer->id, 'jurisdiction' => 'CH', 'currency' => 'CHF',
            'starts_on' => "{$year}-01-01", 'ends_on' => "{$year}-12-31", 'discount_1_percent' => '2.5', 'discount_2_percent' => '1.5',
            'tolerance_percent' => '1.5', 'notes' => 'Jahresrückvergütung und Umsatzbonus mit Gutschrift; Werbekostenbeiträge gegen Rechnung.',
        ], (string) $tenant->id, $userId);

        $scales->save($contract, null, [
            'code' => 'JRV-UMS', 'name' => 'Jahresrückvergütung auf dem Umsatz', 'tier_mode' => 'retroactive', 'basis' => 'turnover', 'volume_mode' => 'total',
            'tiers' => [
                ['threshold' => '2000000', 'rate_percent' => '0.50', 'flat_amount' => null, 'label' => 'Stufe 1'],
                ['threshold' => '5000000', 'rate_percent' => '1.00', 'flat_amount' => null, 'label' => 'Stufe 2'],
                ['threshold' => '10000000', 'rate_percent' => '1.50', 'flat_amount' => null, 'label' => 'Stufe 3'],
            ],
        ], $userId);
        $scales->save($contract, null, [
            'code' => 'UMS-MENGE', 'name' => 'Umsatzbonus auf der Menge (progressiv)', 'tier_mode' => 'progressive', 'basis' => 'quantity', 'volume_mode' => 'total',
            'tiers' => [
                ['threshold' => '100000', 'rate_percent' => '0.50', 'flat_amount' => null, 'label' => null],
                ['threshold' => '250000', 'rate_percent' => '1.00', 'flat_amount' => '2500', 'label' => null],
            ],
        ], $userId);

        $this->promotion($promotions, $contract, $userId, ['code' => 'AKTION-FRUEHLING', 'name' => 'Frühlingsaktion', 'promotion_type' => 'sell_in_flyer', 'date_basis' => 'delivery', 'starts_on' => "{$year}-04-01", 'ends_on' => "{$year}-04-30", 'contribution_mode' => 'percent_of_sales', 'rate_percent' => '3.5']);
        $this->promotion($promotions, $contract, $userId, ['code' => 'LISTUNG', 'name' => 'Listungsbeitrag Neuprodukte', 'promotion_type' => 'listing_fee', 'date_basis' => 'order', 'starts_on' => "{$year}-01-01", 'ends_on' => "{$year}-12-31", 'contribution_mode' => 'fixed_amount', 'fixed_amount' => '15000', 'fixed_accrual' => 'linear']);
        $this->promotion($promotions, $contract, $userId, ['code' => 'PLATZIERUNG', 'name' => 'Bevorzugte Platzierung Kopfplatz', 'promotion_type' => 'display', 'date_basis' => 'delivery', 'starts_on' => "{$year}-09-01", 'ends_on' => "{$year}-10-31", 'contribution_mode' => 'fixed_amount', 'fixed_amount' => '9000', 'fixed_accrual' => 'linear']);
        $this->promotion($promotions, $contract, $userId, ['code' => 'LOGISTIK', 'name' => 'Logistikbeitrag Verteilzentrum', 'promotion_type' => 'cedi_logistics', 'date_basis' => 'delivery', 'starts_on' => "{$year}-01-01", 'ends_on' => "{$year}-12-31", 'contribution_mode' => 'per_unit', 'unit_amount' => '0.35']);

        $contracts->transition($contract, 'activate', $userId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function promotion(PromotionService $promotions, GdoContract $contract, string $userId, array $data): void
    {
        $promotion = $promotions->save($contract, null, $data, $userId);
        $promotions->transition($promotion, 'activate', $userId);
    }

    /**
     * A posted accrual of the previous month (no journal entry: it is demo content) and the settlement proposal it feeds.
     */
    private function sampleAccrualAndSettlement(Tenant $tenant, GdoContract $contract, string $userId): void
    {
        $month = now()->subMonthNoOverflow()->startOfMonth();
        $key = $month->format('Y-m');

        if (GdoAccrualRun::query()->where('period_key', $key)->exists()) {
            return;
        }

        $contract->load(['scales', 'promotions']);
        $scale = $contract->scales->firstWhere('code', 'PFA-FAT');
        $listing = $contract->promotions->firstWhere('code', 'REF-ANNO');

        if ($scale === null || $listing === null) {
            return;
        }

        $run = GdoAccrualRun::create([
            'tenant_id' => $tenant->id, 'period_key' => $key, 'period_start' => $month->toDateString(), 'period_end' => $month->copy()->endOfMonth()->toDateString(),
            'status' => RunStatus::Posted->value, 'unique_key' => $tenant->id.':'.$key, 'total_accrued' => '5000.0000', 'contracts_count' => 1,
            'run_by' => $userId, 'posted_at' => now(), 'created_by' => $userId,
        ]);

        foreach ([[SourceType::Scale, $scale->id, $scale->code.' · '.$scale->name, '4000.00', '400000.00', '1.0000'], [SourceType::Promotion, $listing->id, $listing->code.' · '.$listing->name, '1000.00', '1000.00', null]] as [$type, $id, $label, $amount, $base, $rate]) {
            GdoAccrualLine::create([
                'tenant_id' => $tenant->id, 'run_id' => $run->id, 'contract_id' => $contract->id, 'source_type' => $type->value, 'source_id' => $id, 'source_label' => $label,
                'currency' => $contract->currency, 'base_value' => $base, 'earned_cumulative' => $amount, 'previously_accrued' => '0', 'delta_amount' => $amount,
                'applied_rate_percent' => $rate, 'status' => LineStatus::Accrued->value, 'created_by' => $userId,
            ]);
        }

        app(SettlementService::class)->propose($contract, SettlementKind::Quarterly->value, $month->copy()->startOfQuarter(), $month->copy()->endOfMonth(), $userId);
    }
}
