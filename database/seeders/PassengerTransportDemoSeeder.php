<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\HumanResources\Models\Employee;
use Modules\PassengerTransport\Models\DriverComplianceState;
use Modules\PassengerTransport\Models\FleetVehicle;
use Modules\PassengerTransport\Models\HotelConciergeContract;
use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Models\SchoolBusContract;
use Modules\PassengerTransport\Services\BookingService;

/**
 * Demo passenger transport for the first company of the installation: a sample fleet (Mercedes S-Class NCC, V-Class van, Setra GT
 * coach, Iveco school bus), driver compliance records for up to four existing employees (licences, CQC Persone, CZV, tachograph
 * card), hotel concierge rate cards (Malpensa - Lugano, Zurich - St. Moritz), a municipal school bus contract and three bookings:
 * Malpensa to Lugano, Zurich to Milan and a school bus run. Idempotent by contract code; does nothing without a company or a user.
 * Names are demo content; the interface texts are in the frontend dictionary seeders.
 */
final class PassengerTransportDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('created_at')->first();
        $user = $tenant?->users()->first();

        if ($tenant === null || $user === null) {
            return;
        }

        app(TenantContext::class)->runWithTenant($tenant, function () use ($tenant, $user): void {
            if (HotelConciergeContract::query()->where('code', 'HTL-VILLA-CAST')->exists()) {
                return;
            }

            $userId = (string) $user->id;
            $this->fleet((string) $tenant->id, $userId);
            $this->drivers((string) $tenant->id, $userId);
            [$villa, $palace] = $this->hotels((string) $tenant->id, $userId);
            $school = $this->school((string) $tenant->id, $userId);
            $this->bookings((string) $tenant->id, $userId, $villa, $palace, $school);
        });
    }

    private function fleet(string $tenantId, string $userId): void
    {
        $vehicles = [
            ['license_plate' => 'TI 100001', 'vehicle_category' => 'sedan_ncc', 'gross_weight_tons' => '2.20', 'passenger_capacity' => 3, 'ncc_authorization_number' => 'NCC-LUG-0012', 'ncc_issuing_municipality' => 'Lugano', 'odometer_km' => 48200],
            ['license_plate' => 'TI 100002', 'vehicle_category' => 'luxury_van_ncc', 'gross_weight_tons' => '3.10', 'passenger_capacity' => 7, 'ncc_authorization_number' => 'NCC-LUG-0013', 'ncc_issuing_municipality' => 'Lugano', 'odometer_km' => 61250],
            ['license_plate' => 'TI 100003', 'vehicle_category' => 'gt_coach_over_3_5t', 'gross_weight_tons' => '18.00', 'passenger_capacity' => 50, 'has_digital_tachograph' => true, 'psva_subject' => true, 'odometer_km' => 214500],
            ['license_plate' => 'TI 100004', 'vehicle_category' => 'school_bus', 'gross_weight_tons' => '7.50', 'passenger_capacity' => 28, 'has_digital_tachograph' => true, 'psva_subject' => true, 'odometer_km' => 98300],
        ];

        foreach ($vehicles as $vehicle) {
            FleetVehicle::query()->firstOrCreate(['license_plate' => $vehicle['license_plate']], [...$vehicle, 'tenant_id' => $tenantId, 'created_by' => $userId]);
        }
    }

    private function drivers(string $tenantId, string $userId): void
    {
        $profiles = [
            ['driving_license_categories' => ['B'], 'cqc_persone_number_it' => null, 'czv_certificate_number_ch' => null, 'tachograph_card_number' => null],
            ['driving_license_categories' => ['B'], 'cqc_persone_number_it' => null, 'czv_certificate_number_ch' => null, 'tachograph_card_number' => null],
            ['driving_license_categories' => ['B', 'D', 'DE'], 'cqc_persone_number_it' => 'CQC-IT-558120', 'czv_certificate_number_ch' => 'CZV-CH-770412', 'tachograph_card_number' => 'I1000012345678'],
            ['driving_license_categories' => ['B', 'D'], 'cqc_persone_number_it' => 'CQC-IT-558121', 'czv_certificate_number_ch' => 'CZV-CH-770413', 'tachograph_card_number' => 'I1000012345679'],
        ];

        foreach (Employee::query()->orderBy('created_at')->limit(count($profiles))->get() as $index => $employee) {
            $profile = $profiles[$index];

            DriverComplianceState::query()->firstOrCreate(['driver_employee_id' => $employee->id], [
                ...$profile,
                'tenant_id' => $tenantId,
                'driving_license_number' => 'DL-DEMO-'.($index + 1),
                'driving_license_expiry_date' => now()->addYears(3)->toDateString(),
                'cqc_persone_expiry_date' => $profile['cqc_persone_number_it'] === null ? null : now()->addYears(2)->toDateString(),
                'czv_expiry_date' => $profile['czv_certificate_number_ch'] === null ? null : now()->addYears(2)->toDateString(),
                'tachograph_card_expiry_date' => $profile['tachograph_card_number'] === null ? null : now()->addYears(4)->toDateString(),
                'last_weekly_rest_ended_at' => now()->subDays(2),
                'counters_updated_at' => now(),
                'created_by' => $userId,
            ]);
        }
    }

    /**
     * @return array{0: HotelConciergeContract, 1: HotelConciergeContract}
     */
    private function hotels(string $tenantId, string $userId): array
    {
        $villa = Contact::query()->firstOrCreate(['company_name' => 'Grand Hotel Villa Castagnola'], ['type' => 'customer', 'country' => 'CH']);
        $badrutt = Contact::query()->firstOrCreate(['company_name' => 'Hotel Alpenblick St. Moritz'], ['type' => 'customer', 'country' => 'CH']);

        $villaContract = HotelConciergeContract::query()->create([
            'tenant_id' => $tenantId, 'contact_id' => $villa->id, 'code' => 'HTL-VILLA-CAST', 'name' => 'Villa Castagnola concierge', 'commission_rate' => '10', 'currency' => 'CHF',
            'valid_from' => now()->startOfYear()->toDateString(), 'valid_to' => now()->endOfYear()->toDateString(), 'created_by' => $userId,
            'rate_card' => [
                ['route_code' => 'MXP-LUG', 'origin_label' => 'Milano Malpensa (MXP)', 'destination_label' => 'Lugano', 'vehicle_category' => 'sedan_ncc', 'price' => '220.00', 'origin_country' => 'IT', 'destination_country' => 'CH', 'origin_lat' => 45.6306, 'origin_lng' => 8.7281, 'destination_lat' => 46.0037, 'destination_lng' => 8.9511, 'border_crossing' => 'chiasso_brogeda'],
                ['route_code' => 'LIN-LUG', 'origin_label' => 'Milano Linate (LIN)', 'destination_label' => 'Lugano', 'vehicle_category' => 'luxury_van_ncc', 'price' => '260.00', 'origin_country' => 'IT', 'destination_country' => 'CH', 'origin_lat' => 45.4451, 'origin_lng' => 9.2767, 'destination_lat' => 46.0037, 'destination_lng' => 8.9511, 'border_crossing' => 'chiasso_brogeda'],
            ],
        ]);

        $palaceContract = HotelConciergeContract::query()->create([
            'tenant_id' => $tenantId, 'contact_id' => $badrutt->id, 'code' => 'HTL-ALPENBLICK', 'name' => 'Alpenblick concierge', 'commission_rate' => '12.5', 'currency' => 'CHF',
            'valid_from' => now()->startOfYear()->toDateString(), 'valid_to' => now()->endOfYear()->toDateString(), 'created_by' => $userId,
            'rate_card' => [
                ['route_code' => 'ZRH-STM', 'origin_label' => 'Zürich Flughafen (ZRH)', 'destination_label' => 'St. Moritz', 'vehicle_category' => 'luxury_van_ncc', 'price' => '690.00', 'origin_country' => 'CH', 'destination_country' => 'CH', 'total_km' => 200],
            ],
        ]);

        return [$villaContract, $palaceContract];
    }

    private function school(string $tenantId, string $userId): SchoolBusContract
    {
        $municipality = Contact::query()->firstOrCreate(['company_name' => 'Comune di Stabio'], ['type' => 'customer', 'country' => 'CH']);

        return SchoolBusContract::query()->create([
            'tenant_id' => $tenantId, 'contact_id' => $municipality->id, 'code' => 'SCH-STABIO', 'name' => 'Scuolabus Stabio', 'currency' => 'CHF', 'monthly_flat_amount' => '4800.00',
            'valid_from' => now()->startOfYear()->toDateString(), 'valid_to' => now()->endOfYear()->toDateString(), 'created_by' => $userId,
            'rate_card' => [
                ['route_code' => 'STB-NORD', 'label' => 'Linea Nord', 'price' => '160.00'],
                ['route_code' => 'STB-SUD', 'label' => 'Linea Sud', 'price' => '140.00'],
            ],
        ]);
    }

    private function bookings(string $tenantId, string $userId, HotelConciergeContract $villa, HotelConciergeContract $palace, SchoolBusContract $school): void
    {
        $bookings = app(BookingService::class);
        $day = now()->addDay()->setTime(14, 0);

        $bookings->create($tenantId, [
            'service_type' => 'airport_transfer', 'ordering_customer_id' => $villa->contact_id, 'hotel_contract_id' => $villa->id, 'lead_passenger_name' => 'Mr. Alexander Weber', 'vip_welcome_sign_text' => 'Mr. Weber',
            'passengers_count' => 2, 'luggage_count' => 3, 'flight_or_train_number' => 'LH1234', 'flight_carrier_iata' => 'LH', 'arrival_airport_iata' => 'MXP', 'scheduled_flight_arrival_at' => $day->toDateTimeString(),
            'pickup_datetime' => $day->copy()->addMinutes(30)->toDateTimeString(), 'pickup_address' => 'Milano Malpensa (MXP), Arrivi', 'pickup_lat' => 45.6306, 'pickup_lng' => 8.7281, 'pickup_country_code' => 'IT',
            'dropoff_address' => 'Grand Hotel Villa Castagnola, Lugano', 'dropoff_lat' => 46.0037, 'dropoff_lng' => 8.9511, 'dropoff_country_code' => 'CH', 'border_crossing_point' => 'chiasso_brogeda',
            'agreed_price_net' => '220.00', 'currency' => 'CHF', 'hotel_concierge_commission_rate' => '10',
        ], $userId);

        $bookings->create($tenantId, [
            'service_type' => 'airport_transfer', 'lead_passenger_name' => 'Famiglia Rossi', 'passengers_count' => 4, 'luggage_count' => 5, 'child_seats_spec' => ['infant' => 0, 'child' => 1, 'booster' => 1],
            'flight_or_train_number' => 'LX1726', 'flight_carrier_iata' => 'LX', 'arrival_airport_iata' => 'ZRH', 'scheduled_flight_arrival_at' => $day->copy()->addHours(3)->toDateTimeString(),
            'pickup_datetime' => $day->copy()->addHours(3)->addMinutes(30)->toDateTimeString(), 'pickup_address' => 'Zürich Flughafen (ZRH)', 'pickup_lat' => 47.4647, 'pickup_lng' => 8.5492, 'pickup_country_code' => 'CH',
            'dropoff_address' => 'Milano, Piazza del Duomo', 'dropoff_lat' => 45.4642, 'dropoff_lng' => 9.1900, 'dropoff_country_code' => 'IT',
            'agreed_price_net' => '780.00', 'currency' => 'CHF',
        ], $userId);

        $bookings->create($tenantId, [
            'service_type' => 'school_bus_route', 'ordering_customer_id' => $school->contact_id, 'school_contract_id' => $school->id, 'passengers_count' => 24, 'municipal_urban_service' => true,
            'pickup_datetime' => now()->addDay()->setTime(7, 15)->toDateTimeString(), 'pickup_address' => 'Stabio, Piazza Maggiore', 'pickup_country_code' => 'CH', 'dropoff_address' => 'Stabio, Scuole elementari', 'dropoff_country_code' => 'CH',
            'total_distance_km' => 12, 'agreed_price_net' => '160.00', 'currency' => 'CHF',
        ], $userId);

        PassengerBooking::query()->where('school_contract_id', $school->id)->update(['status' => 'confirmed']);
    }
}
