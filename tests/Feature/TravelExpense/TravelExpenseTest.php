<?php

declare(strict_types=1);

use App\Models\Expense;
use App\Models\LookupItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\ComprehensiveLookupMasterSeeder;
use Database\Seeders\ExpenseLookupSeeder;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\TravelExpense\Models\TravelPerDiemRate;
use Modules\TravelExpense\Models\TravelTrip;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-10 09:00:00');
    $this->tenant = trvTenant('Viaggi Rossi', 'IT', 'EUR');
    $this->owner = trvMember($this->tenant, 'owner');
    $this->admin = trvMember($this->tenant, 'admin');
    $this->accountant = trvMember($this->tenant, 'accountant');
    $this->employee = trvMember($this->tenant, 'member');
    $this->colleague = trvMember($this->tenant, 'member');
    trvAct($this->employee, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function trvTenant(string $name, string $country, string $currency): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => $country, 'default_currency' => $currency]);
}

function trvMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@travel-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function trvAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function trvTrip(array $overrides = []): array
{
    return test()->postJson('/api/v1/travel/trips', [
        'purpose' => 'Customer workshop in Bologna',
        'destination_city' => 'Bologna',
        'destination_country' => 'IT',
        'start_date' => '2027-06-14',
        'end_date' => '2027-06-16',
        'departure_time' => '08:00',
        'return_time' => '20:00',
        'estimated_budget' => '600.00',
        ...$overrides,
    ])->assertCreated()->json('data');
}

function trvSeedRates(string $jurisdiction = 'IT', int $year = 2027, bool $asAccountant = true): void
{
    if ($asAccountant) {
        trvAct(test()->accountant, test()->tenant);
    }

    test()->postJson('/api/v1/travel/rates/defaults', ['jurisdiction' => $jurisdiction, 'year' => $year])->assertOk();

    if ($asAccountant) {
        trvAct(test()->employee, test()->tenant);
    }
}

/**
 * Moves a trip to approved through the real flow: the employee submits, the administrator approves.
 *
 * @return array<string, mixed>
 */
function trvApprove(string $tripId, ?string $advance = null): array
{
    test()->postJson("/api/v1/travel/trips/{$tripId}/submit")->assertOk();
    trvAct(test()->admin, test()->tenant);
    $response = test()->postJson("/api/v1/travel/trips/{$tripId}/approve", $advance === null ? [] : ['advance_amount' => $advance])->assertOk()->json('data');
    trvAct(test()->employee, test()->tenant);

    return $response;
}

function trvExpense(Tenant $tenant, User $user, array $overrides = []): Expense
{
    $category = LookupItem::query()->whereHas('category', fn ($query) => $query->where('code', 'expense_category'))->first();

    if ($category === null) {
        test()->seed(ComprehensiveLookupMasterSeeder::class);
        test()->seed(ExpenseLookupSeeder::class);
        $category = LookupItem::query()->whereHas('category', fn ($query) => $query->where('code', 'expense_category'))->firstOrFail();
    }

    return Expense::create([
        'tenant_id' => $tenant->id,
        'expense_number' => 'EXP-2027-'.Str::upper(Str::random(8)),
        'category_id' => $category->id,
        'date' => '2027-06-15',
        'amount_net' => '81.97',
        'amount_gross' => '100.00',
        'currency' => 'EUR',
        'status' => 'approved',
        'is_reimbursable' => true,
        'user_id' => $user->id,
        ...$overrides,
    ]);
}

