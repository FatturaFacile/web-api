<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Childcare\Models\MonthlyBill;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-02 07:00:00');
    $this->tenant = verticalTenant();
    $this->parent = verticalContact($this->tenant, 'Famiglia Bernasconi');
    $this->manager = verticalMember($this->tenant, 'childcare_manager');
    verticalApi($this->manager, $this->tenant);

    $this->groupA = $this->postJson('/api/v1/childcare/groups', ['name' => 'Orsetti'])->assertCreated()->json('data.id');
    $this->groupB = $this->postJson('/api/v1/childcare/groups', ['name' => 'Coniglietti'])->assertCreated()->json('data.id');
    $this->enrol = fn (string $first, string $last, string $group): string => $this->postJson('/api/v1/childcare/children', [
        'billing_contact_id' => $this->parent->id, 'first_name' => $first, 'last_name' => $last, 'enrolled_on' => '2026-10-15', 'group_id' => $group,
    ])->assertCreated()->json('data.id');
});

test('the children grid sorts, filters and lists values by the decrypted name, the group and the billing contact', function (): void {
    ($this->enrol)('Carla', 'Zanetti', $this->groupA);
    ($this->enrol)('Anna', 'Rossi', $this->groupB);
    ($this->enrol)('Bruno', 'Verdi', $this->groupB);

    $rows = $this->getJson('/api/v1/childcare/children?sort_by=display_name&sort_dir=desc')->assertOk();
    expect($rows->json('data.*.first_name'))->toBe(['Carla', 'Bruno', 'Anna'])
        ->and($rows->json('meta.total'))->toBe(3)
        ->and($rows->json('data.0.group_name'))->toBe('Orsetti')
        ->and($rows->json('data.0.billing_contact_name'))->toBe('Famiglia Bernasconi')
        ->and($rows->json('data.0'))->not->toHaveKeys(['group', 'billing_contact']);

    $this->getJson('/api/v1/childcare/children?filter[display_name][]=Anna Rossi')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.first_name', 'Anna');
    $this->getJson('/api/v1/childcare/children?filter_like[display_name]=ruNo')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.first_name', 'Bruno');
    $this->getJson('/api/v1/childcare/children?filter[group.name][]=Coniglietti&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);

    $groups = collect($this->getJson('/api/v1/childcare/children?grid_values=group.name')->assertOk()->json('data'))->pluck('count', 'value')->all();
    expect($groups)->toBe(['Coniglietti' => 2, 'Orsetti' => 1]);

    $contacts = $this->getJson('/api/v1/childcare/children?grid_values=billing_contact_name')->assertOk()->json('data');
    expect($contacts)->toBe([['value' => 'Famiglia Bernasconi', 'count' => 3]]);
});

test('the notes grid sorts and filters by the decrypted child name and body', function (): void {
    $anna = ($this->enrol)('Anna', 'Rossi', $this->groupA);
    $bruno = ($this->enrol)('Bruno', 'Verdi', $this->groupA);
    foreach ([[$bruno, 'Slept well'], [$anna, 'Ate everything'], [$anna, 'Played outside']] as [$child, $body]) {
        $this->postJson('/api/v1/childcare/notes', ['child_id' => $child, 'date' => '2026-11-02', 'category' => 'general', 'body' => $body, 'visible_to_family' => true])->assertCreated();
    }

    $sorted = $this->getJson('/api/v1/childcare/notes?sort_by=child_name&sort_dir=desc')->assertOk();
    expect($sorted->json('data.0.child_name'))->toBe('Bruno Verdi')->and($sorted->json('meta.total'))->toBe(3);

    $this->getJson('/api/v1/childcare/notes?filter_like[body_text]=ate')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'Ate everything');

    $values = collect($this->getJson('/api/v1/childcare/notes?grid_values=child_name')->assertOk()->json('data'))->pluck('count', 'value')->all();
    expect($values)->toBe(['Anna Rossi' => 2, 'Bruno Verdi' => 1]);
});

test('the bills grid derives status, currency and names and groups by them', function (): void {
    $anna = ($this->enrol)('Anna', 'Rossi', $this->groupA);
    $bruno = ($this->enrol)('Bruno', 'Verdi', $this->groupA);
    MonthlyBill::factory()->create(['tenant_id' => $this->tenant->id, 'child_id' => $anna, 'period' => '2026-11', 'invoice_id' => null]);
    MonthlyBill::factory()->create(['tenant_id' => $this->tenant->id, 'child_id' => $bruno, 'period' => '2026-11', 'invoice_id' => null]);
    MonthlyBill::factory()->create(['tenant_id' => $this->tenant->id, 'child_id' => $bruno, 'period' => '2026-10', 'invoice_id' => null]);

    $list = $this->getJson('/api/v1/childcare/billing/bills?period=2026-11&sort_by=child_name&sort_dir=desc')->assertOk();
    expect($list->json('meta.total'))->toBe(2)
        ->and($list->json('data.0.child_name'))->toBe('Bruno Verdi')
        ->and($list->json('data.0.contact_name'))->toBe('Famiglia Bernasconi')
        ->and($list->json('data.0.contact_id'))->toBe($this->parent->id)
        ->and($list->json('data.0.status'))->toBe('draft')
        ->and($list->json('data.0.currency'))->toBe('EUR');

    $statuses = $this->getJson('/api/v1/childcare/billing/bills?period=2026-11&grid_values=status')->assertOk()->json('data');
    expect($statuses)->toBe([['value' => 'draft', 'count' => 2]]);
    $this->getJson('/api/v1/childcare/billing/bills?period=2026-11&filter[status][]=paid')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/childcare/billing/bills?period=2026-11&filter[status][]=draft')->assertOk()->assertJsonCount(2, 'data');
});
