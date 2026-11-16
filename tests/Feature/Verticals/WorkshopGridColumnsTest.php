<?php

declare(strict_types=1);

use App\Models\TireHotelRecord;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workshop\Models\VehicleSale;
use Modules\Workshop\Models\VehicleStockUnit;
use Modules\Workshop\Models\WorkshopFolder;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-07 10:00:00');
    $this->tenant = verticalTenant();
    $this->customer = verticalContact($this->tenant, 'Garage Rossi SA');
    $this->vehicle = Vehicle::factory()->create(['tenant_id' => $this->tenant->id, 'client_id' => $this->customer->id, 'license_plate' => 'TI 111111', 'make' => 'Fiat', 'model' => 'Panda']);
    $this->otherVehicle = Vehicle::factory()->create(['tenant_id' => $this->tenant->id, 'client_id' => $this->customer->id, 'license_plate' => 'TI 222222', 'make' => 'Audi', 'model' => 'A3']);
    $this->user = verticalMember($this->tenant, 'admin');
    verticalApi($this->user, $this->tenant);
});

test('the folder grid exposes the plate, vehicle and customer as calculated columns', function (): void {
    WorkshopFolder::factory()->create(['tenant_id' => $this->tenant->id, 'vehicle_id' => $this->vehicle->id, 'contact_id' => $this->customer->id]);
    WorkshopFolder::factory()->count(2)->create(['tenant_id' => $this->tenant->id, 'vehicle_id' => $this->otherVehicle->id, 'contact_id' => $this->customer->id]);

    $list = $this->getJson('/api/v1/workshop/folders?sort_by=license_plate&sort_dir=desc')->assertOk();
    expect($list->json('data.0.license_plate'))->toBe('TI 222222')
        ->and($list->json('data.0.vehicle_model'))->toBe('Audi A3')
        ->and($list->json('data.0.customer_name'))->toBe('Garage Rossi SA');

    $values = collect($this->getJson('/api/v1/workshop/folders?grid_values=license_plate')->assertOk()->json('data'))->pluck('count', 'value')->all();
    expect($values)->toBe(['TI 111111' => 1, 'TI 222222' => 2]);

    expect($this->getJson('/api/v1/workshop/folders?filter[license_plate][]=TI%20111111')->assertOk()->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/workshop/folders?filter_like[customer_name]=rossi')->assertOk()->json('meta.total'))->toBe(3)
        ->and($this->getJson('/api/v1/workshop/folders?grid_values=vehicle_model')->assertOk()->json('data.0.value'))->toBe('Audi A3')
        ->and($this->getJson('/api/v1/workshop/folders?search=222222')->assertOk()->json('meta.total'))->toBe(2);
});

test('the stock grid exposes the sale date and the buyer of a sold vehicle', function (): void {
    VehicleStockUnit::factory()->create(['tenant_id' => $this->tenant->id, 'stock_number' => 'VS-1']);
    $sold = VehicleStockUnit::factory()->create(['tenant_id' => $this->tenant->id, 'stock_number' => 'VS-2', 'status' => 'sold']);
    VehicleSale::factory()->create(['tenant_id' => $this->tenant->id, 'stock_id' => $sold->id, 'buyer_contact_id' => $this->customer->id, 'vehicle_id' => $this->vehicle->id, 'sale_date' => '2026-10-01']);

    $list = $this->getJson('/api/v1/workshop/vehicle-stock?sort_by=buyer_name&sort_dir=desc')->assertOk();
    expect($list->json('data.0.stock_number'))->toBe('VS-2')
        ->and($list->json('data.0.buyer_name'))->toBe('Garage Rossi SA')
        ->and($list->json('data.0.sold_at'))->toBe('2026-10-01')
        ->and($list->json('data.1.buyer_name'))->toBeNull();

    expect($this->getJson('/api/v1/workshop/vehicle-stock?filter[buyer_name][]=Garage%20Rossi%20SA')->assertOk()->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/workshop/vehicle-stock?grid_values=sold_at')->assertOk()->json('data'))->toContain(['value' => '2026-10-01', 'count' => 1]);
});

test('the recurring service grid takes name, price and activity from the billing subscription', function (): void {
    verticalLedger($this->tenant);
    verticalLedger($this->tenant, 2027);
    verticalApi($this->user, $this->tenant);

    $ids = [];
    foreach ([['Deposito gomme', '90'], ['Tagliando', '250']] as [$name, $price]) {
        $ids[$name] = $this->postJson('/api/v1/workshop/recurring-services', [
            'vehicle_id' => $this->vehicle->id, 'service_type' => 'maintenance_plan', 'name' => $name,
            'interval' => 'yearly', 'start_date' => '2026-11-07', 'currency' => 'CHF', 'unit_price' => $price, 'tax_rate' => '8.1',
        ])->assertCreated()->json('data.id');
    }
    $this->postJson("/api/v1/workshop/recurring-services/{$ids['Tagliando']}/stop")->assertOk();

    $list = $this->getJson('/api/v1/workshop/recurring-services?sort_by=unit_price&sort_dir=desc')->assertOk();
    expect($list->json('data.0.name'))->toBe('Tagliando')
        ->and($list->json('data.0.unit_price'))->toBe('250.00')
        ->and($list->json('data.0.interval'))->toBe('yearly')
        ->and($list->json('data.0.license_plate'))->toBe('TI 111111')
        ->and($list->json('data.0.is_active'))->toBeFalse()
        ->and($list->json('data.1.is_active'))->toBeTrue();

    $active = collect($this->getJson('/api/v1/workshop/recurring-services?grid_values=is_active')->assertOk()->json('data'))->pluck('count', 'value')->all();
    expect($active)->toBe([0 => 1, 1 => 1])
        ->and($this->getJson('/api/v1/workshop/recurring-services?filter[is_active][]=1')->assertOk()->json('data.0.name'))->toBe('Deposito gomme')
        ->and($this->getJson('/api/v1/workshop/recurring-services?filter_like[name]=tagl')->assertOk()->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/workshop/recurring-services?grid_values=start_date')->assertOk()->json('data'))->toBe([['value' => '2026-11-07', 'count' => 2]]);
});

test('the seasonal switch plan is a grid list with the vehicle plate', function (): void {
    foreach ([[$this->vehicle, 'winter'], [$this->otherVehicle, 'summer'], [$this->otherVehicle, 'summer']] as [$vehicle, $season]) {
        TireHotelRecord::create([
            'tenant_id' => $this->tenant->id, 'vehicle_id' => $vehicle->id, 'season' => $season, 'brand' => 'Pirelli', 'size' => '205/55 R16',
            'front_left_tread_mm' => 6, 'front_right_tread_mm' => 6, 'rear_left_tread_mm' => 6, 'rear_right_tread_mm' => 6, 'status' => 'stored',
        ]);
    }

    $page = $this->getJson('/api/v1/automotive/tire-hotel/seasonal-switch-plan?per_page=2&sort_by=vehicle.license_plate&sort_dir=desc')->assertOk();
    expect($page->json('data.total'))->toBe(3)
        ->and($page->json('data.data'))->toHaveCount(2)
        ->and($page->json('data.data.0.license_plate'))->toBe('TI 222222')
        ->and($page->json('data.data.0.vehicle_model'))->toBe('A3');

    $seasons = collect($this->getJson('/api/v1/automotive/tire-hotel/seasonal-switch-plan?grid_values=season')->assertOk()->json('data'))->pluck('count', 'value')->all();
    expect($seasons)->toBe(['summer' => 2, 'winter' => 1])
        ->and($this->getJson('/api/v1/automotive/tire-hotel/seasonal-switch-plan?filter[vehicle.license_plate][]=TI%20111111')->assertOk()->json('data.total'))->toBe(1);
});
