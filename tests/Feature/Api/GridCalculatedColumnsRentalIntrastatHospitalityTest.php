<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\RentalBooking;
use App\Models\RentalVehicle;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Hospitality\Models\Reservation;
use Modules\Intrastat\Enums\IntrastatFlow;
use Modules\Intrastat\Models\IntrastatDeclaration;
use Modules\Intrastat\Models\IntrastatDeclarationLine;

require_once __DIR__.'/../Compliance/ComplianceHelpers.php';

uses(RefreshDatabase::class);

/**
 * @param  Builder<*>  $query
 * @param  array<string, mixed>  $parameters
 */
function rentalGridPage(Builder $query, array $parameters, int $perPage = 10): LengthAwarePaginator
{
    app()->instance('request', Request::create('/grid', 'GET', $parameters));

    return $query->gridPaginate($perPage);
}

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    actingAsMember($this->tenant, 'accountant');
});

test('rental vehicles expose the renter, the return date and the availability status as grid columns', function (): void {
    $acme = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'company_name' => 'Acme Srl', 'type' => 'client']);
    $person = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'company_name' => null, 'first_name' => 'Mario', 'last_name' => 'Rossi', 'type' => 'client']);

    $rented = RentalVehicle::create(['tenant_id' => $this->tenant->id, 'vehicle_id' => Vehicle::factory()->create(['tenant_id' => $this->tenant->id, 'license_plate' => 'TI 111111', 'client_id' => $acme->id])->id, 'category' => 'suv', 'daily_rate' => 80, 'is_available' => false]);
    $free = RentalVehicle::create(['tenant_id' => $this->tenant->id, 'vehicle_id' => Vehicle::factory()->create(['tenant_id' => $this->tenant->id, 'license_plate' => 'TI 222222', 'client_id' => $acme->id])->id, 'category' => 'economy', 'daily_rate' => 40, 'is_available' => true]);

    RentalBooking::create(['tenant_id' => $this->tenant->id, 'rental_vehicle_id' => $rented->id, 'client_id' => $person->id, 'start_date' => '2026-11-01 09:00:00', 'end_date' => '2026-11-05 18:00:00', 'status' => 'active', 'total_estimated' => 320]);
    RentalBooking::create(['tenant_id' => $this->tenant->id, 'rental_vehicle_id' => $free->id, 'client_id' => $acme->id, 'start_date' => '2026-10-01 09:00:00', 'end_date' => '2026-10-03 18:00:00', 'status' => 'completed', 'total_estimated' => 80]);

    $byStatus = rentalGridPage(RentalVehicle::query(), ['filter' => ['rental_status' => ['rented']]]);
    expect($byStatus->total())->toBe(1)
        ->and($byStatus->getCollection()->first()->id)->toBe($rented->id);

    $byClient = rentalGridPage(RentalVehicle::query(), ['filter' => ['current_client' => ['Mario Rossi']]]);
    expect($byClient->total())->toBe(1);

    $emptyClient = rentalGridPage(RentalVehicle::query(), ['filter' => ['current_client' => ['__empty__']]]);
    expect($emptyClient->total())->toBe(1)
        ->and($emptyClient->getCollection()->first()->id)->toBe($free->id);

    $sorted = rentalGridPage(RentalVehicle::query(), ['sort_by' => 'vehicle.license_plate', 'sort_dir' => 'desc']);
    expect($sorted->getCollection()->pluck('id')->all())->toBe([$free->id, $rented->id]);

    $byReturn = rentalGridPage(RentalVehicle::query(), ['sort_by' => 'current_return_date', 'sort_dir' => 'desc']);
    expect($byReturn->getCollection()->first()->id)->toBe($rented->id);

});

test('rental bookings sort and filter by plate, client name and rate through relation paths', function (): void {
    $acme = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'company_name' => 'Acme Srl', 'type' => 'client']);
    $beta = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'company_name' => 'Beta Spa', 'type' => 'client']);
    $vehicle = RentalVehicle::create(['tenant_id' => $this->tenant->id, 'vehicle_id' => Vehicle::factory()->create(['tenant_id' => $this->tenant->id, 'license_plate' => 'TI 333333', 'client_id' => $acme->id])->id, 'category' => 'van', 'daily_rate' => 65, 'is_available' => true]);

    foreach ([[$acme, 100], [$beta, 300]] as [$client, $total]) {
        RentalBooking::create(['tenant_id' => $this->tenant->id, 'rental_vehicle_id' => $vehicle->id, 'client_id' => $client->id, 'start_date' => '2026-11-01 09:00:00', 'end_date' => '2026-11-02 09:00:00', 'status' => 'reserved', 'total_estimated' => $total]);
    }

    $sorted = rentalGridPage(RentalBooking::query(), ['sort_by' => 'client.name', 'sort_dir' => 'desc']);
    expect($sorted->getCollection()->first()->client->company_name)->toBe('Beta Spa');

    expect(rentalGridPage(RentalBooking::query(), ['filter' => ['client.name' => ['Acme Srl']]])->total())->toBe(1)
        ->and(rentalGridPage(RentalBooking::query(), ['filter' => ['rentalVehicle.vehicle.license_plate' => ['TI 333333']]])->total())->toBe(2)
        ->and(rentalGridPage(RentalBooking::query(), ['sort_by' => 'total_estimated', 'sort_dir' => 'asc'])->getCollection()->first()->client->company_name)->toBe('Acme Srl');
});

