<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IndustrialLaundry\Domain\Enums\ArticleType;
use Modules\IndustrialLaundry\Domain\Enums\CertificationStatus;
use Modules\IndustrialLaundry\Domain\Enums\GarmentEventType;
use Modules\IndustrialLaundry\Domain\Enums\LifecycleState;
use Modules\IndustrialLaundry\Domain\Enums\ScanDirection;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Models\GarmentEvent;
use Modules\IndustrialLaundry\Models\ParContract;
use Modules\IndustrialLaundry\Models\RollCage;
use Modules\IndustrialLaundry\Models\ScanSession;
use Modules\IndustrialLaundry\Models\WashBatch;
use Modules\IndustrialLaundry\Models\WashProgram;
use Modules\IndustrialLaundry\Services\ParContractService;
use Modules\IndustrialLaundry\Services\WashProgramCatalog;

/**
 * Demo industrial laundry for the first company of the installation, and the standard wash programs for every company: a hotel,
 * a nursing home (RSA), an industrial customer with personal workwear and PPE, and a Swiss hotel billed by weight, with their par
 * stock contracts, roll cages, tagged garments (clean, delivered, dormant for more than 60 days, PPE near and at the wash limit,
 * workwear in lockers), a received soiled roll cage and a RABC wash batch. Idempotent by contract code and EPC; does nothing
 * without a company or a user. Every name below is demo content, the interface texts are in the frontend dictionary seeders.
 */
