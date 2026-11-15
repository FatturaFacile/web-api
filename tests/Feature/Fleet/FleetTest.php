<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Fleet\Models\FleetVehicle;
use Modules\HumanResources\Models\Employee;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-15 09:00:00');
    $this->tenant = fltTenant('Autotrasporti');
    $this->owner = fltMember($this->tenant, 'owner');
    fltAct($this->owner, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function fltTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function fltMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@fleet-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function fltAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fltVehicle(array $overrides = []): array
{
    return test()->postJson('/api/v1/fleet/vehicles', [
        'license_plate' => 'ab'.random_int(100, 999).'cd',
        'make' => 'Fiat',
        'model' => 'Ducato',
        'vehicle_type' => 'van',
        'fuel_type' => 'diesel',
        'odometer_km' => 10000,
        ...$overrides,
    ])->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fltFuel(string $vehicleId, string $date, int $km, string $quantity, array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/fleet/fuel-entries', [
        'vehicle_id' => $vehicleId,
        'entry_date' => $date,
        'energy_unit' => 'liter',
        'quantity' => $quantity,
        'unit_price' => '1.8',
        'odometer_km' => $km,
        ...$overrides,
    ]);
}

test('a vehicle is created with a normalized unique plate and its odometer never decreases', function (): void {
    $vehicle = fltVehicle(['license_plate' => ' ab123cd ', 'purchase_value' => '20000', 'ownership' => 'leased']);

    expect($vehicle['license_plate'])->toBe('AB123CD')
        ->and($vehicle['status'])->toBe('active')
        ->and($vehicle['odometer_km'])->toBe(10000)
        ->and($vehicle['initial_odometer_km'])->toBe(10000)
        ->and($vehicle['ownership'])->toBe('leased')
        ->and($vehicle['purchase_value'])->toBe('20000.00');

    $this->postJson('/api/v1/fleet/vehicles', ['license_plate' => 'AB123CD', 'make' => 'Fiat', 'model' => 'Panda'])
        ->assertUnprocessable()->assertJsonValidationErrors(['license_plate']);
    $this->postJson('/api/v1/fleet/vehicles', ['license_plate' => 'ZZ999ZZ', 'make' => 'Fiat', 'model' => 'Panda', 'fuel_type' => 'steam'])
        ->assertUnprocessable()->assertJsonValidationErrors(['fuel_type']);

    $this->putJson('/api/v1/fleet/vehicles/'.$vehicle['id'], ['odometer_km' => 9000])
        ->assertUnprocessable()->assertJsonValidationErrors(['odometer_km']);
    $this->putJson('/api/v1/fleet/vehicles/'.$vehicle['id'], ['odometer_km' => 12000, 'status' => 'maintenance'])
        ->assertOk()->assertJsonPath('data.odometer_km', 12000)->assertJsonPath('data.status', 'maintenance');

    $this->getJson('/api/v1/fleet/vehicles?status=maintenance&search=ab12')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/fleet/vehicles?status=active')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/fleet/vehicles/'.$vehicle['id'])->assertOk()->assertJsonPath('data.current_assignment', null);

    $this->deleteJson('/api/v1/fleet/vehicles/'.$vehicle['id'])->assertNoContent();
    $this->getJson('/api/v1/fleet/vehicles/'.$vehicle['id'])->assertNotFound();
});