test('the rental lists answer the grid contract with a flat page and its meta', function (): void {
    $response = $this->getJson('/api/v1/rent/bookings?per_page=5')->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.total'))->toBe(0);
});

test('intrastat declarations expose model and period as grid columns and the list carries the KPI summary', function (): void {
    $sales = IntrastatDeclaration::factory()->create(['tenant_id' => $this->tenant->id, 'flow' => IntrastatFlow::Sales, 'year' => 2027, 'period' => 3, 'reference_number' => 1]);
    $purchases = IntrastatDeclaration::factory()->purchases()->create(['tenant_id' => $this->tenant->id, 'year' => 2027, 'period' => 4, 'reference_number' => 2]);

    IntrastatDeclarationLine::query()->create(['tenant_id' => $this->tenant->id, 'declaration_id' => $sales->id, 'section' => 1, 'line_number' => 1, 'amount_eur' => 1000]);
    IntrastatDeclarationLine::query()->create(['tenant_id' => $this->tenant->id, 'declaration_id' => $purchases->id, 'section' => 2, 'line_number' => 1, 'amount_eur' => 400, 'sign' => '-']);

    $models = collect($this->getJson('/api/v1/intrastat/declarations?grid_values=model')->assertOk()->json('data'))->pluck('value')->sort()->values()->all();
    expect($models)->toBe(['INTRA-1', 'INTRA-2']);

    $periods = collect($this->getJson('/api/v1/intrastat/declarations?grid_values=period_label')->assertOk()->json('data'))->pluck('value')->sort()->values()->all();
    expect($periods)->toBe(['2027 / M3', '2027 / M4']);

    $filtered = $this->getJson('/api/v1/intrastat/declarations?filter[model][]=INTRA-2')->assertOk();
    expect($filtered->json('data.0.id'))->toBe($purchases->id)
        ->and($filtered->json('meta.total'))->toBe(1);

    $sorted = $this->getJson('/api/v1/intrastat/declarations?sort_by=period_label&sort_dir=desc')->assertOk();
    expect($sorted->json('data.0.id'))->toBe($purchases->id);

    $summary = $this->getJson('/api/v1/intrastat/declarations?per_page=1&year=2027')->assertOk()->json('summary');
    expect($summary)->toBe(['sales_total_eur' => 1000, 'purchases_total_eur' => -400, 'draft_count' => 2, 'submitted_count' => 0]);
});

test('hospitality reservations of a day are paged by the grid and expose their duration', function (): void {
    foreach ([['Anna', 60], ['Bruno', 120], ['Carla', 90]] as [$guest, $minutes]) {
        Reservation::factory()->create([
            'tenant_id' => $this->tenant->id, 'guest_name' => $guest, 'party_size' => 2,
            'starts_at' => '2026-11-20 19:00:00', 'ends_at' => now()->parse('2026-11-20 19:00:00')->addMinutes($minutes),
        ]);
    }

    $page = $this->getJson('/api/v1/hospitality/reservations?date=2026-11-20&per_page=2&sort_by=duration_minutes&sort_dir=desc')->assertOk();
    expect(collect($page->json('data'))->pluck('guest_name')->all())->toBe(['Bruno', 'Carla'])
        ->and($page->json('data.0.duration_minutes'))->toBe(120)
        ->and($page->json('meta.total'))->toBe(3);

    $values = collect($this->getJson('/api/v1/hospitality/reservations?date=2026-11-20&grid_values=duration_minutes')->assertOk()->json('data'))->pluck('value')->map(fn ($value): int => (int) $value)->sort()->values()->all();
    expect($values)->toBe([60, 90, 120]);

    $byGuest = $this->getJson('/api/v1/hospitality/reservations?date=2026-11-20&filter_like[guest_name]=ann')->assertOk();
    expect($byGuest->json('meta.total'))->toBe(1);
});
