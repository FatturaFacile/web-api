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
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadSource;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;

uses(RefreshDatabase::class);

/**
 * Pipeline stages configured per tenant, the opportunity pipeline through the API, weighted forecasts, lead
 * qualification and conversion through the API, campaigns, and the CRM reports.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Pipeline Tenant', 'legal_name' => 'Pipeline Tenant SpA']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::create(['name' => 'Sales Manager', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'sales_manager']);
    $this->actingAs($this->user);
    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id);

    $contact = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->account = Account::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'account_number' => 'ACC-PIPE', 'name' => 'Pipe Corp', 'contact_id' => $contact->id]);
});

test('default stages install once and stages are configurable per tenant', function (): void {
    $this->postJson('/api/v1/crm/pipeline-stages/defaults')->assertOk()->assertJsonPath('created', 6);
    $this->postJson('/api/v1/crm/pipeline-stages/defaults')->assertOk()->assertJsonPath('created', 0)->assertJsonCount(6, 'data');

    $this->postJson('/api/v1/crm/pipeline-stages', ['code' => 'demo', 'name' => 'Demo', 'sequence' => 35, 'default_probability' => 60])
        ->assertCreated()->assertJsonPath('default_probability', 60);
    $this->postJson('/api/v1/crm/pipeline-stages', ['code' => 'demo', 'name' => 'Duplicate', 'default_probability' => 10])->assertUnprocessable();
    $this->postJson('/api/v1/crm/pipeline-stages', ['code' => 'odd', 'name' => 'Odd', 'default_probability' => 10, 'is_won' => true, 'is_lost' => true])->assertUnprocessable();

    $codes = collect($this->getJson('/api/v1/crm/pipeline-stages')->assertOk()->json('data'))->pluck('code')->all();
    expect($codes)->toBe(['discovery', 'qualification', 'proposal', 'demo', 'negotiation', 'closed_won', 'closed_lost']);
});

test('changing a stage probability reprices its open opportunities and the weighted pipeline', function (): void {
    $this->postJson('/api/v1/crm/pipeline-stages/defaults')->assertOk();

    $first = $this->postJson('/api/v1/crm/opportunities', ['account_id' => $this->account->id, 'name' => 'Deal A', 'amount' => 10000, 'stage_code' => 'proposal'])->assertCreated();
    $this->postJson('/api/v1/crm/opportunities', ['account_id' => $this->account->id, 'name' => 'Deal B', 'amount' => 20000, 'stage_code' => 'proposal'])->assertCreated();
    $this->postJson('/api/v1/crm/opportunities', ['account_id' => $this->account->id, 'name' => 'Deal C', 'amount' => 4000, 'stage_code' => 'discovery'])->assertCreated();

    $this->getJson('/api/v1/crm/reports/pipeline')->assertOk()
        ->assertJsonPath('totals.opportunities', 3)
        ->assertJsonPath('totals.weighted_amount', 15400);

    app(TenantContext::class)->setTenant($this->tenant);
    $proposal = OpportunityStage::where('code', 'proposal')->first();
    $this->putJson("/api/v1/crm/pipeline-stages/{$proposal->id}", ['default_probability' => 80, 'reprice_open_opportunities' => true])
        ->assertOk()->assertJsonPath('repriced_opportunities', 2);

    $report = $this->getJson('/api/v1/crm/reports/pipeline')->assertOk();
    expect((float) $report->json('totals.weighted_amount'))->toBe(24400.0)
        ->and(collect($report->json('by_stage'))->firstWhere('code', 'proposal')['weighted_amount'])->toEqual(24000)
        ->and($report->json('by_owner.0.owner_user_id'))->toBe($this->user->id);

    app(TenantContext::class)->setTenant($this->tenant);
    expect((float) Opportunity::find($first->json('id'))->expected_revenue)->toBe(8000.0);

    $this->deleteJson("/api/v1/crm/pipeline-stages/{$proposal->id}")->assertStatus(409);
    $unused = OpportunityStage::where('code', 'negotiation')->first();
    $this->deleteJson("/api/v1/crm/pipeline-stages/{$unused->id}")->assertNoContent();
});

test('the opportunity pipeline moves through stages to won and lost via the API', function (): void {
    $this->postJson('/api/v1/crm/pipeline-stages/defaults')->assertOk();
    $won = $this->postJson('/api/v1/crm/opportunities', ['account_id' => $this->account->id, 'name' => 'Win me', 'amount' => 5000])->assertCreated();
    $lost = $this->postJson('/api/v1/crm/opportunities', ['account_id' => $this->account->id, 'name' => 'Lose me', 'amount' => 7000])->assertCreated();

    $this->postJson('/api/v1/crm/opportunities/'.$won->json('id').'/stage', ['target_stage_code' => 'negotiation'])->assertOk()->assertJsonPath('status', 'open');
    $this->postJson('/api/v1/crm/opportunities/'.$won->json('id').'/stage', ['target_stage_code' => 'closed_won'])->assertOk()->assertJsonPath('status', 'won');
    $this->postJson('/api/v1/crm/opportunities/'.$lost->json('id').'/stage', ['target_stage_code' => 'closed_lost', 'win_loss_reason' => 'Price'])->assertOk()->assertJsonPath('status', 'lost');

    $this->getJson('/api/v1/crm/reports/conversion')->assertOk()
        ->assertJsonPath('opportunities.won', 1)
        ->assertJsonPath('opportunities.lost', 1)
        ->assertJsonPath('opportunities.win_rate', 50);

    $forecast = $this->postJson('/api/v1/crm/forecasts/generate', ['period' => now()->addDays(30)->format('Y-m'), 'quota_amount' => 10000])->assertSuccessful();
    expect((float) $forecast->json('closed_amount'))->toBe(5000.0)
        ->and((float) $forecast->json('weighted_amount'))->toBe(5000.0);

    $this->postJson('/api/v1/crm/forecasts/'.$forecast->json('id').'/lock')->assertOk()->assertJsonPath('status', 'locked');
    $this->getJson('/api/v1/crm/forecasts')->assertOk();
});

test('opportunities cannot reference an account of another tenant', function (): void {
    $this->postJson('/api/v1/crm/pipeline-stages/defaults')->assertOk();
    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other SpA']);
    $foreignAccount = app(TenantContext::class)->runWithTenant($other, fn () => Account::create(['id' => (string) Str::uuid7(), 'account_number' => 'ACC-X', 'name' => 'Foreign']));

    $this->postJson('/api/v1/crm/opportunities', ['account_id' => $foreignAccount->id, 'name' => 'Leak', 'amount' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('account_id');
});

test('leads are qualified, disqualified and converted through the API and feed the conversion report', function (): void {
    $source = LeadSource::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'code' => 'web', 'name' => 'Website']);
    $toConvert = $this->postJson('/api/v1/crm/leads', ['first_name' => 'Carla', 'company_name' => 'Carla Srl', 'email' => 'carla@carla.it', 'lead_source_code' => 'web'])->assertCreated();
    $toReject = $this->postJson('/api/v1/crm/leads', ['first_name' => 'Dino', 'company_name' => 'Dino Srl', 'lead_source_code' => 'web'])->assertCreated();
    $this->postJson('/api/v1/crm/leads', ['first_name' => 'Ezio'])->assertCreated();

    $this->postJson('/api/v1/crm/leads/'.$toConvert->json('id').'/qualify')->assertOk()->assertJsonPath('status', 'qualified');
    $this->postJson('/api/v1/crm/leads/'.$toReject->json('id').'/disqualify', [])->assertUnprocessable();
    $this->postJson('/api/v1/crm/leads/'.$toReject->json('id').'/disqualify', ['reason' => 'No budget'])->assertOk()->assertJsonPath('status', 'disqualified');

    $converted = $this->postJson('/api/v1/crm/leads/'.$toConvert->json('id').'/convert', ['opportunity_amount' => 12000])->assertOk();
    expect($converted->json('contact_linked'))->toBeFalse()
        ->and($converted->json('opportunity.name'))->toContain('Carla Srl')
        ->and($converted->json('account.contact.email'))->toBe('carla@carla.it');

    $this->postJson('/api/v1/crm/leads/'.$toConvert->json('id').'/convert')->assertStatus(409);

    $report = $this->getJson('/api/v1/crm/reports/conversion')->assertOk();
    expect($report->json('leads.total'))->toBe(3)
        ->and($report->json('leads.converted'))->toBe(1)
        ->and((float) $report->json('leads.conversion_rate'))->toBe(33.33)
        ->and(collect($report->json('leads.by_source'))->firstWhere('lead_source', 'web')['leads'])->toBe(2)
        ->and($source->code)->toBe('web');
});

test('campaigns are created, listed and report their ROI through the API', function (): void {
    $campaign = $this->postJson('/api/v1/crm/campaigns', ['name' => 'Spring Fair', 'budgeted_cost' => 5000, 'expected_revenue' => 20000])->assertCreated();

    $this->getJson('/api/v1/crm/campaigns')->assertOk();
    $this->getJson('/api/v1/crm/campaigns/'.$campaign->json('id'))->assertOk()->assertJsonPath('name', 'Spring Fair');
    $this->getJson('/api/v1/crm/campaigns/'.$campaign->json('id').'/roi')->assertOk();
});

test('the activity report counts by type, status and assignee with the overdue backlog', function (): void {
    $this->postJson('/api/v1/crm/activities', ['subject' => 'Late call', 'type' => 'call', 'due_date' => now()->subDay()->toDateTimeString()])->assertCreated();
    $done = $this->postJson('/api/v1/crm/activities', ['subject' => 'Meeting', 'type' => 'meeting'])->assertCreated();
    $this->postJson('/api/v1/crm/activities/'.$done->json('id').'/complete')->assertOk();

    $this->getJson('/api/v1/crm/reports/activities')->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('completed', 1)
        ->assertJsonPath('overdue', 1)
        ->assertJsonPath('completion_rate', 50)
        ->assertJsonPath('by_assignee.0.assigned_user_id', $this->user->id);
});

test('reports only see the active tenant', function (): void {
    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other SpA']);
    app(TenantContext::class)->runWithTenant($other, fn () => Lead::create(['id' => (string) Str::uuid7(), 'first_name' => 'Foreign', 'status' => 'converted']));

    $this->getJson('/api/v1/crm/reports/conversion')->assertOk()->assertJsonPath('leads.total', 0);
});