it('numbers the trips per company and year and keeps a member on their own trips', function (): void {
    $first = trvTrip();
    $second = trvTrip(['start_date' => '2027-07-05', 'end_date' => '2027-07-06']);

    expect($first['number'])->toBe('TR-2027-0001')
        ->and($second['number'])->toBe('TR-2027-0002')
        ->and($first['status'])->toBe('draft')
        ->and($first['currency'])->toBe('EUR')
        ->and($first['rate_jurisdiction'])->toBe('IT')
        ->and($first['employee']['id'])->toBe($this->employee->id);

    $this->postJson('/api/v1/travel/trips', [
        'user_id' => $this->colleague->id, 'purpose' => 'x', 'destination_city' => 'Roma', 'destination_country' => 'IT', 'start_date' => '2027-08-01', 'end_date' => '2027-08-02',
    ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

    trvAct($this->colleague, $this->tenant);
    $this->getJson("/api/v1/travel/trips/{$first['id']}")->assertForbidden();
    expect($this->getJson('/api/v1/travel/trips')->assertOk()->json('data'))->toBe([]);

    trvAct($this->accountant, $this->tenant);
    expect($this->getJson('/api/v1/travel/trips')->assertOk()->json('data'))->toHaveCount(2);
    $this->getJson("/api/v1/travel/trips/{$first['id']}")->assertOk();
});

it('takes the jurisdiction and the currency of a Swiss company', function (): void {
    $swiss = trvTenant('Viaggi Bernasconi', 'CH', 'CHF');
    trvAct(trvMember($swiss, 'owner'), $swiss);

    $trip = trvTrip(['destination_country' => 'CH', 'destination_city' => 'Lugano']);

    expect($trip['rate_jurisdiction'])->toBe('CH')->and($trip['currency'])->toBe('CHF');
});

it('refuses an advance above the estimated budget and invalid dates', function (): void {
    $this->postJson('/api/v1/travel/trips', [
        'purpose' => 'x', 'destination_city' => 'Roma', 'destination_country' => 'IT', 'start_date' => '2027-08-05', 'end_date' => '2027-08-04',
    ])->assertUnprocessable()->assertJsonValidationErrors('end_date');

    $this->postJson('/api/v1/travel/trips', [
        'purpose' => 'x', 'destination_city' => 'Roma', 'destination_country' => 'IT', 'start_date' => '2027-08-05', 'end_date' => '2027-08-05',
        'departure_time' => '18:00', 'return_time' => '09:00',
    ])->assertUnprocessable()->assertJsonValidationErrors('return_time');

    $this->postJson('/api/v1/travel/trips', [
        'purpose' => 'x', 'destination_city' => 'Roma', 'destination_country' => 'IT', 'start_date' => '2027-08-05', 'end_date' => '2027-08-06',
        'estimated_budget' => '200.00', 'advance_amount' => '250.00',
    ])->assertUnprocessable()->assertJsonValidationErrors('advance_amount');
});

it('runs the approval flow with validated transitions and the separation of duties', function (): void {
    $trip = trvTrip(['advance_amount' => '300.00']);
    $id = $trip['id'];

    $this->postJson("/api/v1/travel/trips/{$id}/approve")->assertForbidden();
    $this->postJson("/api/v1/travel/trips/{$id}/complete")->assertUnprocessable();

    $this->postJson("/api/v1/travel/trips/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
    $this->putJson("/api/v1/travel/trips/{$id}", ['purpose' => 'changed', 'destination_city' => 'Bologna', 'destination_country' => 'IT', 'start_date' => '2027-06-14', 'end_date' => '2027-06-16'])->assertUnprocessable();
    $this->postJson("/api/v1/travel/trips/{$id}/submit")->assertUnprocessable();
    $this->postJson("/api/v1/travel/trips/{$id}/approve")->assertForbidden();

    $this->postJson("/api/v1/travel/trips/{$id}/withdraw")->assertOk()->assertJsonPath('data.status', 'draft');
    $this->putJson("/api/v1/travel/trips/{$id}", ['purpose' => 'Workshop and training', 'destination_city' => 'Bologna', 'destination_country' => 'IT', 'start_date' => '2027-06-14', 'end_date' => '2027-06-16', 'estimated_budget' => '600.00', 'advance_amount' => '300.00'])
        ->assertOk()->assertJsonPath('data.purpose', 'Workshop and training');
    $this->postJson("/api/v1/travel/trips/{$id}/submit")->assertOk();

    trvAct($this->admin, $this->tenant);
    $this->postJson("/api/v1/travel/trips/{$id}/reject", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/travel/trips/{$id}/reject", ['reason' => 'Budget frozen this month'])
        ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Budget frozen this month');
    $this->postJson("/api/v1/travel/trips/{$id}/approve")->assertUnprocessable();

    trvAct($this->employee, $this->tenant);
    $this->putJson("/api/v1/travel/trips/{$id}", ['purpose' => 'x', 'destination_city' => 'Bologna', 'destination_country' => 'IT', 'start_date' => '2027-06-14', 'end_date' => '2027-06-16'])->assertUnprocessable();
    $this->postJson("/api/v1/travel/trips/{$id}/reopen")->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.rejection_reason', null);
    $this->postJson("/api/v1/travel/trips/{$id}/submit")->assertOk();

    trvAct($this->admin, $this->tenant);
    $this->postJson("/api/v1/travel/trips/{$id}/approve", ['advance_amount' => '400.00'])->assertOk()
        ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.advance_amount', '400.00')->assertJsonPath('data.approved_by', $this->admin->id);

    trvAct($this->employee, $this->tenant);
    $this->postJson("/api/v1/travel/trips/{$id}/complete")->assertUnprocessable();
    $this->travelTo('2027-06-16 21:00:00');
    $this->postJson("/api/v1/travel/trips/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
    $this->deleteJson("/api/v1/travel/trips/{$id}")->assertUnprocessable();

    $actions = AuditLedgerEntry::query()->where('tenant_id', $this->tenant->id)->where('module_code', 'MODULE_41_TRAVEL_EXPENSE')->pluck('action')->all();
    expect($actions)->toContain('TRAVEL_TRIP_SUBMITTED', 'TRAVEL_TRIP_REJECTED', 'TRAVEL_TRIP_APPROVED', 'TRAVEL_TRIP_COMPLETED');
});

it('lets the owner approve their own trip but nobody else', function (): void {
    trvAct($this->admin, $this->tenant);
    $adminTrip = trvTrip();
    $this->postJson("/api/v1/travel/trips/{$adminTrip['id']}/submit")->assertOk();
    $this->postJson("/api/v1/travel/trips/{$adminTrip['id']}/approve")->assertForbidden();

    trvAct($this->owner, $this->tenant);
    $this->postJson("/api/v1/travel/trips/{$adminTrip['id']}/approve")->assertOk();

    $ownerTrip = trvTrip(['start_date' => '2027-09-01', 'end_date' => '2027-09-02']);
    $this->postJson("/api/v1/travel/trips/{$ownerTrip['id']}/submit")->assertOk();
    $this->postJson("/api/v1/travel/trips/{$ownerTrip['id']}/approve")->assertOk();
});

it('refuses to submit a trip that overlaps another active trip of the employee', function (): void {
    $first = trvTrip();
    $this->postJson("/api/v1/travel/trips/{$first['id']}/submit")->assertOk();

    $overlapping = trvTrip(['start_date' => '2027-06-16', 'end_date' => '2027-06-18']);
    $this->postJson("/api/v1/travel/trips/{$overlapping['id']}/submit")->assertUnprocessable()->assertJsonValidationErrors('start_date');
});

it('calculates the Italian per diem with the art. 51 thresholds and the one-third deductions', function (): void {
    trvSeedRates();
    $trip = trvTrip();
    trvApprove($trip['id']);

    $days = $this->putJson("/api/v1/travel/trips/{$trip['id']}/per-diem")->assertOk()->json();

    expect($days['data'])->toHaveCount(3)
        ->and(array_column($days['data'], 'amount'))->toBe(['46.48', '46.48', '46.48'])
        ->and(array_column($days['data'], 'hours_away'))->toBe(['16.00', '24.00', '20.00'])
        ->and($days['totals'])->toBe(['amount' => '139.44', 'tax_free_amount' => '139.44', 'taxable_amount' => '0.00', 'currency' => 'EUR']);

    $withLodging = $this->putJson("/api/v1/travel/trips/{$trip['id']}/per-diem", ['days' => [
        ['day' => '2027-06-14', 'lodging_provided' => true],
        ['day' => '2027-06-15', 'lunch_provided' => true, 'dinner_provided' => true],
        ['day' => '2027-06-16', 'lodging_provided' => true, 'lunch_provided' => true],
    ]])->assertOk()->json('data');

    expect($withLodging[0])->toMatchArray(['gross_amount' => '46.48', 'deduction_amount' => '15.49', 'amount' => '30.99', 'tax_free_amount' => '30.99', 'taxable_amount' => '0.00'])
        ->and($withLodging[1]['amount'])->toBe('30.99')
        ->and($withLodging[2]['day_factor'])->toBe('1.00');
    expect($withLodging[2]['amount'])->toBe('15.49');

    $kept = $this->putJson("/api/v1/travel/trips/{$trip['id']}/per-diem")->assertOk()->json('data');
    expect($kept[0]['lodging_provided'])->toBeTrue()->and($kept[0]['amount'])->toBe('30.99');
});

it('counts a short day in half or not at all and a long one in full', function (): void {
    trvSeedRates();
    $trip = trvTrip(['start_date' => '2027-06-14', 'end_date' => '2027-06-14', 'departure_time' => '08:00', 'return_time' => '17:00']);

    $half = $this->putJson("/api/v1/travel/trips/{$trip['id']}/per-diem")->assertOk()->json('data');
    expect($half)->toHaveCount(1)->and($half[0])->toMatchArray(['hours_away' => '9.00', 'day_factor' => '0.50', 'amount' => '23.24']);

    $this->putJson("/api/v1/travel/trips/{$trip['id']}", ['purpose' => 'Short', 'destination_city' => 'Bologna', 'destination_country' => 'IT', 'start_date' => '2027-06-14', 'end_date' => '2027-06-14', 'departure_time' => '09:00', 'return_time' => '14:00'])->assertOk();
    expect($this->getJson("/api/v1/travel/trips/{$trip['id']}/per-diem")->json('data'))->toBe([]);
    $none = $this->putJson("/api/v1/travel/trips/{$trip['id']}/per-diem")->assertOk()->json('data');
    expect($none[0])->toMatchArray(['day_factor' => '0.00', 'amount' => '0.00']);
});

it('uses the abroad allowance, a country row before the generic one and the latest earlier year', function (): void {
    $this->postJson('/api/v1/travel/rates/per-diem', ['jurisdiction' => 'IT', 'year' => 2026, 'scope' => 'abroad', 'currency' => 'EUR', 'daily_amount' => '77.47', 'tax_free_daily_amount' => '77.47'])->assertForbidden();

    trvAct($this->accountant, $this->tenant);
    $this->postJson('/api/v1/travel/rates/per-diem', ['jurisdiction' => 'IT', 'year' => 2026, 'scope' => 'abroad', 'currency' => 'EUR', 'daily_amount' => '77.47', 'tax_free_daily_amount' => '77.47'])->assertCreated();
    $this->postJson('/api/v1/travel/rates/per-diem', ['jurisdiction' => 'IT', 'year' => 2026, 'scope' => 'abroad', 'currency' => 'EUR', 'daily_amount' => '80.00'])->assertUnprocessable()->assertJsonValidationErrors('year');

    trvAct($this->employee, $this->tenant);
    $germany = trvTrip(['destination_country' => 'DE', 'destination_city' => 'Munich', 'start_date' => '2027-06-14', 'end_date' => '2027-06-14', 'departure_time' => '00:00', 'return_time' => '23:59']);
    $this->putJson("/api/v1/travel/trips/{$germany['id']}/per-diem")->assertOk()->assertJsonPath('data.0.amount', '77.47');

    trvAct($this->accountant, $this->tenant);
    $this->postJson('/api/v1/travel/rates/per-diem', ['jurisdiction' => 'IT', 'year' => 2027, 'scope' => 'abroad', 'country' => 'de', 'currency' => 'EUR', 'daily_amount' => '90.00', 'tax_free_daily_amount' => '77.47'])->assertCreated()->assertJsonPath('data.country', 'DE');

    trvAct($this->employee, $this->tenant);
    $this->putJson("/api/v1/travel/trips/{$germany['id']}/per-diem")->assertOk()
        ->assertJsonPath('data.0.amount', '90.00')->assertJsonPath('data.0.tax_free_amount', '77.47')->assertJsonPath('data.0.taxable_amount', '12.53');

    $france = trvTrip(['destination_country' => 'FR', 'destination_city' => 'Lyon', 'start_date' => '2027-07-14', 'end_date' => '2027-07-14', 'departure_time' => '00:00', 'return_time' => '23:59']);
    $this->putJson("/api/v1/travel/trips/{$france['id']}/per-diem")->assertOk()->assertJsonPath('data.0.amount', '77.47');
});

it('calculates the Swiss flat rates in francs, deducting every meal and keeping the whole allowance tax free', function (): void {
    $swiss = trvTenant('Viaggi Bernasconi', 'CH', 'CHF');
    $owner = trvMember($swiss, 'owner');
    trvAct($owner, $swiss);
    trvSeedRates('CH', 2027, false);

    $trip = trvTrip(['destination_country' => 'CH', 'destination_city' => 'Lugano', 'start_date' => '2027-06-14', 'end_date' => '2027-06-15', 'departure_time' => '00:00', 'return_time' => '23:59']);
    expect($trip['currency'])->toBe('CHF');

    $days = $this->putJson("/api/v1/travel/trips/{$trip['id']}/per-diem", ['days' => [
        ['day' => '2027-06-14', 'breakfast_provided' => true, 'lunch_provided' => true],
        ['day' => '2027-06-15', 'lunch_provided' => true, 'dinner_provided' => true],
    ]])->assertOk();

    expect($days->json('data.0.amount'))->toBe('30.00')
        ->and($days->json('data.1.amount'))->toBe('15.00')
        ->and($days->json('totals'))->toBe(['amount' => '45.00', 'tax_free_amount' => '45.00', 'taxable_amount' => '0.00', 'currency' => 'CHF']);
});

it('taxes the part of the allowance above the tax-free threshold', function (): void {
    trvAct($this->accountant, $this->tenant);
    $this->postJson('/api/v1/travel/rates/per-diem', ['jurisdiction' => 'IT', 'year' => 2027, 'scope' => 'domestic', 'currency' => 'EUR', 'daily_amount' => '60.00', 'tax_free_daily_amount' => '46.48'])->assertCreated();
    trvAct($this->employee, $this->tenant);
    $trip = trvTrip(['start_date' => '2027-06-14', 'end_date' => '2027-06-14', 'departure_time' => '00:00', 'return_time' => '23:59']);

    $this->putJson("/api/v1/travel/trips/{$trip['id']}/per-diem")->assertOk()
        ->assertJsonPath('data.0.amount', '60.00')->assertJsonPath('data.0.taxable_amount', '13.52')
        ->assertJsonPath('totals.taxable_amount', '13.52');
});

it('refuses to calculate without a rate or with a rate in another currency', function (): void {
    $trip = trvTrip();
    $this->putJson("/api/v1/travel/trips/{$trip['id']}/per-diem")->assertUnprocessable()->assertJsonValidationErrors('rate');

    TravelPerDiemRate::factory()->create(['tenant_id' => $this->tenant->id, 'currency' => 'CHF']);
    TravelPerDiemRate::factory()->create(['tenant_id' => $this->tenant->id, 'scope' => 'abroad', 'currency' => 'CHF']);
    $this->putJson("/api/v1/travel/trips/{$trip['id']}/per-diem")->assertUnprocessable()->assertJsonValidationErrors('currency');

    trvSeedRates();
    $this->putJson("/api/v1/travel/trips/{$trip['id']}/per-diem", ['days' => [['day' => '2027-07-01']]])->assertUnprocessable();
});

it('reimburses mileage by kilometre with a snapshotted rate', function (): void {
    $trip = trvTrip();
    $this->postJson("/api/v1/travel/trips/{$trip['id']}/mileage-claims", ['travel_date' => '2027-06-14', 'from_location' => 'Milano', 'to_location' => 'Bologna', 'vehicle_type' => 'car', 'distance_km' => '84.50'])
        ->assertUnprocessable()->assertJsonValidationErrors('vehicle_type');

    trvSeedRates();
    $claim = $this->postJson("/api/v1/travel/trips/{$trip['id']}/mileage-claims", ['travel_date' => '2027-06-14', 'from_location' => 'Milano', 'to_location' => 'Bologna', 'vehicle_type' => 'car', 'round_trip' => true, 'distance_km' => '84.50'])
        ->assertCreated()->json('data');

    expect($claim)->toMatchArray(['rate_per_km' => '0.4000', 'amount' => '67.60', 'distance_km' => '84.50', 'round_trip' => true, 'currency' => 'EUR']);

    $this->postJson("/api/v1/travel/trips/{$trip['id']}/mileage-claims", ['travel_date' => '2027-06-20', 'from_location' => 'a', 'to_location' => 'b', 'vehicle_type' => 'car', 'distance_km' => '10'])
        ->assertUnprocessable()->assertJsonValidationErrors('travel_date');

    trvAct($this->accountant, $this->tenant);
    $rateId = $this->getJson('/api/v1/travel/rates/mileage?vehicle_type=car')->assertOk()->json('data.0.id');
    $this->putJson("/api/v1/travel/rates/mileage/{$rateId}", ['jurisdiction' => 'IT', 'year' => 2027, 'vehicle_type' => 'car', 'currency' => 'EUR', 'rate_per_km' => '0.5000'])->assertOk();

    trvAct($this->employee, $this->tenant);
    expect($this->getJson("/api/v1/travel/trips/{$trip['id']}")->json('data.mileage_claims.0.amount'))->toBe('67.60');
    $updated = $this->putJson("/api/v1/travel/trips/{$trip['id']}/mileage-claims/{$claim['id']}", ['travel_date' => '2027-06-14', 'from_location' => 'Milano', 'to_location' => 'Bologna', 'vehicle_type' => 'car', 'round_trip' => false, 'distance_km' => '84.50'])
        ->assertOk()->json('data');
    expect($updated['amount'])->toBe('42.25');

    $this->deleteJson("/api/v1/travel/trips/{$trip['id']}/mileage-claims/{$claim['id']}")->assertNoContent();
    expect($this->getJson("/api/v1/travel/trips/{$trip['id']}")->json('data.mileage_claims'))->toBe([]);
});

it('keeps an itinerary of legs, requires dates for a hotel and freezes it while the trip is under approval', function (): void {
    $trip = trvTrip();
    $id = $trip['id'];

    $flight = $this->postJson("/api/v1/travel/trips/{$id}/legs", ['leg_type' => 'flight', 'from_location' => 'Milano Linate', 'to_location' => 'Roma Fiumicino', 'departs_at' => '2027-06-14T07:00:00', 'arrives_at' => '2027-06-14T08:10:00', 'booking_status' => 'booked', 'booked_cost' => '120.00'])
        ->assertCreated()->json('data');
    $this->postJson("/api/v1/travel/trips/{$id}/legs", ['leg_type' => 'hotel', 'to_location' => 'Hotel Centro', 'booked_cost' => '90.00'])->assertUnprocessable()->assertJsonValidationErrors('departs_at');
    $this->postJson("/api/v1/travel/trips/{$id}/legs", ['leg_type' => 'hotel', 'to_location' => 'Hotel Centro', 'departs_at' => '2027-06-14T15:00:00', 'arrives_at' => '2027-06-16T11:00:00', 'booked_cost' => '180.00'])->assertCreated()->assertJsonPath('data.sequence', 2);
    $train = $this->postJson("/api/v1/travel/trips/{$id}/legs", ['leg_type' => 'train', 'to_location' => 'Milano', 'booked_cost' => '60.00', 'booking_status' => 'cancelled'])->assertCreated()->json('data');
    $this->postJson("/api/v1/travel/trips/{$id}/legs", ['leg_type' => 'car', 'to_location' => 'x', 'booked_cost' => '10.00', 'currency' => 'CHF'])->assertUnprocessable()->assertJsonValidationErrors('currency');
    $this->postJson("/api/v1/travel/trips/{$id}/legs", ['leg_type' => 'boat', 'to_location' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('leg_type');

    $this->putJson("/api/v1/travel/trips/{$id}/legs/{$flight['id']}", ['leg_type' => 'flight', 'from_location' => 'Milano Linate', 'to_location' => 'Roma Fiumicino', 'booked_cost' => '140.00'])->assertOk()->assertJsonPath('data.booked_cost', '140.00');
    $this->deleteJson("/api/v1/travel/trips/{$id}/legs/{$train['id']}")->assertNoContent();
    expect($this->getJson("/api/v1/travel/trips/{$id}")->json('data.legs'))->toHaveCount(2);

    $report = $this->getJson("/api/v1/travel/trips/{$id}/report")->assertOk()->json('data');
    expect($report['budget']['booked_itinerary'])->toBe('320.00')
        ->and($report['budget']['booked_by_type'])->toBe(['flight' => '140.00', 'hotel' => '180.00']);

    $this->postJson("/api/v1/travel/trips/{$id}/submit")->assertOk();
    $this->postJson("/api/v1/travel/trips/{$id}/legs", ['leg_type' => 'car', 'to_location' => 'x'])->assertUnprocessable();
    $this->deleteJson("/api/v1/travel/trips/{$id}/legs/{$flight['id']}")->assertUnprocessable();
});

it('reconciles the advance against per diem, mileage and approved expenses with the balance owed to the employee', function (): void {
    trvSeedRates();
    $trip = trvTrip(['advance_amount' => '300.00']);
    $id = $trip['id'];
    trvApprove($id);

    $this->postJson("/api/v1/travel/trips/{$id}/advance-paid", ['paid_at' => '2027-06-12'])->assertForbidden();
    trvAct($this->accountant, $this->tenant);
    $this->postJson("/api/v1/travel/trips/{$id}/advance-paid", ['paid_at' => '2027-06-12'])->assertOk()->assertJsonPath('data.advance_paid_at', '2027-06-12');
    $this->postJson("/api/v1/travel/trips/{$id}/advance-paid", ['paid_at' => '2027-06-12'])->assertUnprocessable();

    trvAct($this->employee, $this->tenant);
    $this->putJson("/api/v1/travel/trips/{$id}/per-diem")->assertOk();
    $this->postJson("/api/v1/travel/trips/{$id}/mileage-claims", ['travel_date' => '2027-06-14', 'from_location' => 'Milano', 'to_location' => 'Bologna', 'vehicle_type' => 'car', 'round_trip' => true, 'distance_km' => '84.50'])->assertCreated();

    $pending = trvExpense($this->tenant, $this->employee, ['status' => 'submitted', 'amount_gross' => '40.00']);
    $approved = trvExpense($this->tenant, $this->employee);
    $foreign = trvExpense($this->tenant, $this->colleague);
    $private = trvExpense($this->tenant, $this->employee, ['is_reimbursable' => false]);
    $swiss = trvExpense($this->tenant, $this->employee, ['currency' => 'CHF']);
    $tooLate = trvExpense($this->tenant, $this->employee, ['date' => '2027-12-31']);

    foreach ([$foreign, $private, $swiss, $tooLate] as $refused) {
        $this->postJson("/api/v1/travel/trips/{$id}/expenses", ['expense_id' => $refused->id])->assertUnprocessable()->assertJsonValidationErrors('expense_id');
    }

    $this->postJson("/api/v1/travel/trips/{$id}/expenses", ['expense_id' => (string) Str::uuid7()])->assertUnprocessable();
    $this->postJson("/api/v1/travel/trips/{$id}/expenses", ['expense_id' => $approved->id])->assertCreated();
    $this->postJson("/api/v1/travel/trips/{$id}/expenses", ['expense_id' => $approved->id])->assertUnprocessable();
    $this->postJson("/api/v1/travel/trips/{$id}/expenses", ['expense_id' => $pending->id])->assertCreated();

    $summary = $this->getJson("/api/v1/travel/trips/{$id}/reconciliation")->assertOk()->json('data');
    expect($summary)->toMatchArray([
        'per_diem_total' => '139.44', 'mileage_total' => '67.60', 'expenses_total' => '100.00', 'total_due' => '307.04',
        'advance_paid' => '300.00', 'balance' => '7.04', 'balance_amount' => '7.04', 'direction' => 'owed_to_employee', 'pending_expenses' => 1,
    ]);

    $this->travelTo('2027-06-17 09:00:00');
    $this->postJson("/api/v1/travel/trips/{$id}/complete")->assertOk();
    trvAct($this->accountant, $this->tenant);
    $this->postJson("/api/v1/travel/trips/{$id}/reconcile")->assertUnprocessable()->assertJsonValidationErrors('expenses');

    $pending->update(['status' => 'rejected']);
    $reconciled = $this->postJson("/api/v1/travel/trips/{$id}/reconcile")->assertOk()->json('data');
    expect($reconciled)->toMatchArray([
        'status' => 'reconciled', 'reconciled_per_diem_total' => '139.44', 'reconciled_mileage_total' => '67.60', 'reconciled_expenses_total' => '100.00',
        'reconciled_advance_total' => '300.00', 'reconciled_balance' => '7.04', 'reconciled_direction' => 'owed_to_employee', 'reconciled_by' => $this->accountant->id,
    ]);

    $this->postJson("/api/v1/travel/trips/{$id}/reconcile")->assertUnprocessable();
    $this->deleteJson("/api/v1/travel/trips/{$id}/expenses/{$approved->id}")->assertUnprocessable();

    $report = $this->getJson("/api/v1/travel/trips/{$id}/report")->assertOk()->json('data');
    expect($report['frozen'])->toBeTrue()
        ->and($report['budget'])->toMatchArray(['estimated' => '600.00', 'actual' => '307.04', 'variance' => '-292.96', 'within_budget' => true, 'utilization_percent' => '51.17'])
        ->and($report['reconciliation']['direction'])->toBe('owed_to_employee')
        ->and($report['trip']['id'])->toBe($id)
        ->and($report['reconciliation']['rejected_expenses'])->toBe(1);
});

it('reports the balance owed back by the employee and ignores an advance that was never paid', function (): void {
    trvSeedRates();
    $trip = trvTrip(['advance_amount' => '500.00', 'estimated_budget' => '500.00']);
    $id = $trip['id'];
    trvApprove($id);
    $this->putJson("/api/v1/travel/trips/{$id}/per-diem")->assertOk();

    $unpaid = $this->getJson("/api/v1/travel/trips/{$id}/reconciliation")->json('data');
    expect($unpaid['advance_amount'])->toBe('500.00')->and($unpaid['advance_paid'])->toBe('0.00')->and($unpaid['balance'])->toBe('139.44');

    trvAct($this->admin, $this->tenant);
    $this->postJson("/api/v1/travel/trips/{$id}/advance-paid", ['paid_at' => '2027-06-12'])->assertOk();
    $owed = $this->getJson("/api/v1/travel/trips/{$id}/reconciliation")->json('data');
    expect($owed)->toMatchArray(['balance' => '-360.56', 'balance_amount' => '360.56', 'direction' => 'owed_by_employee']);

    $this->travelTo('2027-06-17 09:00:00');
    trvAct($this->employee, $this->tenant);
    $this->postJson("/api/v1/travel/trips/{$id}/complete")->assertOk();
    trvAct($this->admin, $this->tenant);
    $this->postJson("/api/v1/travel/trips/{$id}/reconcile")->assertOk()->assertJsonPath('data.reconciled_balance', '-360.56')->assertJsonPath('data.reconciled_direction', 'owed_by_employee');
});

it('calculates the per diem at reconciliation when it was not calculated and rates exist', function (): void {
    trvSeedRates();
    $trip = trvTrip();
    trvApprove($trip['id']);
    $this->travelTo('2027-06-17 09:00:00');
    $this->postJson("/api/v1/travel/trips/{$trip['id']}/complete")->assertOk();

    trvAct($this->accountant, $this->tenant);
    $this->postJson("/api/v1/travel/trips/{$trip['id']}/reconcile")->assertOk()
        ->assertJsonPath('data.reconciled_per_diem_total', '139.44')->assertJsonPath('data.reconciled_direction', 'owed_to_employee');
});

it('keeps the rate tables and the trips of another company out of reach', function (): void {
    trvSeedRates();
    $trip = trvTrip();

    $other = trvTenant('Viaggi Neri', 'IT', 'EUR');
    $stranger = trvMember($other, 'owner');
    trvAct($stranger, $other);

    $this->getJson("/api/v1/travel/trips/{$trip['id']}")->assertNotFound();
    $this->postJson("/api/v1/travel/trips/{$trip['id']}/submit")->assertNotFound();
    expect($this->getJson('/api/v1/travel/trips')->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/travel/rates/per-diem')->json('data'))->toBe([]);

    $rateId = TravelPerDiemRate::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->value('id');
    $this->putJson("/api/v1/travel/rates/per-diem/{$rateId}", ['jurisdiction' => 'IT', 'year' => 2027, 'scope' => 'domestic', 'currency' => 'EUR', 'daily_amount' => '1.00'])->assertNotFound();
    expect(TravelTrip::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

it('adds the reference rates only once and deletes a draft trip', function (): void {
    trvAct($this->accountant, $this->tenant);
    $this->postJson('/api/v1/travel/rates/defaults', ['jurisdiction' => 'IT', 'year' => 2027])->assertOk()->assertJsonPath('data.per_diem_created', 2)->assertJsonPath('data.mileage_created', 2);
    $this->postJson('/api/v1/travel/rates/defaults', ['jurisdiction' => 'IT', 'year' => 2027])->assertOk()->assertJsonPath('data.per_diem_created', 0);
    $this->postJson('/api/v1/travel/rates/defaults', ['jurisdiction' => 'DE', 'year' => 2027])->assertUnprocessable()->assertJsonValidationErrors('jurisdiction');
    expect($this->getJson('/api/v1/travel/rates/per-diem?jurisdiction=IT&scope=abroad')->json('data.0.daily_amount'))->toBe('77.47');

    $rateId = $this->getJson('/api/v1/travel/rates/per-diem?scope=domestic')->json('data.0.id');
    $this->deleteJson("/api/v1/travel/rates/per-diem/{$rateId}")->assertNoContent();

    trvAct($this->employee, $this->tenant);
    $trip = trvTrip();
    $this->deleteJson("/api/v1/travel/trips/{$trip['id']}")->assertNoContent();
    $this->getJson("/api/v1/travel/trips/{$trip['id']}")->assertNotFound();
});
