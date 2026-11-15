<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-15 09:00:00');
    $this->tenant = plmTenant('Meccanica');
    $this->owner = plmMember($this->tenant, 'owner');
    $this->admin = plmMember($this->tenant, 'admin');
    plmAct($this->owner, $this->tenant);
    $this->item = MfgItem::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->bom = MfgBom::factory()->create(['tenant_id' => $this->tenant->id, 'mfg_item_id' => $this->item->id]);
});

afterEach(fn () => app(TenantContext::class)->clear());

function plmTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function plmMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@plm-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function plmAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function plmOrder(array $overrides = []): array
{
    return test()->postJson('/api/v1/plm/change-orders', [
        'code' => 'ECO-'.Str::upper(Str::random(5)),
        'title' => 'Replace the bearing',
        'reason' => 'The supplier discontinued the bearing.',
        'change_type' => 'material',
        'priority' => 'high',
        'approvers' => [(string) test()->owner->id, (string) test()->admin->id],
        'affected_items' => [['mfg_item_id' => (string) test()->item->id, 'to_revision' => 'B', 'change_summary' => 'New bearing']],
        'affected_boms' => [['bom_id' => (string) test()->bom->id, 'note' => 'Swap component']],
        ...$overrides,
    ])->assertCreated()->json('data');
}

/**
 * Brings an order to the approved status with both approvers.
 *
 * @return array<string, mixed>
 */
function plmApproved(array $overrides = []): array
{
    $order = plmOrder($overrides);
    test()->postJson("/api/v1/plm/change-orders/{$order['id']}/submit")->assertOk();
    plmAct(test()->owner, test()->tenant);
    test()->postJson("/api/v1/plm/change-orders/{$order['id']}/decide", ['decision' => 'approve'])->assertOk();
    plmAct(test()->admin, test()->tenant);
    $approved = test()->postJson("/api/v1/plm/change-orders/{$order['id']}/decide", ['decision' => 'approve'])->assertOk()->json('data');
    plmAct(test()->owner, test()->tenant);

    return $approved;
}

