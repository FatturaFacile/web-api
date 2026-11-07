<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{contact: string, vehicle: string, site: string, case: string}
 */
function verticalRecords(Tenant $tenant): array
{
    $contact = (string) Str::uuid7();
    DB::table('contacts')->insert(['id' => $contact, 'tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Cliente '.$tenant->name, 'created_at' => now(), 'updated_at' => now()]);
    $vehicle = (string) Str::uuid7();
    DB::table('vehicles')->insert(['id' => $vehicle, 'tenant_id' => $tenant->id, 'client_id' => $contact, 'license_plate' => 'TI '.random_int(100000, 999999), 'make' => 'Fiat', 'model' => 'Panda', 'created_at' => now(), 'updated_at' => now()]);
    $site = (string) Str::uuid7();
    DB::table('construction_sites')->insert(['id' => $site, 'tenant_id' => $tenant->id, 'client_id' => $contact, 'code' => 'C-'.Str::random(4), 'name' => 'Cantiere', 'address' => 'Via Roma 1', 'created_at' => now(), 'updated_at' => now()]);
    $case = (string) Str::uuid7();
    DB::table('legal_cases')->insert(['id' => $case, 'tenant_id' => $tenant->id, 'client_id' => $contact, 'case_number' => 'F-'.Str::random(4), 'title' => 'Fascicolo', 'created_at' => now(), 'updated_at' => now()]);

    return ['contact' => $contact, 'vehicle' => $vehicle, 'site' => $site, 'case' => $case];
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->own = verticalRecords($this->tenant);
    $this->foreign = verticalRecords($this->other);

    $user = User::create(['name' => 'Owner', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    Sanctum::actingAs($user);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);
});

test('ids of another company are refused by the tire hotel, SAL, fee note and time log requests', function (): void {
    $tires = ['season' => 'winter', 'brand' => 'Pirelli', 'size' => '205/55 R16', 'front_left_tread_mm' => 6, 'front_right_tread_mm' => 6, 'rear_left_tread_mm' => 6, 'rear_right_tread_mm' => 6, 'warehouse_shelf_location' => 'A1'];
    $sal = ['sal_number' => 'SAL-01', 'period_start' => '2027-01-01', 'period_end' => '2027-01-31', 'gross_certified_amount' => 1000];
    $fee = ['note_number' => 'P-1', 'date' => '2027-01-31', 'taxable_fees' => 1000];
    $log = ['date' => '2027-01-31', 'duration_minutes' => 60, 'hourly_rate' => 100, 'description' => 'Studio'];

    $this->postJson('/api/v1/automotive/tire-hotel/check-in', [...$tires, 'vehicle_id' => $this->foreign['vehicle']])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
    $this->postJson('/api/v1/construction/sal', [...$sal, 'construction_site_id' => $this->foreign['site']])->assertUnprocessable()->assertJsonValidationErrors('construction_site_id');
    $this->postJson('/api/v1/professionals/fee-notes', [...$fee, 'client_id' => $this->foreign['contact'], 'legal_case_id' => $this->foreign['case']])->assertUnprocessable()->assertJsonValidationErrors(['client_id', 'legal_case_id']);
    $this->postJson('/api/v1/professionals/time-logs', [...$log, 'legal_case_id' => $this->foreign['case']])->assertUnprocessable()->assertJsonValidationErrors('legal_case_id');

    $this->postJson('/api/v1/construction/sal', [...$sal, 'construction_site_id' => $this->own['site']])->assertCreated();
    $this->postJson('/api/v1/professionals/fee-notes', [...$fee, 'client_id' => $this->own['contact'], 'legal_case_id' => $this->own['case']])->assertCreated();
});

test('a SAL computes retention and net payable to the cent', function (): void {
    $data = $this->postJson('/api/v1/construction/sal', [
        'construction_site_id' => $this->own['site'], 'sal_number' => 'SAL-02', 'period_start' => '2027-01-01', 'period_end' => '2027-01-31',
        'gross_certified_amount' => '1234.56', 'retention_percentage' => '7.5',
    ])->assertCreated()->json('data');

    expect($data['retention'])->toBe(92.59)->and($data['net_payable'])->toBe(1141.97);

    $stored = DB::table('progress_billings')->where('id', $data['id'])->first();
    expect((string) $stored->retention_amount)->toBe('92.59')->and((string) $stored->net_payable_amount)->toBe('1141.97');
});

test('a fee note adds the fund and VAT and withholds on the fees, to the cent', function (): void {
    $data = $this->postJson('/api/v1/professionals/fee-notes', [
        'client_id' => $this->own['contact'], 'note_number' => 'P-2', 'date' => '2027-01-31', 'taxable_fees' => '1000.10',
    ])->assertCreated()->json('data');

    expect($data)->toMatchArray(['cassa_previdenza' => 40.0, 'vat' => 228.82, 'ritenuta_acconto' => 200.02, 'net_payable' => 1068.9]);

    $stored = DB::table('fee_notes')->where('id', $data['id'])->first();
    expect((string) $stored->net_payable)->toBe('1068.90');
});

test('billable time divides only once, so the fee keeps its cents', function (): void {
    $fee = $this->postJson('/api/v1/professionals/time-logs', [
        'legal_case_id' => $this->own['case'], 'date' => '2027-01-31', 'duration_minutes' => 7, 'hourly_rate' => '150.00', 'description' => 'Telefonata',
    ])->assertCreated()->json('data.fee_amount');

    expect($fee)->toBe(17.5);
});
