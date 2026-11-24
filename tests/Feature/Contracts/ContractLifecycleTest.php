<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Contracts\Models\ContractObligation;
use Modules\Contracts\Models\ContractStatusChange;
use Modules\Contracts\Services\ContractDeadlineService;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierContract;
use Modules\ServiceManagement\Models\ServiceContract;

uses(RefreshDatabase::class);

function ctlMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function ctlActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ctlCustomer(array $overrides = []): array
{
    return ['kind' => 'customer', 'counterparty_id' => test()->customer->id, 'contract_number' => 'C-'.Str::random(6), 'title' => 'Maintenance', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'contract_value' => '1200.00', 'status' => 'draft', 'notice_period_days' => 60, 'renewal_term_months' => 12, ...$overrides];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ctlSupplier(array $overrides = []): array
{
    return ['kind' => 'supplier', 'counterparty_id' => test()->supplier->id, 'contract_number' => 'S-'.Str::random(6), 'title' => 'Framework', 'start_date' => '2026-01-01', 'end_date' => '2027-06-30', 'contract_value' => '5000.00', 'status' => 'draft', ...$overrides];
}

function ctlContract(array $payload = []): string
{
    return test()->postJson('/api/v1/contract-register', ctlCustomer($payload))->assertCreated()->json('data.id');
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.private_disk' => 'local']);
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->customer = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->manager = ctlMember($this->tenant, 'accountant');
    ctlActAs($this->manager, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a contract moves from draft through review to active and every step is kept in its history', function (): void {
    $id = ctlContract();

    $this->getJson("/api/v1/contract-register/customer/{$id}")->assertOk()->assertJsonPath('data.lifecycle_status', 'draft')->assertJsonPath('data.allowed_transitions', ['review', 'active']);
    $this->postJson("/api/v1/contract-register/customer/{$id}/transition", ['status' => 'review', 'reason' => 'Sent to legal'])->assertOk()->assertJsonPath('data.status', 'review');
    $this->postJson("/api/v1/contract-register/customer/{$id}/transition", ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.allowed_transitions', ['renewal', 'expired', 'terminated']);

    $history = $this->getJson("/api/v1/contract-register/customer/{$id}/history")->assertOk()->json('data');

    expect($history)->toHaveCount(2)
        ->and($history[0])->toMatchArray(['from_status' => 'review', 'to_status' => 'active', 'changed_by' => $this->manager->id])
        ->and($history[1])->toMatchArray(['from_status' => 'draft', 'to_status' => 'review', 'reason' => 'Sent to legal']);
});

test('moves the lifecycle does not allow are refused', function (): void {
    $id = ctlContract();

    $this->postJson("/api/v1/contract-register/customer/{$id}/transition", ['status' => 'renewal'])->assertConflict();
    $this->postJson("/api/v1/contract-register/customer/{$id}/transition", ['status' => 'active'])->assertOk();
    $this->postJson("/api/v1/contract-register/customer/{$id}/transition", ['status' => 'draft'])->assertConflict();
    $this->postJson("/api/v1/contract-register/customer/{$id}/transition", ['status' => 'renewal'])->assertOk();
    $this->postJson("/api/v1/contract-register/customer/{$id}/transition", ['status' => 'invented'])->assertUnprocessable()->assertJsonValidationErrors(['status']);

    expect(ContractStatusChange::query()->count())->toBe(2);
});

test('terminating needs a reason and the right role, and is final', function (): void {
    $id = ctlContract(['status' => 'active']);

    $this->postJson("/api/v1/contract-register/customer/{$id}/transition", ['status' => 'terminated'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);

    ctlActAs(ctlMember($this->tenant, 'procurement_manager'), $this->tenant);
    $this->postJson("/api/v1/contract-register/customer/{$id}/transition", ['status' => 'terminated', 'reason' => 'Breach'])->assertForbidden();
    expect(ServiceContract::query()->findOrFail($id)->status)->toBe('active');

    ctlActAs(ctlMember($this->tenant, 'sales_manager'), $this->tenant);
    $this->postJson("/api/v1/contract-register/customer/{$id}/transition", ['status' => 'terminated', 'reason' => 'Breach'])->assertOk()->assertJsonPath('data.allowed_transitions', []);
    $this->postJson("/api/v1/contract-register/customer/{$id}/transition", ['status' => 'active'])->assertConflict();
    $this->postJson("/api/v1/contract-register/customer/{$id}/obligations", ['title' => 'Late', 'type' => 'obligation', 'due_date' => '2027-01-01'])->assertConflict();
});

test('a contract locked by a workflow, past its end, or outside the lifecycle is not moved from here', function (): void {
    $locked = ctlContract();
    ServiceContract::query()->whereKey($locked)->update(['is_locked' => true]);
    $this->postJson("/api/v1/contract-register/customer/{$locked}/transition", ['status' => 'review'])->assertConflict();

    $ended = ctlContract(['start_date' => '2019-01-01', 'end_date' => '2019-12-31']);
    $this->postJson("/api/v1/contract-register/customer/{$ended}/transition", ['status' => 'active'])->assertConflict();

    $suspended = ctlContract(['status' => 'active']);
    ServiceContract::query()->whereKey($suspended)->update(['status' => 'suspended']);
    $this->getJson("/api/v1/contract-register/customer/{$suspended}")->assertOk()->assertJsonPath('data.lifecycle_status', null);
    $this->postJson("/api/v1/contract-register/customer/{$suspended}/transition", ['status' => 'terminated', 'reason' => 'x'])->assertConflict();
});

test('the spellings used before the register read as lifecycle statuses', function (): void {
    $id = $this->postJson('/api/v1/contract-register', ctlSupplier())->assertCreated()->json('data.id');
    SupplierContract::query()->whereKey($id)->update(['status' => 'pending_approval']);

    $this->getJson("/api/v1/contract-register/supplier/{$id}")->assertOk()->assertJsonPath('data.lifecycle_status', 'review');
    $this->postJson("/api/v1/contract-register/supplier/{$id}/transition", ['status' => 'active'])->assertOk();

    SupplierContract::query()->whereKey($id)->update(['status' => 'cancelled']);
    $this->getJson("/api/v1/contract-register/supplier/{$id}")->assertOk()->assertJsonPath('data.lifecycle_status', 'terminated');
});

test('a contract that ended without renewal expires by itself, and a lapsed one can be renewed back to active', function (): void {
    $lapses = ctlContract(['status' => 'active', 'auto_renew' => false]);
    $renewing = ctlContract(['status' => 'active', 'auto_renew' => false]);
    $this->postJson("/api/v1/contract-register/customer/{$renewing}/transition", ['status' => 'renewal'])->assertOk();
    $terminated = ctlContract(['status' => 'active']);
    $this->postJson("/api/v1/contract-register/customer/{$terminated}/transition", ['status' => 'terminated', 'reason' => 'x'])->assertOk();

    $result = app(ContractDeadlineService::class)->run((string) $this->tenant->id, CarbonImmutable::parse('2027-01-02'));

    expect($result['expired'])->toBe(2)
        ->and(ServiceContract::query()->findOrFail($lapses)->status)->toBe('expired')
        ->and(ServiceContract::query()->findOrFail($terminated)->status)->toBe('terminated');

    $change = ContractStatusChange::query()->where('contract_id', $lapses)->latest()->firstOrFail();
    expect($change->changed_by)->toBeNull()->and($change->to_status)->toBe('expired');

    // A lapsed contract can still be renewed, which puts it back in force with a new end date and a history entry.
    $this->postJson("/api/v1/contract-register/customer/{$lapses}/renew")->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.end_date', '2027-12-31');
    expect(ContractStatusChange::query()->where('contract_id', $lapses)->where('reason', 'like', 'Renewed until 2027-12-31')->where('to_status', 'active')->exists())->toBeTrue();
});

test('draft, review and terminated contracts cannot be renewed', function (): void {
    $draft = ctlContract();

    $this->postJson("/api/v1/contract-register/customer/{$draft}/renew")->assertConflict();
});

test('obligations and milestones are added, listed by deadline and closed once', function (): void {
    $id = ctlContract(['status' => 'active']);
    $owner = ctlMember($this->tenant, 'sales_manager');

    $obligation = $this->postJson("/api/v1/contract-register/customer/{$id}/obligations", ['title' => 'Send the annual report', 'type' => 'obligation', 'due_date' => '2026-12-01', 'owner_user_id' => $owner->id, 'responsible_party' => 'counterparty'])->assertCreated()->assertJsonPath('data.remind_days_before', 7)->json('data.id');
    $milestone = $this->postJson("/api/v1/contract-register/customer/{$id}/obligations", ['title' => 'First payment', 'type' => 'milestone', 'due_date' => '2026-11-20', 'amount' => '5000.00', 'currency' => 'EUR'])->assertCreated()->assertJsonPath('data.amount', '5000.00')->json('data.id');

    $list = $this->getJson("/api/v1/contract-register/customer/{$id}/obligations")->assertOk()->json('data');
    expect(collect($list)->pluck('id')->all())->toBe([$milestone, $obligation]);

    $this->postJson("/api/v1/contract-register/obligations/{$milestone}/complete", ['note' => 'Paid'])->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completion_note', 'Paid');
    $this->postJson("/api/v1/contract-register/obligations/{$milestone}/complete")->assertConflict();
    $this->postJson("/api/v1/contract-register/obligations/{$milestone}/waive")->assertConflict();
    $this->putJson("/api/v1/contract-register/obligations/{$milestone}", ['title' => 'First payment', 'type' => 'milestone', 'due_date' => '2026-12-20'])->assertConflict();
    $this->deleteJson("/api/v1/contract-register/obligations/{$milestone}")->assertConflict();

    $this->postJson("/api/v1/contract-register/obligations/{$obligation}/waive")->assertOk()->assertJsonPath('data.status', 'waived');
    expect($this->getJson('/api/v1/contract-register/obligations?status=open')->json('data'))->toBe([]);
});

test('obligations are filtered by due window and by overdue', function (): void {
    $id = ctlContract(['status' => 'active']);
    ContractObligation::factory()->create(['tenant_id' => $this->tenant->id, 'contract_id' => $id, 'title' => 'Late', 'due_date' => now()->subDays(3)->toDateString()]);
    ContractObligation::factory()->create(['tenant_id' => $this->tenant->id, 'contract_id' => $id, 'title' => 'Soon', 'due_date' => now()->addDays(5)->toDateString()]);
    ContractObligation::factory()->milestone()->create(['tenant_id' => $this->tenant->id, 'contract_id' => $id, 'title' => 'Far', 'due_date' => now()->addDays(90)->toDateString()]);

    expect(collect($this->getJson('/api/v1/contract-register/obligations?overdue=1')->json('data'))->pluck('title')->all())->toBe(['Late'])
        ->and(collect($this->getJson('/api/v1/contract-register/obligations?due_within_days=10')->json('data'))->pluck('title')->all())->toBe(['Soon'])
        ->and(collect($this->getJson('/api/v1/contract-register/obligations?type=milestone')->json('data'))->pluck('title')->all())->toBe(['Far'])
        ->and($this->getJson('/api/v1/contract-register/obligations?overdue=1')->json('data.0.is_overdue'))->toBeTrue();

    $this->getJson('/api/v1/contract-register/obligations?type=invented')->assertUnprocessable()->assertJsonValidationErrors(['type']);
});

test('the daily job reminds each obligation once, when its window opens', function (): void {
    $id = ctlContract(['status' => 'active']);
    $due = ContractObligation::factory()->create(['tenant_id' => $this->tenant->id, 'contract_id' => $id, 'due_date' => '2027-01-05', 'remind_days_before' => 7]);
    $far = ContractObligation::factory()->create(['tenant_id' => $this->tenant->id, 'contract_id' => $id, 'due_date' => '2027-03-01', 'remind_days_before' => 7]);
    $done = ContractObligation::factory()->create(['tenant_id' => $this->tenant->id, 'contract_id' => $id, 'due_date' => '2027-01-05', 'status' => 'completed']);
    $service = app(ContractDeadlineService::class);

    expect($service->run((string) $this->tenant->id, CarbonImmutable::parse('2026-12-20'))['obligation_reminders'])->toBe(0)
        ->and($service->run((string) $this->tenant->id, CarbonImmutable::parse('2026-12-30'))['obligation_reminders'])->toBe(1)
        ->and($service->run((string) $this->tenant->id, CarbonImmutable::parse('2027-01-02'))['obligation_reminders'])->toBe(0)
        ->and($due->refresh()->reminded_at)->not->toBeNull()
        ->and($far->refresh()->reminded_at)->toBeNull()
        ->and($done->refresh()->reminded_at)->toBeNull();

    // Moving the due date re-arms the reminder.
    $this->putJson("/api/v1/contract-register/obligations/{$due->id}", ['title' => 'Moved', 'type' => 'obligation', 'due_date' => '2027-02-01'])->assertOk();
    expect($due->refresh()->reminded_at)->toBeNull();
});

test('obligation input is validated and tied to the company', function (): void {
    $id = ctlContract(['status' => 'active']);
    $stranger = ctlMember($this->other, 'admin');
    $valid = ['title' => 'Report', 'type' => 'obligation', 'due_date' => '2026-12-01'];

    $this->postJson("/api/v1/contract-register/customer/{$id}/obligations", [])->assertUnprocessable()->assertJsonValidationErrors(['title', 'type', 'due_date']);
    $this->postJson("/api/v1/contract-register/customer/{$id}/obligations", [...$valid, 'owner_user_id' => $stranger->id])->assertUnprocessable()->assertJsonValidationErrors(['owner_user_id']);
    $this->postJson("/api/v1/contract-register/customer/{$id}/obligations", [...$valid, 'amount' => '10.00'])->assertUnprocessable()->assertJsonValidationErrors(['currency']);
    $this->postJson("/api/v1/contract-register/customer/{$id}/obligations", [...$valid, 'remind_days_before' => 1000])->assertUnprocessable()->assertJsonValidationErrors(['remind_days_before']);
    $this->postJson("/api/v1/contract-register/customer/{$id}/obligations", [...$valid, 'due_date' => '01/12/2026'])->assertUnprocessable()->assertJsonValidationErrors(['due_date']);
});

test('obligations, parties, documents and history of another company are not found', function (): void {
    $id = ctlContract(['status' => 'active']);
    $obligation = $this->postJson("/api/v1/contract-register/customer/{$id}/obligations", ['title' => 'Report', 'type' => 'obligation', 'due_date' => '2026-12-01'])->json('data.id');

    ctlActAs(ctlMember($this->other, 'admin'), $this->other);

    $this->getJson("/api/v1/contract-register/customer/{$id}/obligations")->assertNotFound();
    $this->postJson("/api/v1/contract-register/customer/{$id}/obligations", ['title' => 'x', 'type' => 'obligation', 'due_date' => '2026-12-01'])->assertNotFound();
    $this->postJson("/api/v1/contract-register/obligations/{$obligation}/complete")->assertNotFound();
    $this->putJson("/api/v1/contract-register/obligations/{$obligation}", ['title' => 'x', 'type' => 'obligation', 'due_date' => '2026-12-01'])->assertNotFound();
    $this->deleteJson("/api/v1/contract-register/obligations/{$obligation}")->assertNotFound();
    $this->getJson("/api/v1/contract-register/customer/{$id}/parties")->assertNotFound();
    $this->getJson("/api/v1/contract-register/customer/{$id}/documents")->assertNotFound();
    $this->getJson("/api/v1/contract-register/customer/{$id}/history")->assertNotFound();
    $this->postJson("/api/v1/contract-register/customer/{$id}/transition", ['status' => 'renewal'])->assertNotFound();
    expect($this->getJson('/api/v1/contract-register/obligations')->json('data'))->toBe([]);
});

test('lifecycle, obligations, parties and documents are closed to roles outside the contract managers', function (): void {
    $id = ctlContract(['status' => 'active']);
    ctlActAs(ctlMember($this->tenant, 'member'), $this->tenant);

    $this->postJson("/api/v1/contract-register/customer/{$id}/transition", ['status' => 'renewal'])->assertForbidden();
    $this->getJson("/api/v1/contract-register/customer/{$id}/history")->assertForbidden();
    $this->getJson('/api/v1/contract-register/obligations')->assertForbidden();
    $this->postJson("/api/v1/contract-register/customer/{$id}/obligations", ['title' => 'x', 'type' => 'obligation', 'due_date' => '2026-12-01'])->assertForbidden();
    $this->getJson("/api/v1/contract-register/customer/{$id}/parties")->assertForbidden();
    $this->postJson("/api/v1/contract-register/customer/{$id}/documents", ['document_id' => (string) Str::uuid()])->assertForbidden();
});

test('parties link contacts, suppliers, users and external people with a role', function (): void {
    $id = ctlContract(['status' => 'active']);
    $colleague = ctlMember($this->tenant, 'sales_manager');

    $this->postJson("/api/v1/contract-register/customer/{$id}/parties", ['party_type' => 'contact', 'party_id' => $this->customer->id, 'role' => 'signatory', 'email' => 'sign@example.com'])->assertCreated()->assertJsonPath('data.name', $this->customer->company_name ?: trim($this->customer->first_name.' '.$this->customer->last_name));
    $this->postJson("/api/v1/contract-register/customer/{$id}/parties", ['party_type' => 'supplier', 'party_id' => $this->supplier->id, 'role' => 'subcontractor'])->assertCreated()->assertJsonPath('data.name', $this->supplier->company_name);
    $this->postJson("/api/v1/contract-register/customer/{$id}/parties", ['party_type' => 'user', 'party_id' => $colleague->id, 'role' => 'representative'])->assertCreated()->assertJsonPath('data.name', $colleague->name);
    $partyId = $this->postJson("/api/v1/contract-register/customer/{$id}/parties", ['party_type' => 'external', 'name' => 'Notary Rossi', 'role' => 'witness'])->assertCreated()->assertJsonPath('data.name', 'Notary Rossi')->json('data.id');

    expect($this->getJson("/api/v1/contract-register/customer/{$id}/parties")->assertOk()->json('data'))->toHaveCount(4);

    $this->postJson("/api/v1/contract-register/customer/{$id}/parties", ['party_type' => 'external', 'name' => 'notary rossi', 'role' => 'witness'])->assertConflict();
    $this->postJson("/api/v1/contract-register/customer/{$id}/parties", ['party_type' => 'contact', 'party_id' => $this->customer->id, 'role' => 'guarantor'])->assertCreated();

    $this->deleteJson("/api/v1/contract-register/customer/{$id}/parties/{$partyId}")->assertOk();
    $this->deleteJson("/api/v1/contract-register/customer/{$id}/parties/{$partyId}")->assertNotFound();
    expect($this->getJson("/api/v1/contract-register/customer/{$id}/parties")->json('data'))->toHaveCount(4);
});

test('party input is validated and cannot reach records of another company', function (): void {
    $id = ctlContract(['status' => 'active']);
    $foreignContact = Contact::factory()->create(['tenant_id' => $this->other->id]);
    $stranger = ctlMember($this->other, 'admin');

    $this->postJson("/api/v1/contract-register/customer/{$id}/parties", [])->assertUnprocessable()->assertJsonValidationErrors(['party_type', 'role']);
    $this->postJson("/api/v1/contract-register/customer/{$id}/parties", ['party_type' => 'contact', 'role' => 'signatory'])->assertUnprocessable()->assertJsonValidationErrors(['party_id']);
    $this->postJson("/api/v1/contract-register/customer/{$id}/parties", ['party_type' => 'contact', 'party_id' => $foreignContact->id, 'role' => 'signatory'])->assertUnprocessable()->assertJsonValidationErrors(['party_id']);
    $this->postJson("/api/v1/contract-register/customer/{$id}/parties", ['party_type' => 'user', 'party_id' => $stranger->id, 'role' => 'signatory'])->assertUnprocessable()->assertJsonValidationErrors(['party_id']);
    $this->postJson("/api/v1/contract-register/customer/{$id}/parties", ['party_type' => 'external', 'role' => 'witness'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->postJson("/api/v1/contract-register/customer/{$id}/parties", ['party_type' => 'external', 'name' => 'X', 'party_id' => $this->customer->id, 'role' => 'witness'])->assertUnprocessable()->assertJsonValidationErrors(['party_id']);
    $this->postJson("/api/v1/contract-register/customer/{$id}/parties", ['party_type' => 'external', 'name' => 'X', 'role' => 'emperor'])->assertUnprocessable()->assertJsonValidationErrors(['role']);
});

test('documents of the document management module are attached to a contract and respect their own visibility', function (): void {
    $id = ctlContract(['status' => 'active']);
    $upload = fn (string $title, array $extra = []): string => $this->post('/api/v1/dms/documents', ['title' => $title, 'file' => UploadedFile::fake()->createWithContent("{$title}.txt", $title), ...$extra], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $signed = $upload('Signed contract');
    $restricted = $upload('Board minutes', ['access_roles' => ['admin']]);

    $this->postJson("/api/v1/contract-register/customer/{$id}/documents", ['document_id' => $signed])->assertCreated()->assertJsonPath('data.title', 'Signed contract');
    $this->postJson("/api/v1/contract-register/customer/{$id}/documents", ['document_id' => $signed])->assertCreated();
    $this->postJson("/api/v1/contract-register/customer/{$id}/documents", ['document_id' => $restricted])->assertNotFound();
    $this->postJson("/api/v1/contract-register/customer/{$id}/documents", ['document_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['document_id']);
    $this->postJson("/api/v1/contract-register/customer/{$id}/documents", [])->assertUnprocessable()->assertJsonValidationErrors(['document_id']);

    expect($this->getJson("/api/v1/contract-register/customer/{$id}/documents")->assertOk()->json('data'))->toHaveCount(1)
        ->and($this->getJson("/api/v1/dms/documents/{$signed}/links")->json('data.0'))->toMatchArray(['linkable_type' => 'service_contract', 'record_exists' => true]);

    $this->deleteJson("/api/v1/contract-register/customer/{$id}/documents/{$signed}")->assertOk();
    $this->deleteJson("/api/v1/contract-register/customer/{$id}/documents/{$signed}")->assertNotFound();
    expect($this->getJson("/api/v1/contract-register/customer/{$id}/documents")->json('data'))->toBe([]);

    // The attachment and its removal are in the audit trail of the document, for the people who can read it.
    ctlActAs(ctlMember($this->tenant, 'admin'), $this->tenant);
    $actions = collect($this->getJson("/api/v1/dms/documents/{$signed}/audit-trail")->assertOk()->json('data'))->pluck('action')->all();
    expect($actions)->toContain('DOCUMENT_LINKED', 'DOCUMENT_UNLINKED');
});

test('a supplier contract takes the same lifecycle, obligations and documents', function (): void {
    $id = $this->postJson('/api/v1/contract-register', ctlSupplier())->assertCreated()->json('data.id');

    $this->postJson("/api/v1/contract-register/supplier/{$id}/transition", ['status' => 'review'])->assertOk();
    $this->postJson("/api/v1/contract-register/supplier/{$id}/transition", ['status' => 'active'])->assertOk();
    $this->postJson("/api/v1/contract-register/supplier/{$id}/obligations", ['title' => 'Quarterly review', 'type' => 'milestone', 'due_date' => '2027-03-31'])->assertCreated()->assertJsonPath('data.contract_kind', 'supplier');

    expect($this->getJson("/api/v1/contract-register/supplier/{$id}/obligations")->json('data'))->toHaveCount(1)
        ->and(DB::table('contract_status_changes')->where('contract_kind', 'supplier')->count())->toBe(2);
});
