<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Employee;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

/**
 * A company of the given country with an open fiscal calendar and an accountant (finance writer) signed in.
 */
function ptrSetUp(object $test, string $country = 'IT'): void
{
    $suffix = Str::upper(Str::random(5));
    $test->tenant = Tenant::create(['name' => 'Taxi Lugano '.$suffix, 'legal_name' => 'Taxi Lugano '.$suffix.' SA', 'country' => $country, 'default_currency' => $country === 'CH' ? 'CHF' : 'EUR']);
    $test->otherTenant = Tenant::create(['name' => 'Altra Societa '.$suffix, 'legal_name' => 'Altra Societa '.$suffix.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);

    foreach ([$test->tenant, $test->otherTenant] as $tenant) {
        fiscalCalendar($tenant, (int) now()->year);
    }

    $test->actor = treasuryActor($test, 'accountant');
    $test->customer = Contact::query()->create(['company_name' => 'Hotel Test', 'type' => 'customer', 'country' => 'CH']);
}

function ptrEmployee(Tenant $tenant, string $name): Employee
{
    $user = User::create(['name' => $name, 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);

    return Employee::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $tenant->id,
        'user_id' => $user->id,
        'employee_number' => 'EMP-'.Str::upper(Str::random(6)),
        'first_name' => $name,
        'last_name' => 'Driver',
        'full_name' => $name.' Driver',
        'work_email' => Str::uuid().'@driver.example.com',
        'hire_date' => '2025-01-01',
        'employment_type' => 'hourly',
        'employment_status' => 'active',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed> the data of the created vehicle
 */
function ptrVehicle(object $test, string $plate, string $category = 'sedan_ncc', array $overrides = []): array
{
    $coach = in_array($category, ['gt_coach_over_3_5t', 'school_bus', 'minibus_9_16'], true);

    return $test->postJson('/api/v1/passenger-transport/vehicles', array_merge([
        'license_plate' => $plate,
        'vehicle_category' => $category,
        'gross_weight_tons' => $coach ? 18 : 2.2,
        'passenger_capacity' => $coach ? 50 : 3,
        'has_digital_tachograph' => $coach,
        'ncc_authorization_number' => $coach ? null : 'NCC-1',
        'ncc_issuing_municipality' => $coach ? null : 'Lugano',
    ], $overrides))->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed> the compliance record of the driver
 */
function ptrDriver(object $test, bool $coachLicences = false, array $overrides = []): array
{
    $employee = ptrEmployee($test->tenant, 'Marco'.Str::random(3));

    return $test->postJson('/api/v1/passenger-transport/drivers', array_merge([
        'driver_employee_id' => $employee->id,
        'driving_license_number' => 'DL-1',
        'driving_license_categories' => $coachLicences ? ['B', 'D', 'DE'] : ['B'],
        'driving_license_expiry_date' => now()->addYears(3)->toDateString(),
        'cqc_persone_number_it' => $coachLicences ? 'CQC-1' : null,
        'cqc_persone_expiry_date' => $coachLicences ? now()->addYears(2)->toDateString() : null,
        'czv_certificate_number_ch' => $coachLicences ? 'CZV-1' : null,
        'czv_expiry_date' => $coachLicences ? now()->addYears(2)->toDateString() : null,
        'tachograph_card_number' => $coachLicences ? 'I1000012345678' : null,
        'tachograph_card_expiry_date' => $coachLicences ? now()->addYears(4)->toDateString() : null,
    ], $overrides))->assertCreated()->json('data');
}

/**
 * A Malpensa to Lugano transfer through Chiasso, two days from now at 14:30.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ptrBookingPayload(object $test, array $overrides = []): array
{
    return array_merge([
        'service_type' => 'airport_transfer',
        'ordering_customer_id' => $test->customer->id,
        'lead_passenger_name' => 'Mr. Weber',
        'passengers_count' => 2,
        'pickup_datetime' => now()->addDays(2)->setTime(14, 30)->toDateTimeString(),
        'pickup_address' => 'Milano Malpensa (MXP)',
        'pickup_lat' => 45.6306,
        'pickup_lng' => 8.7281,
        'pickup_country_code' => 'IT',
        'dropoff_address' => 'Lugano, Hotel',
        'dropoff_lat' => 46.0037,
        'dropoff_lng' => 8.9511,
        'dropoff_country_code' => 'CH',
        'border_crossing_point' => 'chiasso_brogeda',
        'agreed_price_net' => '220.00',
        'currency' => 'CHF',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed> the data of the created booking
 */
function ptrBooking(object $test, array $overrides = []): array
{
    return $test->postJson('/api/v1/passenger-transport/bookings', ptrBookingPayload($test, $overrides))->assertCreated()->json('data');
}
