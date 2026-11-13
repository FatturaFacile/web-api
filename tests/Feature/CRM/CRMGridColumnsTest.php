<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Campaign;
use Modules\CRM\Models\CampaignMember;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'CRM Grid Tenant',
        'legal_name' => 'CRM Grid Tenant SpA',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::create([
        'id' => (string) Str::uuid7(),
        'email' => 'crmgrid@example.com',
        'name' => 'CRM Grid User',
        'password' => bcrypt('secret'),
    ]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    $this->actingAs($this->user);
    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function crmGridLead(array $attributes): Lead
{
    return Lead::create(array_merge([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) test()->tenant->id,
        'status' => 'new',
        'score' => 10,
    ], $attributes));
}

test('account display_name is the linked contact company name, else the account name', function (): void {
    $contact = Contact::factory()->create(['company_name' => 'Zeta Linked Srl', 'type' => 'client']);

    foreach ([['ACC-1', 'Alpha Account', null], ['ACC-2', 'Stale Copy', $contact->id], ['ACC-3', 'Beta Account', null]] as [$number, $name, $contactId]) {
        Account::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $this->tenant->id,
            'account_number' => $number,
            'name' => $name,
            'contact_id' => $contactId,
        ]);
    }

    $sorted = $this->getJson('/api/v1/crm/accounts?sort_by=display_name&sort_dir=desc')->assertOk();
    expect(collect($sorted->json('data'))->pluck('name')->all())->toBe(['Zeta Linked Srl', 'Beta Account', 'Alpha Account'])
        ->and($sorted->json('meta.last_page'))->toBe(1)
        ->and($sorted->json('meta.per_page'))->toBeInt();

    $values = $this->getJson('/api/v1/crm/accounts?grid_values=display_name')->assertOk();
    expect(collect($values->json('data'))->pluck('value')->sort()->values()->all())->toBe(['Alpha Account', 'Beta Account', 'Zeta Linked Srl']);

    $filtered = $this->getJson('/api/v1/crm/accounts?filter[display_name][]=Zeta%20Linked%20Srl')->assertOk();
    expect($filtered->json('data'))->toHaveCount(1);

    $like = $this->getJson('/api/v1/crm/accounts?filter_like[display_name]=beta')->assertOk();
    expect($like->json('data'))->toHaveCount(1);
});

test('lead display_name sorts, filters and groups by the full name else the company, and search finds leads', function (): void {
    crmGridLead(['first_name' => 'Mario', 'last_name' => 'Rossi', 'company_name' => 'Acme']);
    crmGridLead(['company_name' => 'Zebra Corp']);
    crmGridLead(['first_name' => 'Anna', 'last_name' => 'Bianchi', 'email' => 'anna@example.test']);

    $sorted = $this->getJson('/api/v1/crm/leads?sort_by=display_name')->assertOk();
    expect(collect($sorted->json('data'))->pluck('full_name')->all())->toBe(['Anna Bianchi', 'Mario Rossi', ''])
        ->and($sorted->json('data.2.company_name'))->toBe('Zebra Corp');

    $values = $this->getJson('/api/v1/crm/leads?grid_values=display_name')->assertOk();
    expect(collect($values->json('data'))->pluck('value')->sort()->values()->all())->toBe(['Anna Bianchi', 'Mario Rossi', 'Zebra Corp']);

    $filtered = $this->getJson('/api/v1/crm/leads?filter[display_name][]=Zebra%20Corp')->assertOk();
    expect($filtered->json('data'))->toHaveCount(1);

    expect($this->getJson('/api/v1/crm/leads?search=bianchi')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/crm/leads?search=example.test')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/crm/leads?search=acme')->json('data'))->toHaveCount(1);
});

test('opportunities filter by stage code and search, and sort by the account relation column', function (): void {
    $stages = [];
    foreach ([['discovery', 'Discovery'], ['proposal', 'Proposal']] as [$code, $name]) {
        $stages[$code] = OpportunityStage::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $this->tenant->id,
            'code' => $code,
            'name' => $name,
            'default_probability' => 20.0,
            'is_active' => true,
        ]);
    }

    foreach ([['Cloud migration', 'discovery', 'Beta Account'], ['Data lake', 'proposal', 'Alpha Account'], ['Cloud backup', 'proposal', 'Gamma Account']] as $index => [$name, $stage, $accountName]) {
        $account = Account::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $this->tenant->id,
            'account_number' => 'ACC-OPP-'.$index,
            'name' => $accountName,
        ]);
        Opportunity::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $this->tenant->id,
            'account_id' => (string) $account->id,
            'stage_id' => (string) $stages[$stage]->id,
            'opportunity_number' => 'OPP-GRID-'.$index,
            'name' => $name,
            'amount' => 1000,
            'expected_revenue' => 500,
            'currency' => 'EUR',
            'probability' => 20.00,
            'expected_close_date' => '2027-06-30',
            'status' => 'open',
        ]);
    }

    $byStage = $this->getJson('/api/v1/crm/opportunities?stage_code=proposal')->assertOk();
    expect($byStage->json('data'))->toHaveCount(2);

    $bySearch = $this->getJson('/api/v1/crm/opportunities?search=cloud&stage_code=proposal')->assertOk();
    expect(collect($bySearch->json('data'))->pluck('name')->all())->toBe(['Cloud backup']);

    $sorted = $this->getJson('/api/v1/crm/opportunities?sort_by=account.name&sort_dir=asc')->assertOk();
    expect(collect($sorted->json('data'))->pluck('account.name')->all())->toBe(['Alpha Account', 'Beta Account', 'Gamma Account']);

    $values = $this->getJson('/api/v1/crm/opportunities?grid_values=stage.name')->assertOk();
    expect(collect($values->json('data'))->pluck('count', 'value')->all())->toBe(['Discovery' => 1, 'Proposal' => 2]);
});

test('campaign members expose the contact or lead name and email as sortable, filterable grid columns', function (): void {
    $campaign = Campaign::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'code' => 'CMP-G', 'name' => 'Grid campaign', 'status' => 'planned']);
    $contact = Contact::factory()->create(['company_name' => 'Delta Srl', 'email' => 'delta@example.test', 'type' => 'client']);
    $lead = crmGridLead(['first_name' => 'Carla', 'last_name' => 'Verdi', 'email' => 'carla@example.test']);

    foreach ([['contact_id', $contact->id], ['lead_id', $lead->id]] as [$column, $value]) {
        CampaignMember::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $this->tenant->id,
            'campaign_id' => $campaign->id,
            $column => $value,
            'status' => 'sent',
        ]);
    }

    $url = "/api/v1/crm/campaigns/{$campaign->id}/members";

    $sorted = $this->getJson($url.'?sort_by=name')->assertOk();
    expect(collect($sorted->json('data'))->pluck('name')->all())->toBe(['Carla Verdi', 'Delta Srl'])
        ->and(collect($sorted->json('data'))->pluck('email')->all())->toBe(['carla@example.test', 'delta@example.test'])
        ->and($sorted->json('meta.last_page'))->toBe(1);

    $values = $this->getJson($url.'?grid_values=email')->assertOk();
    expect(collect($values->json('data'))->pluck('value')->sort()->values()->all())->toBe(['carla@example.test', 'delta@example.test']);

    $filtered = $this->getJson($url.'?filter_like[name]=delta')->assertOk();
    expect($filtered->json('data'))->toHaveCount(1)->and($filtered->json('data.0.name'))->toBe('Delta Srl');
});
