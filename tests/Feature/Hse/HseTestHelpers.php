<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Hse\Domain\Enums\CertificateType;
use Modules\Hse\Domain\Enums\FitnessOutcome;
use Modules\Hse\Domain\Enums\Jurisdiction;
use Modules\Hse\Domain\Enums\VisitType;
use Modules\Hse\Infrastructure\Models\EmployeeSafetyCertificate;
use Modules\Hse\Infrastructure\Models\OccupationalMedicalVisit;
use Modules\Hse\Infrastructure\Models\SafetyRoleRequirementMatrix;
use Modules\HumanResources\Models\Employee;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseAisle;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Models\WarehouseRack;
use Modules\Inventory\Models\WarehouseZone;

/**
 * Builders of the HSE tests. The tenant context is set for the model layer; hseActAs also authenticates an HTTP caller.
 */
function hseTenant(string $country = 'CH'): Tenant
{
    $tenant = Tenant::create([
        'name' => 'Hse '.Str::random(5),
        'legal_name' => 'Hse Sagl',
        'vat_number' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'fiscal_code' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'uid_number' => 'CHE-123.456.789',
        'country' => $country,
        'default_currency' => $country === 'CH' ? 'CHF' : 'EUR',
    ]);

    app(TenantContext::class)->setTenant($tenant);

    return $tenant;
}

/**
 * @param  list<string>  $permissions
 */
function hseActAs(Tenant $tenant, string $role = 'owner', array $permissions = []): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode($permissions)]);

    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);

    return $user;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function hseEmployee(Tenant $tenant, array $attributes = []): Employee
{
    return Employee::factory()->create(['tenant_id' => $tenant->id, 'employment_status' => 'active', 'termination_date' => null, ...$attributes]);
}

function hseMatrix(Tenant $tenant, array $attributes = []): SafetyRoleRequirementMatrix
{
    return SafetyRoleRequirementMatrix::create([
        'tenant_id' => $tenant->id, 'name' => 'Rule', 'jurisdiction' => Jurisdiction::forCountry($tenant->country),
        'job_position_id' => (string) Str::uuid(), 'required_training_types_json' => [], 'required_medical_exam_codes_json' => [],
        'required_ppe_product_ids_json' => [], ...$attributes,
    ]);
}

function hseCertificate(Tenant $tenant, Employee $employee, CertificateType $type, string $issuedAt, ?string $expiresAt): EmployeeSafetyCertificate
{
    return EmployeeSafetyCertificate::create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'certificate_type' => $type,
        'issued_at' => $issuedAt, 'expires_at' => $expiresAt, 'status' => 'valid',
    ]);
}

function hseMedicalVisit(Tenant $tenant, Employee $employee, FitnessOutcome $outcome, string $visitDate, ?string $nextDue = null, ?string $restrictions = null): OccupationalMedicalVisit
{
    return OccupationalMedicalVisit::create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'doctor_name' => 'Dr. Medico', 'visit_type' => VisitType::Periodic,
        'visit_date' => $visitDate, 'next_visit_due_date' => $nextDue, 'fitness_outcome' => $outcome, 'restrictions_summary' => $restrictions,
    ]);
}

/**
 * A PPE product with stock in a bin of a fresh warehouse.
 *
 * @return array{product_id: string, warehouse_id: string, stock_item: StockItem}
 */
function hsePpeStock(Tenant $tenant, float $quantity): array
{
    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId, 'tenant_id' => $tenant->id, 'sku' => 'PPE-'.Str::random(6), 'name' => 'Safety shoes S3', 'unit_measure' => 'PZ',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $warehouse = Warehouse::factory()->create(['tenant_id' => $tenant->id]);
    $zone = WarehouseZone::factory()->create(['tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id]);
    $aisle = WarehouseAisle::factory()->create(['tenant_id' => $tenant->id, 'warehouse_zone_id' => $zone->id]);
    $rack = WarehouseRack::factory()->create(['tenant_id' => $tenant->id, 'warehouse_aisle_id' => $aisle->id]);
    $bin = WarehouseBin::factory()->create(['tenant_id' => $tenant->id, 'warehouse_rack_id' => $rack->id]);
    $item = StockItem::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $productId, 'standard_cost' => 40.0]);
    $lot = StockLot::create(['tenant_id' => $tenant->id, 'stock_item_id' => $item->id, 'lot_number' => 'LOT-2027-01']);
    StockQuant::create(['tenant_id' => $tenant->id, 'warehouse_bin_id' => $bin->id, 'stock_item_id' => $item->id, 'lot_id' => $lot->id, 'quantity_on_hand' => $quantity, 'quantity_reserved' => 0]);

    return ['product_id' => $productId, 'warehouse_id' => (string) $warehouse->id, 'stock_item' => $item];
}