test('the asset link must exist in the company and records stay inside it', function (): void {
    $this->postJson('/api/v1/fleet/vehicles', ['license_plate' => 'AS111SS', 'make' => 'Fiat', 'model' => 'Panda', 'asset_id' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors(['asset_id']);

    $vehicle = fltVehicle();

    $other = fltTenant('Altra');
    fltAct(fltMember($other, 'owner'), $other);
    $this->getJson('/api/v1/fleet/vehicles/'.$vehicle['id'])->assertNotFound();
    $this->getJson('/api/v1/fleet/vehicles')->assertOk()->assertJsonCount(0, 'data');
    fltFuel($vehicle['id'], '2027-01-10', 10100, '30')->assertUnprocessable()->assertJsonValidationErrors(['vehicle_id']);
});

test('a read-only member never writes and members read', function (): void {
    $vehicle = fltVehicle();

    fltAct(fltMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->getJson('/api/v1/fleet/vehicles')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/fleet/vehicles', ['license_plate' => 'RO111RO', 'make' => 'Fiat', 'model' => 'Panda'])->assertForbidden();
    $this->deleteJson('/api/v1/fleet/vehicles/'.$vehicle['id'])->assertForbidden();
});

test('assignments never overlap, record the odometer at handover and return, and block selling an assigned vehicle', function (): void {
    $vehicle = fltVehicle();
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    $foreign = Employee::factory()->create();

    $first = $this->postJson('/api/v1/fleet/assignments', ['vehicle_id' => $vehicle['id'], 'employee_id' => $employee->id, 'starts_on' => '2027-02-01'])
        ->assertCreated()->assertJsonPath('data.start_odometer_km', 10000)->assertJsonPath('data.is_open', true)
        ->assertJsonPath('data.employee.id', $employee->id)->json('data');

    $this->postJson('/api/v1/fleet/assignments', ['vehicle_id' => $vehicle['id'], 'driver_label' => 'Mario Esterno', 'starts_on' => '2027-03-01'])
        ->assertUnprocessable()->assertJsonValidationErrors(['starts_on']);
    $this->postJson('/api/v1/fleet/assignments', ['vehicle_id' => $vehicle['id'], 'starts_on' => '2026-12-01'])
        ->assertUnprocessable()->assertJsonValidationErrors(['employee_id']);
    $this->postJson('/api/v1/fleet/assignments', ['vehicle_id' => $vehicle['id'], 'employee_id' => $foreign->id, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31'])
        ->assertUnprocessable()->assertJsonValidationErrors(['employee_id']);

    $this->putJson('/api/v1/fleet/vehicles/'.$vehicle['id'], ['status' => 'sold'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->getJson('/api/v1/fleet/vehicles/'.$vehicle['id'])->assertJsonPath('data.current_assignment.id', $first['id']);

    $this->postJson('/api/v1/fleet/assignments/'.$first['id'].'/end', ['ends_on' => '2027-04-01', 'end_odometer_km' => 9000])->assertUnprocessable();
    $this->postJson('/api/v1/fleet/assignments/'.$first['id'].'/end', ['ends_on' => '2027-04-01', 'end_odometer_km' => 14000])
        ->assertOk()->assertJsonPath('data.is_open', false)->assertJsonPath('data.end_odometer_km', 14000);
    $this->postJson('/api/v1/fleet/assignments/'.$first['id'].'/end', ['ends_on' => '2027-04-02', 'end_odometer_km' => 14100])->assertUnprocessable();
    $this->getJson('/api/v1/fleet/vehicles/'.$vehicle['id'])->assertJsonPath('data.odometer_km', 14000);

    // Handover on the day of the return is allowed, an earlier odometer is not.
    $this->postJson('/api/v1/fleet/assignments', ['vehicle_id' => $vehicle['id'], 'driver_label' => 'Mario Esterno', 'starts_on' => '2027-04-01', 'start_odometer_km' => 13000])
        ->assertUnprocessable()->assertJsonValidationErrors(['start_odometer_km']);
    $this->postJson('/api/v1/fleet/assignments', ['vehicle_id' => $vehicle['id'], 'driver_label' => 'Mario Esterno', 'starts_on' => '2027-04-01', 'start_odometer_km' => 14000])
        ->assertCreated()->assertJsonPath('data.driver_label', 'Mario Esterno');
    $this->getJson('/api/v1/fleet/assignments?vehicle_id='.$vehicle['id'].'&open=1')->assertOk()->assertJsonCount(1, 'data');

    $this->putJson('/api/v1/fleet/assignments/'.$first['id'], ['ends_on' => '2027-05-01', 'end_odometer_km' => 14000])->assertUnprocessable();
});

test('a sold vehicle takes no new assignment or fuel entry', function (): void {
    $vehicle = fltVehicle();
    $this->putJson('/api/v1/fleet/vehicles/'.$vehicle['id'], ['status' => 'sold'])->assertOk();

    $this->postJson('/api/v1/fleet/assignments', ['vehicle_id' => $vehicle['id'], 'driver_label' => 'Mario', 'starts_on' => '2027-02-01'])
        ->assertUnprocessable()->assertJsonValidationErrors(['vehicle_id']);
    fltFuel($vehicle['id'], '2027-02-01', 10100, '30')->assertUnprocessable()->assertJsonValidationErrors(['vehicle_id']);
});

test('fuel entries compute consumption and cost per km between full tanks and keep the odometer monotonic', function (): void {
    $vehicle = fltVehicle(['purchase_value' => '20000']);

    $first = fltFuel($vehicle['id'], '2027-01-10', 10000, '40')->assertCreated()->assertJsonPath('data.total_cost', '72.00')->assertJsonPath('data.consumption_per_100km', null)->json('data');
    $second = fltFuel($vehicle['id'], '2027-02-10', 10600, '39')->assertCreated()
        ->assertJsonPath('data.total_cost', '70.20')->assertJsonPath('data.distance_km', 600)
        ->assertJsonPath('data.consumption_per_100km', '6.500')->assertJsonPath('data.cost_per_km', '0.1170')->json('data');
    $partial = fltFuel($vehicle['id'], '2027-03-10', 10900, '10', ['is_full_tank' => false, 'unit_price' => null, 'total_cost' => '18'])
        ->assertCreated()->assertJsonPath('data.unit_price', '1.8000')->assertJsonPath('data.consumption_per_100km', null)->json('data');
    $fourth = fltFuel($vehicle['id'], '2027-04-10', 11200, '18')->assertCreated()
        ->assertJsonPath('data.distance_km', 600)->assertJsonPath('data.consumption_per_100km', '4.667')->assertJsonPath('data.cost_per_km', '0.0840')->json('data');

    $this->getJson('/api/v1/fleet/vehicles/'.$vehicle['id'])->assertJsonPath('data.odometer_km', 11200);

    // A reading lower than the odometer, lower than an earlier one, or higher than a later one is refused.
    fltFuel($vehicle['id'], '2027-05-10', 11100, '30')->assertUnprocessable()->assertJsonValidationErrors(['odometer_km']);
    fltFuel($vehicle['id'], '2027-02-20', 10400, '30')->assertUnprocessable()->assertJsonValidationErrors(['odometer_km']);
    fltFuel($vehicle['id'], '2027-02-20', 11000, '30')->assertUnprocessable()->assertJsonValidationErrors(['odometer_km']);
    fltFuel($vehicle['id'], '2027-02-20', 10700, '30')->assertCreated();

    // A later entry in between changes the consumption of the neighbours.
    $this->getJson('/api/v1/fleet/fuel-entries/'.$second['id'])->assertJsonPath('data.consumption_per_100km', '6.500');
    $this->putJson('/api/v1/fleet/fuel-entries/'.$partial['id'], ['quantity' => '20', 'total_cost' => '36'])->assertOk()->assertJsonPath('data.unit_price', '1.8000');
    $this->getJson('/api/v1/fleet/fuel-entries/'.$fourth['id'])->assertJsonPath('data.consumption_per_100km', '7.600');

    $this->deleteJson('/api/v1/fleet/fuel-entries/'.$first['id'])->assertNoContent();
    $this->getJson('/api/v1/fleet/fuel-entries/'.$second['id'])->assertJsonPath('data.consumption_per_100km', null);
    $this->getJson('/api/v1/fleet/fuel-entries?vehicle_id='.$vehicle['id'].'&from=2027-03-01&to=2027-04-30')->assertOk()->assertJsonCount(2, 'data');

    // Missing price or total, and the wrong unit for the fuel type.
    $this->postJson('/api/v1/fleet/fuel-entries', ['vehicle_id' => $vehicle['id'], 'entry_date' => '2027-06-01', 'energy_unit' => 'liter', 'quantity' => '10', 'odometer_km' => 11300])
        ->assertUnprocessable()->assertJsonValidationErrors(['total_cost']);
    fltFuel($vehicle['id'], '2027-06-01', 11300, '10', ['energy_unit' => 'kwh'])->assertUnprocessable()->assertJsonValidationErrors(['energy_unit']);
});

test('an electric vehicle is charged in kWh and the driver must be an employee of the company', function (): void {
    $vehicle = fltVehicle(['fuel_type' => 'electric']);
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);

    fltFuel($vehicle['id'], '2027-02-01', 10100, '30')->assertUnprocessable()->assertJsonValidationErrors(['energy_unit']);
    fltFuel($vehicle['id'], '2027-02-01', 10100, '30', ['energy_unit' => 'kwh', 'employee_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['employee_id']);
    fltFuel($vehicle['id'], '2027-02-01', 10100, '30', ['energy_unit' => 'kwh', 'unit_price' => '0.4', 'employee_id' => $employee->id])
        ->assertCreated()->assertJsonPath('data.total_cost', '12.00')->assertJsonPath('data.employee.id', $employee->id);
});

test('scheduled services are overdue by date or by kilometres and a service record moves them forward', function (): void {
    $vehicle = fltVehicle();
    $byDate = $this->postJson('/api/v1/fleet/scheduled-services', ['vehicle_id' => $vehicle['id'], 'name' => 'Annual service', 'interval_days' => 365, 'last_service_on' => '2026-05-01'])
        ->assertCreated()->assertJsonPath('data.next_due_on', '2027-05-01')->assertJsonPath('data.is_overdue', true)->json('data');
    $byKm = $this->postJson('/api/v1/fleet/scheduled-services', ['vehicle_id' => $vehicle['id'], 'name' => 'Tyres', 'service_type' => 'tyres', 'interval_km' => 5000])
        ->assertCreated()->assertJsonPath('data.next_due_km', 15000)->assertJsonPath('data.km_to_due', 5000)->assertJsonPath('data.is_overdue', false)->json('data');
    $this->postJson('/api/v1/fleet/scheduled-services', ['vehicle_id' => $vehicle['id'], 'name' => 'Nothing due'])
        ->assertUnprocessable()->assertJsonValidationErrors(['interval_days']);

    $this->getJson('/api/v1/fleet/scheduled-services?overdue=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $byDate['id']);

    // The odometer reaches the due kilometres.
    $this->putJson('/api/v1/fleet/vehicles/'.$vehicle['id'], ['odometer_km' => 15100])->assertOk();
    $this->getJson('/api/v1/fleet/scheduled-services?overdue=1')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/fleet/scheduled-services/'.$byKm['id'])->assertJsonPath('data.is_overdue', true)->assertJsonPath('data.km_to_due', -100);

    // Completing the services moves them forward.
    $this->postJson('/api/v1/fleet/services', ['vehicle_id' => $vehicle['id'], 'scheduled_service_id' => $byDate['id'], 'service_date' => '2027-06-10', 'odometer_km' => 15100, 'description' => 'Annual service', 'cost' => '320.50', 'vendor' => 'Garage Rossi'])
        ->assertCreated()->assertJsonPath('data.cost', '320.50');
    $this->postJson('/api/v1/fleet/services', ['vehicle_id' => $vehicle['id'], 'scheduled_service_id' => $byKm['id'], 'service_type' => 'tyres', 'service_date' => '2027-06-11', 'odometer_km' => 15200, 'description' => 'Winter tyres', 'cost' => '480'])
        ->assertCreated();
    $this->getJson('/api/v1/fleet/scheduled-services?overdue=1')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/fleet/scheduled-services/'.$byDate['id'])->assertJsonPath('data.next_due_on', '2028-06-09')->assertJsonPath('data.last_service_km', 15100);
    $this->getJson('/api/v1/fleet/scheduled-services/'.$byKm['id'])->assertJsonPath('data.next_due_km', 20200);

    // The odometer of a service never goes back.
    $this->postJson('/api/v1/fleet/services', ['vehicle_id' => $vehicle['id'], 'service_date' => '2027-06-12', 'odometer_km' => 15000, 'description' => 'Wipers'])
        ->assertUnprocessable()->assertJsonValidationErrors(['odometer_km']);
    $other = fltVehicle();
    $this->postJson('/api/v1/fleet/services', ['vehicle_id' => $other['id'], 'scheduled_service_id' => $byDate['id'], 'service_date' => '2027-06-12', 'odometer_km' => 10100, 'description' => 'Wipers'])
        ->assertUnprocessable()->assertJsonValidationErrors(['scheduled_service_id']);

    $this->getJson('/api/v1/fleet/services?vehicle_id='.$vehicle['id'].'&service_type=tyres')->assertOk()->assertJsonCount(1, 'data');
});

test('deleting a service record rewinds the schedule it completed', function (): void {
    $vehicle = fltVehicle();
    $schedule = $this->postJson('/api/v1/fleet/scheduled-services', ['vehicle_id' => $vehicle['id'], 'name' => 'Oil', 'interval_km' => 10000])->assertCreated()->json('data');
    $record = $this->postJson('/api/v1/fleet/services', ['vehicle_id' => $vehicle['id'], 'scheduled_service_id' => $schedule['id'], 'service_date' => '2027-06-10', 'odometer_km' => 12000, 'description' => 'Oil'])
        ->assertCreated()->json('data');
    $this->getJson('/api/v1/fleet/scheduled-services/'.$schedule['id'])->assertJsonPath('data.last_service_km', 12000)->assertJsonPath('data.next_due_km', 22000);

    $this->deleteJson('/api/v1/fleet/services/'.$record['id'])->assertNoContent();
    $this->getJson('/api/v1/fleet/scheduled-services/'.$schedule['id'])->assertJsonPath('data.last_service_km', null);
    $this->putJson('/api/v1/fleet/scheduled-services/'.$schedule['id'], ['name' => 'Oil and filters', 'is_active' => false])->assertOk()->assertJsonPath('data.is_overdue', false);
    $this->deleteJson('/api/v1/fleet/scheduled-services/'.$schedule['id'])->assertNoContent();
});

test('compliance deadlines raise expiry alerts and renewing opens the next one', function (): void {
    $vehicle = fltVehicle();
    $insurance = $this->postJson('/api/v1/fleet/deadlines', ['vehicle_id' => $vehicle['id'], 'deadline_type' => 'insurance', 'provider' => 'Generali', 'reference' => 'POL-1', 'expires_on' => '2027-06-30', 'cost' => '650'])
        ->assertCreated()->assertJsonPath('data.days_to_expiry', 15)->assertJsonPath('data.is_expired', false)->json('data');
    $this->postJson('/api/v1/fleet/deadlines', ['vehicle_id' => $vehicle['id'], 'deadline_type' => 'road_tax', 'expires_on' => '2027-06-01', 'cost' => '210'])
        ->assertCreated()->assertJsonPath('data.is_expired', true)->assertJsonPath('data.days_to_expiry', -14);
    $this->postJson('/api/v1/fleet/deadlines', ['vehicle_id' => $vehicle['id'], 'deadline_type' => 'inspection', 'expires_on' => '2028-01-31'])->assertCreated();

    $this->getJson('/api/v1/fleet/deadlines?expiring=30')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/fleet/deadlines?expiring=7')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/fleet/deadlines?expired=1')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/fleet/deadlines?deadline_type=inspection')->assertOk()->assertJsonCount(1, 'data');

    $this->postJson('/api/v1/fleet/deadlines/'.$insurance['id'].'/renew', ['expires_on' => '2027-06-30'])->assertUnprocessable()->assertJsonValidationErrors(['expires_on']);
    $renewed = $this->postJson('/api/v1/fleet/deadlines/'.$insurance['id'].'/renew', ['expires_on' => '2028-06-30', 'cost' => '700'])
        ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.provider', 'Generali')->assertJsonPath('data.reference', 'POL-1')->assertJsonPath('data.cost', '700.00')->json('data');
    expect($renewed['id'])->not->toBe($insurance['id']);

    $this->getJson('/api/v1/fleet/deadlines/'.$insurance['id'])->assertJsonPath('data.status', 'renewed')->assertJsonPath('data.is_expired', false);
    $this->postJson('/api/v1/fleet/deadlines/'.$insurance['id'].'/renew', ['expires_on' => '2029-06-30'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->getJson('/api/v1/fleet/deadlines?expiring=30')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/fleet/deadlines?status=renewed')->assertOk()->assertJsonCount(1, 'data');

    $this->putJson('/api/v1/fleet/deadlines/'.$renewed['id'], ['cost' => '710'])->assertOk()->assertJsonPath('data.cost', '710.00');
    $this->deleteJson('/api/v1/fleet/deadlines/'.$renewed['id'])->assertNoContent();
});

test('fines are attributed to the driver of the day and tracked until paid', function (): void {
    $vehicle = fltVehicle();
    $anna = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    $luca = Employee::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->postJson('/api/v1/fleet/assignments', ['vehicle_id' => $vehicle['id'], 'employee_id' => $anna->id, 'starts_on' => '2027-01-01', 'ends_on' => '2027-03-01', 'end_odometer_km' => 11000])->assertCreated();
    $this->postJson('/api/v1/fleet/assignments', ['vehicle_id' => $vehicle['id'], 'employee_id' => $luca->id, 'starts_on' => '2027-03-01', 'start_odometer_km' => 11000])->assertCreated();

    $annaFine = $this->postJson('/api/v1/fleet/incidents', ['vehicle_id' => $vehicle['id'], 'occurred_on' => '2027-02-10', 'description' => 'Speeding', 'amount' => '85', 'reference' => 'V-100', 'due_on' => '2027-06-01', 'location' => 'A4'])
        ->assertCreated()->assertJsonPath('data.employee_id', $anna->id)->assertJsonPath('data.payment_status', 'unpaid')->assertJsonPath('data.is_overdue', true)->json('data');
    $lucaFine = $this->postJson('/api/v1/fleet/incidents', ['vehicle_id' => $vehicle['id'], 'occurred_on' => '2027-03-01', 'description' => 'ZTL', 'amount' => '100'])
        ->assertCreated()->assertJsonPath('data.employee_id', $luca->id)->json('data');
    $this->postJson('/api/v1/fleet/incidents', ['vehicle_id' => $vehicle['id'], 'occurred_on' => '2027-02-10', 'description' => 'Parking', 'amount' => '30', 'employee_id' => $luca->id, 'kind' => 'fine'])
        ->assertCreated()->assertJsonPath('data.employee_id', $luca->id);
    $this->postJson('/api/v1/fleet/incidents', ['vehicle_id' => $vehicle['id'], 'occurred_on' => '2026-01-10', 'description' => 'Scratch', 'kind' => 'damage'])
        ->assertCreated()->assertJsonPath('data.employee_id', null);

    $this->postJson('/api/v1/fleet/incidents/'.$annaFine['id'].'/pay', [])->assertOk()
        ->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.paid_on', '2027-06-15')->assertJsonPath('data.is_overdue', false);
    $this->postJson('/api/v1/fleet/incidents/'.$lucaFine['id'].'/pay', ['paid_on' => '2027-06-01'])->assertOk()->assertJsonPath('data.paid_on', '2027-06-01');
    $this->putJson('/api/v1/fleet/incidents/'.$lucaFine['id'], ['payment_status' => 'contested'])->assertOk()->assertJsonPath('data.paid_on', null);
    $this->putJson('/api/v1/fleet/incidents/'.$lucaFine['id'], ['employee_id' => $anna->id])->assertOk()->assertJsonPath('data.employee.id', $anna->id);
    $this->putJson('/api/v1/fleet/incidents/'.$lucaFine['id'], ['employee_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['employee_id']);

    $this->getJson('/api/v1/fleet/incidents?employee_id='.$luca->id)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/fleet/incidents?payment_status=unpaid')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/fleet/incidents?kind=damage&vehicle_id='.$vehicle['id'])->assertOk()->assertJsonCount(1, 'data');
});

test('the dashboard sums the cost of ownership and the upcoming obligations', function (): void {
    $vehicle = fltVehicle(['purchase_value' => '20000', 'status' => 'active']);
    fltVehicle(['status' => 'sold']);

    fltFuel($vehicle['id'], '2027-01-10', 10000, '40')->assertCreated();
    fltFuel($vehicle['id'], '2027-02-10', 10600, '39')->assertCreated();
    fltFuel($vehicle['id'], '2027-03-10', 10900, '10', ['is_full_tank' => false])->assertCreated();
    fltFuel($vehicle['id'], '2027-04-10', 11200, '18')->assertCreated();
    $this->postJson('/api/v1/fleet/services', ['vehicle_id' => $vehicle['id'], 'service_date' => '2027-02-20', 'odometer_km' => 10700, 'description' => 'Brakes', 'cost' => '180'])->assertCreated();
    $this->postJson('/api/v1/fleet/deadlines', ['vehicle_id' => $vehicle['id'], 'deadline_type' => 'insurance', 'expires_on' => '2027-07-01', 'cost' => '600'])->assertCreated();
    $this->postJson('/api/v1/fleet/scheduled-services', ['vehicle_id' => $vehicle['id'], 'name' => 'Overdue by date', 'next_due_on' => '2027-06-01'])->assertCreated();
    $this->postJson('/api/v1/fleet/incidents', ['vehicle_id' => $vehicle['id'], 'occurred_on' => '2027-03-01', 'description' => 'Speeding', 'amount' => '85'])->assertCreated();
    $this->postJson('/api/v1/fleet/incidents', ['vehicle_id' => $vehicle['id'], 'occurred_on' => '2027-03-02', 'description' => 'Cancelled fine', 'amount' => '500', 'payment_status' => 'cancelled'])->assertCreated();

    $costs = $this->getJson('/api/v1/fleet/vehicles/'.$vehicle['id'].'/costs')->assertOk()->json('data');
    expect($costs['fuel_cost'])->toBe('192.60')
        ->and($costs['service_cost'])->toBe('180.00')
        ->and($costs['deadline_cost'])->toBe('600.00')
        ->and($costs['incident_cost'])->toBe('85.00')
        ->and($costs['running_cost'])->toBe('1057.60')
        ->and($costs['distance_km'])->toBe(1200)
        ->and($costs['cost_per_km'])->toBe('0.8813')
        ->and($costs['total_cost_of_ownership'])->toBe('21057.60')
        ->and((float) $costs['average_consumption_per_100km'])->toEqualWithDelta(5.5835, 0.001);

    $dashboard = $this->getJson('/api/v1/fleet/dashboard')->assertOk()
        ->assertJsonPath('data.vehicles.total', 2)->assertJsonPath('data.vehicles.by_status.active', 1)->assertJsonPath('data.vehicles.by_status.sold', 1)
        ->assertJsonPath('data.totals.running_cost', '1057.60')->assertJsonPath('data.totals.cost_per_km', '0.8813')
        ->assertJsonPath('data.unpaid_incidents.count', 1)->assertJsonPath('data.unpaid_incidents.amount', '85.00')
        ->assertJsonCount(1, 'data.upcoming_deadlines')->assertJsonCount(1, 'data.overdue_services')
        ->assertJsonCount(2, 'data.per_vehicle')->json('data');
    expect($dashboard['upcoming_deadlines'][0]['days_to_expiry'])->toBe(16)
        ->and($dashboard['overdue_services'][0]['name'])->toBe('Overdue by date');

    $this->getJson('/api/v1/fleet/dashboard?days=5')->assertOk()->assertJsonCount(0, 'data.upcoming_deadlines');
    $this->getJson('/api/v1/fleet/dashboard?days=500')->assertUnprocessable();
});

test('the factories build valid records of the company', function (): void {
    $vehicle = FleetVehicle::factory()->create(['tenant_id' => $this->tenant->id]);

    expect($vehicle->tenant_id)->toBe($this->tenant->id)->and($vehicle->isRetired())->toBeFalse();
    $this->getJson('/api/v1/fleet/vehicles/'.$vehicle->id)->assertOk();
});