it('creates a change order with approvers, affected items and the linked bill of material', function (): void {
    $order = plmOrder();

    expect($order['status'])->toBe('draft')
        ->and($order['approvals'])->toHaveCount(2)
        ->and($order['approvals'][0]['sequence'])->toBe(1)
        ->and($order['approvals'][0]['status'])->toBe('pending')
        ->and($order['affected_items'][0]['item_code'])->toBe($this->item->item_code)
        ->and($order['affected_boms'][0]['bom_number'])->toBe($this->bom->bom_number)
        ->and($order['affected_boms'][0]['bom_revision'])->toBe('A');

    $this->postJson('/api/v1/plm/change-orders', ['code' => $order['code'], 'title' => 'Duplicate', 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/plm/change-orders', ['code' => 'ECO-X', 'title' => 'Foreign', 'reason' => 'x', 'approvers' => [(string) Str::uuid()], 'affected_boms' => [['bom_id' => (string) Str::uuid()]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['approvers.0', 'affected_boms.0.bom_id']);
});

it('needs approvers and something affected before it is submitted, and a draft only is editable', function (): void {
    $empty = plmOrder(['approvers' => [], 'affected_items' => [], 'affected_boms' => []]);
    $this->postJson("/api/v1/plm/change-orders/{$empty['id']}/submit")->assertUnprocessable()->assertJsonValidationErrors('approvers');

    $this->putJson("/api/v1/plm/change-orders/{$empty['id']}", ['approvers' => [(string) $this->owner->id]])->assertOk()->assertJsonCount(1, 'data.approvals');
    $this->postJson("/api/v1/plm/change-orders/{$empty['id']}/submit")->assertUnprocessable()->assertJsonValidationErrors('affected_items');

    $this->putJson("/api/v1/plm/change-orders/{$empty['id']}", ['affected_boms' => [['bom_id' => (string) $this->bom->id]]])->assertOk();
    $this->postJson("/api/v1/plm/change-orders/{$empty['id']}/submit")->assertOk()->assertJsonPath('data.status', 'in_review');
    $this->putJson("/api/v1/plm/change-orders/{$empty['id']}", ['title' => 'Late edit'])->assertUnprocessable()->assertJsonValidationErrors('change_order');
    $this->deleteJson("/api/v1/plm/change-orders/{$empty['id']}")->assertUnprocessable();
});

it('runs the approval flow in sequence and only for the approver whose turn it is', function (): void {
    $order = plmOrder();
    $this->postJson("/api/v1/plm/change-orders/{$order['id']}/decide", ['decision' => 'approve'])->assertUnprocessable()->assertJsonValidationErrors('change_order');
    $this->postJson("/api/v1/plm/change-orders/{$order['id']}/submit")->assertOk();

    $this->getJson('/api/v1/plm/change-orders?awaiting_me=1')->assertOk()->assertJsonCount(1, 'data');
    plmAct($this->admin, $this->tenant);
    $this->getJson('/api/v1/plm/change-orders?awaiting_me=1')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/plm/change-orders/{$order['id']}/decide", ['decision' => 'approve'])->assertUnprocessable()->assertJsonValidationErrors('change_order');

    plmAct($this->owner, $this->tenant);
    $this->postJson("/api/v1/plm/change-orders/{$order['id']}/decide", ['decision' => 'approve', 'comment' => 'Looks right'])->assertOk()->assertJsonPath('data.status', 'in_review')->assertJsonPath('data.approvals.0.status', 'approved');
    $this->postJson("/api/v1/plm/change-orders/{$order['id']}/decide", ['decision' => 'approve'])->assertUnprocessable();

    plmAct($this->admin, $this->tenant);
    $this->getJson('/api/v1/plm/change-orders?awaiting_me=1')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/plm/change-orders/{$order['id']}/decide", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');
});

it('rejects the order at once on a rejection that carries a comment', function (): void {
    $order = plmOrder();
    $this->postJson("/api/v1/plm/change-orders/{$order['id']}/submit")->assertOk();

    $this->postJson("/api/v1/plm/change-orders/{$order['id']}/decide", ['decision' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('comment');
    $this->postJson("/api/v1/plm/change-orders/{$order['id']}/decide", ['decision' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('decision');
    $this->postJson("/api/v1/plm/change-orders/{$order['id']}/decide", ['decision' => 'reject', 'comment' => 'Too costly'])
        ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Too costly');
    $this->postJson("/api/v1/plm/change-orders/{$order['id']}/implement")->assertUnprocessable();
});

it('implements an approved order: the new revision is released and the previous one becomes obsolete', function (): void {
    $first = $this->postJson('/api/v1/plm/revisions', ['mfg_item_id' => (string) $this->item->id, 'revision' => 'A', 'change_summary' => 'Initial'])->assertCreated()->json('data');
    $this->postJson("/api/v1/plm/revisions/{$first['id']}/release")->assertOk()->assertJsonPath('data.status', 'released');

    $order = plmApproved(['effective_date' => '2027-07-01']);
    $this->postJson("/api/v1/plm/change-orders/{$order['id']}/implement")->assertOk()->assertJsonPath('data.status', 'implemented');

    $revisions = collect($this->getJson("/api/v1/plm/revisions?mfg_item_id={$this->item->id}")->assertOk()->json('data'))->keyBy('revision');
    expect($revisions['A']['status'])->toBe('obsolete')
        ->and($revisions['B']['status'])->toBe('released')
        ->and($revisions['B']['effective_from'])->toBe('2027-07-01')
        ->and($revisions['B']['change_order_id'])->toBe($order['id'])
        ->and($revisions['B']['change_summary'])->toBe('New bearing');

    $this->postJson("/api/v1/plm/change-orders/{$order['id']}/implement")->assertUnprocessable();
    $this->postJson("/api/v1/plm/change-orders/{$order['id']}/cancel")->assertUnprocessable();
});

it('refuses to implement when the target revision already exists', function (): void {
    $this->postJson('/api/v1/plm/revisions', ['mfg_item_id' => (string) $this->item->id, 'revision' => 'B'])->assertCreated();
    $order = plmApproved();

    $this->postJson("/api/v1/plm/change-orders/{$order['id']}/implement")->assertUnprocessable()->assertJsonValidationErrors('affected_items');
    $this->getJson("/api/v1/plm/change-orders/{$order['id']}")->assertOk()->assertJsonPath('data.status', 'approved');
});

it('releases the first revision directly and every later one only through a change order', function (): void {
    $a = $this->postJson('/api/v1/plm/revisions', ['mfg_item_id' => (string) $this->item->id, 'revision' => 'A'])->assertCreated()->json('data');
    $this->postJson('/api/v1/plm/revisions', ['mfg_item_id' => (string) $this->item->id, 'revision' => 'A'])->assertUnprocessable()->assertJsonValidationErrors('revision');
    $this->putJson("/api/v1/plm/revisions/{$a['id']}", ['revision' => 'A1', 'change_summary' => 'Prototype'])->assertOk()->assertJsonPath('data.revision', 'A1');
    $this->postJson("/api/v1/plm/revisions/{$a['id']}/release")->assertOk()->assertJsonPath('data.effective_from', '2027-06-15');
    $this->putJson("/api/v1/plm/revisions/{$a['id']}", ['change_summary' => 'Changed'])->assertUnprocessable();
    $this->deleteJson("/api/v1/plm/revisions/{$a['id']}")->assertUnprocessable();

    $b = $this->postJson('/api/v1/plm/revisions', ['mfg_item_id' => (string) $this->item->id, 'revision' => 'B'])->assertCreated()->json('data');
    $this->postJson("/api/v1/plm/revisions/{$b['id']}/release")->assertUnprocessable()->assertJsonValidationErrors('revision');
    $this->postJson("/api/v1/plm/revisions/{$a['id']}/obsolete")->assertOk()->assertJsonPath('data.status', 'obsolete');
    $this->postJson("/api/v1/plm/revisions/{$a['id']}/obsolete")->assertUnprocessable();
    $this->postJson("/api/v1/plm/revisions/{$b['id']}/release")->assertOk();
    $this->deleteJson("/api/v1/plm/revisions/{$b['id']}")->assertUnprocessable();

    $this->getJson('/api/v1/plm/revisions?status=released')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.item.item_code', $this->item->item_code);
});

it('cancels an order and lists with filters', function (): void {
    $order = plmOrder(['priority' => 'critical']);
    plmOrder(['priority' => 'low']);

    $this->getJson('/api/v1/plm/change-orders?priority=critical')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/plm/change-orders?search=bearing')->assertOk()->assertJsonCount(2, 'data');
    $this->postJson("/api/v1/plm/change-orders/{$order['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->getJson('/api/v1/plm/change-orders?status=cancelled')->assertOk()->assertJsonCount(1, 'data');
});

it('keeps the records inside the company and honours the role access', function (): void {
    $order = plmOrder();
    $other = plmTenant('Altra');
    plmAct(plmMember($other, 'owner'), $other);

    $this->getJson("/api/v1/plm/change-orders/{$order['id']}")->assertNotFound();
    $this->getJson('/api/v1/plm/change-orders')->assertOk()->assertJsonCount(0, 'data');

    $viewer = plmMember($this->tenant, 'accountant_readonly');
    plmAct($viewer, $this->tenant);
    $this->getJson('/api/v1/plm/change-orders')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/plm/change-orders/{$order['id']}/submit")->assertForbidden();
});
