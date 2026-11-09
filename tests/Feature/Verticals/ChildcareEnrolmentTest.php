<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-02 07:00:00');
    $this->tenant = verticalTenant();
    $this->parent = verticalContact($this->tenant, 'Famiglia Bernasconi');
    $this->manager = verticalMember($this->tenant, 'childcare_manager');
    verticalApi($this->manager, $this->tenant);

    $this->group = $this->postJson('/api/v1/childcare/groups', ['name' => 'Orsetti', 'capacity' => 1])->assertCreated()->json('data.id');
    $this->unlimited = $this->postJson('/api/v1/childcare/groups', ['name' => 'Coniglietti'])->assertCreated()->json('data.id');
    $this->enrol = fn (string $name, array $overrides = []) => $this->postJson('/api/v1/childcare/children', [
        'billing_contact_id' => $this->parent->id, 'first_name' => $name, 'last_name' => 'Bernasconi', 'enrolled_on' => '2026-10-15', ...$overrides,
    ]);
});

test('a group never holds more active children than its capacity', function (): void {
    $first = ($this->enrol)('Anna', ['group_id' => $this->group])->assertCreated()->assertJsonPath('data.status', 'active')->json('data.id');
    ($this->enrol)('Bruno', ['group_id' => $this->group])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'Orsetti is full'));
    $second = ($this->enrol)('Carla', ['group_id' => $this->unlimited])->assertCreated()->json('data.id');
    ($this->enrol)('Dario', ['group_id' => $this->unlimited])->assertCreated();

    $this->putJson("/api/v1/childcare/children/{$second}", ['group_id' => $this->group])->assertUnprocessable();
    $this->putJson("/api/v1/childcare/children/{$first}", ['group_id' => $this->group, 'first_name' => 'Anna Maria'])->assertOk();
    $this->putJson("/api/v1/childcare/children/{$first}", ['status' => 'left', 'left_on' => '2026-11-01'])->assertOk();
    $this->putJson("/api/v1/childcare/children/{$second}", ['group_id' => $this->group])->assertOk();

    $groups = collect($this->getJson('/api/v1/childcare/groups')->assertOk()->json('data'))->keyBy('name');
    expect($groups['Orsetti'])->toMatchArray(['active_children' => 1, 'places_left' => 0])
        ->and($groups['Coniglietti'])->toMatchArray(['active_children' => 1, 'places_left' => null]);
});

test('the waiting list is admitted in the order of the applications and into a free place only', function (): void {
    ($this->enrol)('Anna', ['group_id' => $this->group])->assertCreated();
    $late = ($this->enrol)('Tardi', ['status' => 'waiting', 'group_id' => $this->group, 'enrolled_on' => '2026-10-20'])->assertCreated()->assertJsonPath('data.status', 'waiting')->json('data.id');
    $early = ($this->enrol)('Presto', ['status' => 'waiting', 'group_id' => $this->group, 'enrolled_on' => '2026-10-10'])->assertCreated()->json('data.id');
    ($this->enrol)('Sinistro', ['status' => 'left'])->assertUnprocessable()->assertJsonValidationErrors('status');

    $list = $this->getJson('/api/v1/childcare/waiting-list')->assertOk()->assertJsonCount(2, 'data');
    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$early, $late])
        ->and($list->json('data.0.position'))->toBe(1);
    $this->getJson('/api/v1/childcare/children?status=active')->assertOk()->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/childcare/children/{$early}/admit")->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'is full'));
    $this->postJson("/api/v1/childcare/children/{$early}/admit", ['group_id' => $this->unlimited])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.group_id', $this->unlimited)
        ->assertJsonPath('data.enrolled_on', fn (string $date): bool => str_starts_with($date, '2026-11-02'));
    $this->postJson("/api/v1/childcare/children/{$early}/admit")->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'waiting list'));
    $this->postJson("/api/v1/childcare/children/{$late}/admit", ['group_id' => '0190f1a0-0000-7000-8000-000000000000'])->assertUnprocessable()->assertJsonValidationErrors('group_id');
    $this->getJson('/api/v1/childcare/waiting-list')->assertOk()->assertJsonCount(1, 'data');
});

test('educators and other companies stay out of the enrolment', function (): void {
    $waiting = ($this->enrol)('Anna', ['status' => 'waiting'])->assertCreated()->json('data.id');

    verticalApi(verticalMember($this->tenant, 'educator'), $this->tenant);
    $this->getJson('/api/v1/childcare/waiting-list')->assertForbidden();
    $this->postJson("/api/v1/childcare/children/{$waiting}/admit")->assertForbidden();
    $this->getJson('/api/v1/childcare/groups')->assertOk();

    verticalApi(verticalMember($this->tenant, 'member'), $this->tenant);
    $this->getJson('/api/v1/childcare/waiting-list')->assertForbidden();

    $other = verticalTenant();
    verticalApi(verticalMember($other, 'admin'), $other);
    $this->getJson('/api/v1/childcare/waiting-list')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/childcare/children/{$waiting}/admit")->assertNotFound();
    $this->getJson('/api/v1/childcare/groups')->assertOk()->assertJsonCount(0, 'data');
});
