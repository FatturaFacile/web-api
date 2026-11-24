<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');

    $this->tenant = Tenant::create(['name' => 'Hold Exposure SRL', 'legal_name' => 'Hold Exposure SRL', 'vat_number' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2026);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->admin = holdExposureUser('admin');
    Sanctum::actingAs($this->admin);

    $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Credit Customer SpA', 'country' => 'IT']);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'HLD-1', 'name' => 'Widget', 'cost_price' => 40, 'sale_price' => 100, 'tax_rate' => 22, 'track_inventory' => false]);
});

function holdExposureUser(string $role, ?Tenant $tenant = null): User
{
    $tenant ??= test()->tenant;
    $user = User::create(['name' => ucfirst($role), 'email' => $role.'-'.Str::random(6).'@hold.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function holdExposureApi(?Tenant $tenant = null): mixed
{
    return test()->withHeader('X-Tenant-Id', ($tenant ?? test()->tenant)->id);
}

/**
 * Creates a draft order and confirms it over a tight credit limit, so it lands on credit hold.
 *
 * @return array<string, mixed> the order as answered by the confirm endpoint
 */
function holdExposureHeldOrder(): array
{
    holdExposureApi()->postJson('/api/v1/invoicing/customer-credit/limit', ['customer_id' => test()->contact->id, 'credit_limit' => 1000])->assertOk();

    $draft = holdExposureApi()->postJson('/api/v1/sales/orders', [
        'contact_id' => test()->contact->id, 'date' => '2026-03-15', 'currency' => 'EUR', 'status' => 'draft',
        'items' => [['product_id' => test()->product->id, 'description' => 'Widget', 'quantity' => 15, 'unit_price' => 100, 'tax_rate' => 22]],
    ])->assertCreated();

    expect($draft->json('data.credit_hold_id'))->toBeNull()->and($draft->json('data.holds'))->toBe([]);

    return holdExposureApi()->postJson('/api/v1/sales/orders/'.$draft->json('data.id').'/confirm')->assertOk()->json('data');
}

test('an order on credit hold exposes the active hold in list, show and confirm, and the id releases it', function (): void {
    $held = holdExposureHeldOrder();
    $holdId = DB::table('sales_order_holds')->where('sales_order_id', $held['id'])->value('id');

    expect($held['status'])->toBe('on_hold')
        ->and($held['credit_hold_id'])->toBe($holdId)
        ->and($held['holds'])->toHaveCount(1)
        ->and(array_keys($held['holds'][0]))->toBe(['id', 'status', 'reason', 'created_at'])
        ->and($held['holds'][0]['id'])->toBe($holdId)
        ->and($held['holds'][0]['status'])->toBe('active')
        ->and($held['holds'][0]['reason'])->toContain('credit limit');

    $show = holdExposureApi()->getJson('/api/v1/sales/orders/'.$held['id'])->assertOk();
    expect($show->json('data.credit_hold_id'))->toBe($holdId)->and($show->json('data.holds.0.id'))->toBe($holdId);

    $row = collect(holdExposureApi()->getJson('/api/v1/sales/orders')->assertOk()->json('data'))->firstWhere('id', $held['id']);
    expect($row['credit_hold_id'])->toBe($holdId)->and($row['holds'])->toHaveCount(1);

    Sanctum::actingAs(holdExposureUser('sales'));
    holdExposureApi()->postJson("/api/v1/invoicing/customer-credit/holds/{$holdId}/release", ['notes' => 'self'])->assertForbidden();

    Sanctum::actingAs(holdExposureUser('accountant'));
    holdExposureApi()->postJson("/api/v1/invoicing/customer-credit/holds/{$holdId}/release", ['notes' => 'Approved'])->assertOk()->assertJsonPath('hold_id', $holdId);

    $released = holdExposureApi()->getJson('/api/v1/sales/orders/'.$held['id'])->assertOk();
    expect($released->json('data.status'))->toBe('confirmed')
        ->and($released->json('data.credit_hold_id'))->toBeNull()
        ->and($released->json('data.holds'))->toBe([]);

    holdExposureApi()->postJson("/api/v1/invoicing/customer-credit/holds/{$holdId}/release")->assertUnprocessable();
});

test('only the credit manager roles can release a hold', function (): void {
    foreach (['owner', 'admin', 'accountant', 'sales_manager', 'sales', 'viewer'] as $role) {
        DB::table('sales_order_holds')->delete();
        Sanctum::actingAs($this->admin);
        $held = holdExposureHeldOrder();

        Sanctum::actingAs(holdExposureUser($role));
        $response = holdExposureApi()->postJson("/api/v1/invoicing/customer-credit/holds/{$held['credit_hold_id']}/release", ['notes' => 'check']);

        if (in_array($role, ['owner', 'admin', 'accountant', 'sales_manager'], true)) {
            $response->assertOk();
        } else {
            $response->assertForbidden();
        }
    }
});

test('the holds of another company are neither listed nor releasable', function (): void {
    $held = holdExposureHeldOrder();

    $other = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    Sanctum::actingAs(holdExposureUser('admin', $other));

    holdExposureApi($other)->getJson('/api/v1/sales/orders/'.$held['id'])->assertNotFound();
    expect(holdExposureApi($other)->getJson('/api/v1/sales/orders')->assertOk()->json('data'))->toBe([]);
    holdExposureApi($other)->postJson("/api/v1/invoicing/customer-credit/holds/{$held['credit_hold_id']}/release")->assertNotFound();
});

test('releasing an unknown hold is a 404 and invalid notes are a 422', function (): void {
    $held = holdExposureHeldOrder();

    holdExposureApi()->postJson('/api/v1/invoicing/customer-credit/holds/'.Str::uuid().'/release')->assertNotFound();
    holdExposureApi()->postJson("/api/v1/invoicing/customer-credit/holds/{$held['credit_hold_id']}/release", ['notes' => ['not', 'a', 'string']])->assertUnprocessable();
});
