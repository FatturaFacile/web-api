<?php

declare(strict_types=1);

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Reimbursement;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    actAs($this, 'secretary');
});

afterEach(fn () => associationsTearDown());

function gridMember(string $first, string $last, ?string $company = null): Member
{
    $contact = Contact::create(['first_name' => $first, 'last_name' => $last, 'company_name' => $company]);

    return Member::factory()->create(['contact_id' => $contact->id]);
}

test('the registry grid sorts, filters and lists the values of the calculated full name', function (): void {
    gridMember('Carla', 'Neri');
    gridMember('Anna', 'Rossi');
    gridMember('', '', 'Zeta Lugano');

    $sorted = $this->getJson('/api/v1/associations/members?sort_by=full_name&sort_dir=asc')->assertOk();
    expect(collect($sorted->json('data'))->map(fn (array $row): string => trim("{$row['first_name']} {$row['last_name']}") ?: (string) $row['company_name'])->all())
        ->toBe(['Anna Rossi', 'Carla Neri', 'Zeta Lugano']);

    $filtered = $this->getJson('/api/v1/associations/members?'.http_build_query(['filter' => ['full_name' => ['Anna Rossi', 'Zeta Lugano']]]))->assertOk();
    expect($filtered->json('meta.total'))->toBe(2);

    $like = $this->getJson('/api/v1/associations/members?'.http_build_query(['filter_like' => ['full_name' => 'neri']]))->assertOk();
    expect($like->json('meta.total'))->toBe(1);

    $values = $this->getJson('/api/v1/associations/members?grid_values=full_name')->assertOk();
    expect(collect($values->json('data'))->pluck('value')->sort()->values()->all())->toBe(['Anna Rossi', 'Carla Neri', 'Zeta Lugano']);
});

test('fee assignments and reimbursements sort and filter by the name of their member', function (): void {
    $anna = gridMember('Anna', 'Rossi');
    $carla = gridMember('Carla', 'Neri');

    foreach ([$carla, $anna] as $member) {
        FeeAssignment::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $member->id]);
        Reimbursement::factory()->expense()->create(['tenant_id' => $this->tenant->id, 'member_id' => $member->id]);
    }

    foreach (['fee-assignments', 'reimbursements'] as $endpoint) {
        $sorted = $this->getJson("/api/v1/associations/{$endpoint}?sort_by=member_name&sort_dir=asc")->assertOk();
        expect(collect($sorted->json('data'))->map(fn (array $row): string => $row['member']['contact']['first_name'])->all())->toBe(['Anna', 'Carla']);

        $filtered = $this->getJson("/api/v1/associations/{$endpoint}?".http_build_query(['filter' => ['member_name' => ['Carla Neri']]]))->assertOk();
        expect($filtered->json('meta.total'))->toBe(1)
            ->and($filtered->json('data.0.member.contact.last_name'))->toBe('Neri');

        $values = $this->getJson("/api/v1/associations/{$endpoint}?grid_values=member_name")->assertOk();
        expect(collect($values->json('data'))->pluck('value')->sort()->values()->all())->toBe(['Anna Rossi', 'Carla Neri']);
    }
});