final class IndustrialLaundryDemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Tenant::query()->get() as $company) {
            app(TenantContext::class)->runWithTenant($company, fn () => app(WashProgramCatalog::class)->ensure((string) $company->id));
        }

        $tenant = Tenant::query()->orderBy('created_at')->first();
        $user = $tenant?->users()->first();

        if ($tenant === null || $user === null) {
            return;
        }

        app(TenantContext::class)->runWithTenant($tenant, function () use ($tenant, $user): void {
            $year = (int) now()->format('Y');
            $userId = (string) $user->id;
            $hotel = $this->customer('Hotel Bellavista', 'IT');
            $rsa = $this->customer('RSA Villa Serena', 'IT');
            $works = $this->customer('Officine Rossi Srl', 'IT');
            $swiss = $this->customer('Hotel Alpenblick AG', 'CH');

            $this->contracts($tenant, $userId, $year, $hotel, $rsa, $works, $swiss);
            $cages = $this->rollCages($tenant);
            $this->hotelGarments($tenant, $hotel);
            $this->rsaGarments($tenant, $rsa);
            $this->workwear($tenant, $works);
            $this->soiledCageAndBatch($tenant, $rsa, $cages[0]);
        });
    }

    private function customer(string $name, string $country): Contact
    {
        return Contact::query()->firstOrCreate(['company_name' => $name], ['type' => 'customer', 'country' => $country]);
    }

    private function contracts(Tenant $tenant, string $userId, int $year, Contact $hotel, Contact $rsa, Contact $works, Contact $swiss): void
    {
        $definitions = [
            ["ILR-HOTEL-{$year}", $hotel, 'hotel', 'per_piece_washed', 'IT', 'EUR', '22', [
                ['article_type' => 'flat_linen', 'circulating_stock_qty' => 800, 'weekly_min_billing_percentage' => '60', 'seasonal_min_billing_percentage' => '85', 'season_start_month' => 6, 'season_end_month' => 9, 'unit_wash_price' => '0.45', 'replacement_price' => '18'],
                ['article_type' => 'terry_towel', 'circulating_stock_qty' => 400, 'weekly_min_billing_percentage' => '50', 'unit_wash_price' => '0.40', 'replacement_price' => '9'],
            ]],
            ["ILR-RSA-{$year}", $rsa, 'rsa', 'hybrid_rental_plus_wash', 'IT', 'EUR', '22', [
                ['article_type' => 'hospital_scrub', 'circulating_stock_qty' => 300, 'weekly_min_billing_percentage' => '40', 'unit_wash_price' => '0.35', 'rental_price' => '1.10', 'replacement_price' => '25'],
                ['article_type' => 'flat_linen', 'circulating_stock_qty' => 500, 'weekly_min_billing_percentage' => '70', 'unit_wash_price' => '0.42', 'rental_price' => '0.60', 'replacement_price' => '16'],
            ]],
            ["ILR-WORK-{$year}", $works, 'industry', 'fixed_monthly_rental_per_garment', 'IT', 'EUR', '22', [
                ['article_type' => 'personal_workwear_uniform', 'circulating_stock_qty' => 40, 'rental_price' => '4.50', 'replacement_price' => '45'],
                ['article_type' => 'ppe_safety_garment', 'circulating_stock_qty' => 20, 'rental_price' => '6.00', 'replacement_price' => '120'],
            ]],
            ["ILR-CH-{$year}", $swiss, 'hotel', 'per_kg_dirty_or_clean', 'CH', 'CHF', '8.1', [
                ['article_type' => 'flat_linen', 'circulating_stock_qty' => 600, 'price_per_kg' => '3.20', 'replacement_price' => '22'],
                ['article_type' => 'terry_towel', 'circulating_stock_qty' => 300, 'price_per_kg' => '3.40', 'replacement_price' => '12'],
            ]],
        ];

        foreach ($definitions as [$code, $customer, $segment, $model, $jurisdiction, $currency, $tax, $lines]) {
            if (ParContract::query()->where('code', $code)->exists()) {
                continue;
            }

            app(ParContractService::class)->save((string) $tenant->id, [
                'code' => $code, 'customer_id' => (string) $customer->id, 'customer_segment' => $segment, 'valid_from' => "{$year}-01-01", 'valid_to' => ($year + 1).'-12-31',
                'billing_model' => $model, 'kg_basis' => 'clean', 'unreturned_garment_replacement_price' => '20', 'unreturned_threshold_days' => 60,
                'jurisdiction' => $jurisdiction, 'currency' => $currency, 'tax_rate' => $tax, 'lines' => $lines,
            ], $userId);
        }
    }

    /**
     * @return list<RollCage>
     */
    private function rollCages(Tenant $tenant): array
    {
        $cages = [];

        foreach ([1 => '38.500', 2 => '38.500', 3 => '42.000', 4 => '42.000', 5 => '36.250', 6 => '36.250'] as $number => $tare) {
            $cages[] = RollCage::query()->firstOrCreate(['barcode' => sprintf('RC-%04d', $number)], ['tenant_id' => $tenant->id, 'label' => 'Roll container '.$number, 'tare_weight_kg' => $tare, 'max_gross_weight_kg' => '450.000', 'is_active' => true]);
        }

        return $cages;
    }

    private function epc(int $number): string
    {
        return sprintf('E2801160%016X', $number);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function garment(Tenant $tenant, int $number, ArticleType $type, ?Contact $customer, LifecycleState $state, array $attributes = []): Garment
    {
        $garment = Garment::query()->where('epc_uid_rfid_hex', $this->epc($number))->first();

        if ($garment !== null) {
            return $garment;
        }

        $garment = Garment::query()->create($attributes + [
            'tenant_id' => $tenant->id,
            'epc_uid_rfid_hex' => $this->epc($number),
            'article_type' => $type->value,
            'assigned_customer_id' => $customer?->id,
            'current_lifecycle_state' => $state->value,
            'wash_cycles_count' => 0,
        ]);

        GarmentEvent::query()->create([
            'tenant_id' => $tenant->id, 'garment_id' => $garment->id, 'contact_id' => $customer?->id, 'article_type' => $type->value,
            'event_type' => GarmentEventType::Registered->value, 'to_state' => LifecycleState::CleanInStock->value, 'wash_cycles_count' => 0, 'occurred_at' => Carbon::now()->subDays(120),
        ]);

        return $garment;
    }

    private function hotelGarments(Tenant $tenant, Contact $hotel): void
    {
        foreach (range(1, 12) as $index) {
            $number = 1000 + $index;
            $type = $index <= 8 ? ArticleType::FlatLinen : ArticleType::TerryTowel;
            $state = $index <= 3 ? LifecycleState::CleanInStock : LifecycleState::DeliveredToCustomer;
            // Garments 10 to 12 stayed at the hotel for 75 to 95 days: dormant, and soon charged as unreturned.
            $delivered = $index >= 10 ? Carbon::now()->subDays(65 + $index * 3) : Carbon::now()->subDays($index);
            $this->garment($tenant, $number, $type, $hotel, $state, [
                'wash_cycles_count' => 5 + $index,
                'last_delivered_at' => $state === LifecycleState::DeliveredToCustomer ? $delivered : null,
                'last_scanned_at' => $delivered,
                'thermal_barcode' => sprintf('HB-%05d', $index),
            ]);
        }
    }

    private function rsaGarments(Tenant $tenant, Contact $rsa): void
    {
        foreach (range(1, 10) as $index) {
            $this->garment($tenant, 2000 + $index, $index <= 6 ? ArticleType::HospitalScrub : ArticleType::FlatLinen, $rsa, $index <= 4 ? LifecycleState::SoiledReturned : LifecycleState::DeliveredToCustomer, [
                'wash_cycles_count' => 10 + $index,
                'last_delivered_at' => Carbon::now()->subDays(3 + $index),
                'last_soiled_at' => $index <= 4 ? Carbon::now()->subHours(6) : null,
                'last_scanned_at' => Carbon::now()->subHours(6),
            ]);
        }
    }

    private function workwear(Tenant $tenant, Contact $works): void
    {
        $employees = [['Marco Bianchi', 'A-01'], ['Giulia Ferri', 'A-02'], ['Luca Romano', 'A-03'], ['Elena Conti', 'B-01']];

        foreach ($employees as $index => [$name, $locker]) {
            $this->garment($tenant, 3000 + $index, ArticleType::PersonalWorkwearUniform, $works, LifecycleState::DeliveredToCustomer, [
                'assigned_wearer_employee_name' => $name, 'assigned_locker_code' => $locker, 'wash_cycles_count' => 18 + $index * 7,
                'last_delivered_at' => Carbon::now()->subDays(6), 'last_scanned_at' => Carbon::now()->subDays(6),
            ]);
        }

        // PPE: one at its certified wash limit (blocked from re-issue), one close to it, two with margin.
        $ppe = [
            [3100, 'Marco Bianchi', 'A-01', 'EN ISO 20471', 2, 50, 50, true],
            [3101, 'Giulia Ferri', 'A-02', 'EN ISO 11612', 3, 50, 46, false],
            [3102, 'Luca Romano', 'A-03', 'EN ISO 20471', 2, 50, 20, false],
            [3103, 'Elena Conti', 'B-01', 'EN 13034', 3, 30, 12, false],
        ];

        foreach ($ppe as [$number, $name, $locker, $standard, $category, $limit, $cycles, $blocked]) {
            $this->garment($tenant, $number, ArticleType::PpeSafetyGarment, $works, $blocked ? LifecycleState::InMending : LifecycleState::DeliveredToCustomer, [
                'assigned_wearer_employee_name' => $name, 'assigned_locker_code' => $locker, 'ppe_certification_standard' => $standard, 'ppe_category' => $category,
                'max_certified_wash_cycles_limit' => $limit, 'wash_cycles_count' => $cycles, 'is_reissue_blocked' => $blocked,
                'mending_or_condemn_reason' => $blocked ? 'ppe_wash_limit_reached' : null, 'last_delivered_at' => Carbon::now()->subDays(9), 'last_scanned_at' => Carbon::now()->subDays(1),
            ]);
        }
    }

    private function soiledCageAndBatch(Tenant $tenant, Contact $rsa, RollCage $cage): void
    {
        if (ScanSession::query()->where('external_scan_id', 'DEMO-SOIL-001')->exists()) {
            return;
        }

        $epcs = array_map(fn (int $index): string => $this->epc(2000 + $index), [1, 2, 3, 4]);
        $session = ScanSession::query()->create([
            'tenant_id' => $tenant->id, 'external_scan_id' => 'DEMO-SOIL-001', 'gate_antenna_id' => 'GATE-IN-01', 'roll_cage_id' => $cage->id, 'roll_cage_barcode' => $cage->barcode,
            'roll_cage_tare_weight_kg' => $cage->tare_weight_kg, 'gross_weight_kg' => '118.500', 'net_weight_kg' => '80.000', 'direction' => ScanDirection::InboundSoil->value,
            'contact_id' => $rsa->id, 'read_epc_tags_list' => ['raw' => array_map(static fn (string $epc): array => ['epc' => $epc, 'rssi' => -58.5], $epcs), 'deduplicated' => $epcs],
            'accepted_epc_tags' => $epcs, 'rejected_reads' => [], 'alerts' => [], 'raw_reads_count' => 4, 'accepted_count' => 4, 'rejected_count' => 0, 'scanned_at' => Carbon::now()->subHours(6),
        ]);

        $program = WashProgram::query()->where('code', 'THERMAL_71_3MIN')->first();

        if ($program === null || WashBatch::query()->where('batch_code', 'RABC-DEMO-001')->exists()) {
            return;
        }

        $batch = WashBatch::query()->create([
            'tenant_id' => $tenant->id, 'batch_code' => 'RABC-DEMO-001', 'disinfection_thermal_chem_program_code' => $program->code, 'rabc_risk_zone' => 'contaminated',
            'rabc_control_status' => CertificationStatus::Compliant->value, 'target_temperature_c' => $program->target_temperature_c, 'target_hold_minutes' => $program->min_hold_minutes,
            'measured_temperature_c' => '72.50', 'measured_hold_minutes' => 4, 'microbiological_cfu_per_25cm2' => '3.00', 'load_net_weight_kg' => '80.000', 'garments_count' => 4,
            'operator_name' => 'Paolo Neri', 'verifier_name' => 'Anna Greco', 'violations' => [], 'washed_at' => Carbon::now()->subHours(5), 'certified_at' => Carbon::now()->subHours(4),
        ]);

        foreach ($epcs as $epc) {
            $garment = Garment::query()->where('epc_uid_rfid_hex', $epc)->first();

            if ($garment !== null) {
                DB::table('ilr_wash_batch_garments')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'wash_batch_id' => $batch->id, 'garment_id' => $garment->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        DB::table('ilr_wash_batch_sessions')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'wash_batch_id' => $batch->id, 'scan_session_id' => $session->id, 'created_at' => now(), 'updated_at' => now()]);
    }
}
