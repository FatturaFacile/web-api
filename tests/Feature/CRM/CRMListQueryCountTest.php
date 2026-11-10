<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadSource;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'CRM Query Count Tenant',
        'legal_name' => 'CRM Query Count Tenant SpA',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::create([
        'id' => (string) Str::uuid7(),
        'email' => 'crmquerycount@example.com',
        'name' => 'CRM Query Count User',
        'password' => bcrypt('secret'),
    ]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    $this->actingAs($this->user);
    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id);
});

/**
 * Number of SQL statements issued while serving the given authenticated GET request.
 */
function queriesFor(string $url): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->getJson($url)->assertOk();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('the lead list issues the same number of queries for 25 rows as for 1', function (): void {
    $makeLead = function (int $number): void {
        $source = LeadSource::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $this->tenant->id,
            'code' => 'src-'.$number,
            'name' => 'Source '.$number,
        ]);

        Lead::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $this->tenant->id,
            'lead_source_id' => (string) $source->id,
            'first_name' => 'Lead '.$number,
            'company_name' => 'Company '.$number,
            'status' => 'new',
            'score' => $number,
        ]);
    };

    $makeLead(1);
    $oneRow = queriesFor('/api/v1/crm/leads');

    foreach (range(2, 25) as $number) {
        $makeLead($number);
    }

    $response = $this->getJson('/api/v1/crm/leads');
    expect($response->json('data'))->toHaveCount(25);
    expect($response->json('data.0.lead_source.name'))->not->toBeNull();

    expect(queriesFor('/api/v1/crm/leads'))->toBe($oneRow);
});

test('the opportunity list issues the same number of queries for 25 rows as for 1', function (): void {
    $stage = OpportunityStage::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'code' => 'discovery',
        'name' => 'Discovery',
        'default_probability' => 20.0,
        'is_active' => true,
    ]);

    $makeOpportunity = function (int $number) use ($stage): void {
        $account = Account::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $this->tenant->id,
            'account_number' => 'ACC-QC-'.$number,
            'name' => 'Account '.$number,
        ]);

        Opportunity::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $this->tenant->id,
            'account_id' => (string) $account->id,
            'stage_id' => (string) $stage->id,
            'opportunity_number' => 'OPP-QC-'.$number,
            'name' => 'Opportunity '.$number,
            'amount' => 1000.00 * $number,
            'expected_revenue' => 500.00 * $number,
            'currency' => 'EUR',
            'probability' => 20.00,
            'expected_close_date' => '2027-06-30',
            'status' => 'open',
        ]);
    };

    $makeOpportunity(1);
    $oneRow = queriesFor('/api/v1/crm/opportunities');

    foreach (range(2, 25) as $number) {
        $makeOpportunity($number);
    }

    $response = $this->getJson('/api/v1/crm/opportunities');
    expect($response->json('data'))->toHaveCount(25);
    expect($response->json('data.0.account.name'))->not->toBeNull();
    expect($response->json('data.0.stage.code'))->toBe('discovery');

    expect(queriesFor('/api/v1/crm/opportunities'))->toBe($oneRow);
});
