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
use Modules\CRM\Models\AccountTerritory;
use Modules\CRM\Models\DealRegistration;
use Modules\CRM\Models\MdfFund;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;
use Modules\CRM\Models\Partner;
use Modules\CRM\Models\PartnerTier;
use Modules\CRM\Models\SalesTerritory;

uses(RefreshDatabase::class);

/**
 * Sales territories and quotas, partner program tiers, deal registration and MDF.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Channel Tenant', 'legal_name' => 'Channel Tenant SpA']);
    app(TenantContext::class)->setTenant($this->tenant);
    crmExtActAs('sales_manager');
});

/**
 * Acts as a new member of the tenant with the role.
 */
function crmExtActAs(string $role): User
{
    $user = User::create(['name' => ucfirst($role), 'email' => Str::uuid().'@example.com', 'password' => Str::random(32)]);
    $user->tenants()->attach(test()->tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);
    test()->actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) test()->tenant->id);

    return $user;
}

function crmExtAccount(string $country, string $industry = 'Manufacturing', string $tier = 'standard'): Account
{
    $contact = Contact::factory()->create(['tenant_id' => test()->tenant->id, 'country' => $country]);

    return Account::factory()->create(['tenant_id' => test()->tenant->id, 'contact_id' => $contact->id, 'industry' => $industry, 'tier' => $tier]);
}

test('territory rules assign accounts by country, industry and priority and keep manual assignments', function (): void {
    $swiss = $this->postJson('/api/v1/crm/territories', ['code' => 'CH', 'name' => 'Switzerland', 'priority' => 10, 'criteria' => ['countries' => ['ch']]])
        ->assertCreated()->json('data');
    $catchAll = $this->postJson('/api/v1/crm/territories', ['code' => 'REST', 'name' => 'Rest of world', 'priority' => 100])->assertCreated()->json('data');
    $this->postJson('/api/v1/crm/territories', ['code' => 'CH', 'name' => 'Duplicate'])->assertUnprocessable()->assertJsonValidationErrors('code');

    $inSwitzerland = crmExtAccount('CH');
    $elsewhere = crmExtAccount('IT');
    $pinned = crmExtAccount('CH');
    $this->postJson("/api/v1/crm/accounts/{$pinned->id}/territory", ['territory_id' => $catchAll['id']])->assertOk()->assertJsonPath('data.source', 'manual');

    $this->postJson('/api/v1/crm/territories/assign')->assertOk()
        ->assertJsonPath('data.assigned', 2)->assertJsonPath('data.kept_manual', 1)->assertJsonPath('data.unmatched', 0);

    expect(AccountTerritory::where('account_id', $inSwitzerland->id)->value('territory_id'))->toBe($swiss['id'])
        ->and(AccountTerritory::where('account_id', $elsewhere->id)->value('territory_id'))->toBe($catchAll['id'])
        ->and(AccountTerritory::where('account_id', $pinned->id)->value('territory_id'))->toBe($catchAll['id']);

    $this->getJson("/api/v1/crm/territories/{$swiss['id']}/accounts")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.account.name', $inSwitzerland->name);
});

test('a territory owner becomes the owner of unassigned accounts and other tenants cannot see territories', function (): void {
    $owner = User::create(['name' => 'Rep', 'email' => Str::uuid().'@example.com', 'password' => Str::random(32)]);
    $owner->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'sales']);
    $territory = $this->postJson('/api/v1/crm/territories', ['code' => 'IT', 'name' => 'Italy', 'owner_user_id' => $owner->id, 'criteria' => ['countries' => ['IT']]])->assertCreated()->json('data');
    $account = crmExtAccount('IT');

    $this->postJson('/api/v1/crm/territories/assign')->assertOk();
    expect($account->refresh()->owner_user_id)->toBe($owner->id);

    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other SpA']);
    $foreign = SalesTerritory::factory()->create(['tenant_id' => $other->id]);
    $this->getJson("/api/v1/crm/territories/{$foreign->id}")->assertNotFound();
    $this->getJson("/api/v1/crm/territories/{$territory['id']}")->assertOk();
});

test('quota attainment counts the opportunities won by the user in the period and territory', function (): void {
    $rep = User::create(['name' => 'Rep', 'email' => Str::uuid().'@example.com', 'password' => Str::random(32)]);
    $rep->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'sales']);
    $stage = OpportunityStage::factory()->create(['tenant_id' => $this->tenant->id, 'is_won' => true, 'code' => 'won']);
    $win = fn (string $amount, string $closed, ?string $owner = null, ?Account $account = null) => Opportunity::factory()->create([
        'tenant_id' => $this->tenant->id, 'account_id' => ($account ?? crmExtAccount('IT'))->id, 'stage_id' => $stage->id,
        'owner_user_id' => $owner ?? $rep->id, 'status' => 'won', 'amount' => $amount, 'actual_close_date' => $closed,
    ]);
    $win('30000.00', '2027-01-15');
    $win('20000.00', '2027-03-31');
    $win('99000.00', '2027-04-01');
    $win('50000.00', '2027-02-01', (string) Str::uuid7());

    $quota = $this->postJson('/api/v1/crm/sales-quotas', ['user_id' => $rep->id, 'period_start' => '2027-01-01', 'period_end' => '2027-03-31', 'target_amount' => '100000'])
        ->assertCreated()->json('data');

    $debug = $this->getJson("/api/v1/crm/sales-quotas/{$quota['id']}");
    expect($debug->status())->toBe(200, $debug->getContent());
    $debug
        ->assertJsonPath('data.attainment.achieved', '50000.00')
        ->assertJsonPath('data.attainment.remaining', '50000.00')
        ->assertJsonPath('data.attainment.attainment_percent', '50.00')
        ->assertJsonPath('data.attainment.won_count', 2);

    $territory = SalesTerritory::factory()->create(['tenant_id' => $this->tenant->id]);
    $scoped = $this->postJson('/api/v1/crm/sales-quotas', ['user_id' => $rep->id, 'territory_id' => $territory->id, 'period_start' => '2027-01-01', 'period_end' => '2027-03-31', 'target_amount' => '10000'])->assertCreated()->json('data');
    $this->getJson("/api/v1/crm/sales-quotas/{$scoped['id']}")->assertJsonPath('data.attainment.achieved', '0.00');

    $this->postJson('/api/v1/crm/sales-quotas', ['user_id' => $rep->id, 'period_start' => '2027-03-31', 'period_end' => '2027-01-01', 'target_amount' => '1'])
        ->assertUnprocessable()->assertJsonValidationErrors('period_end');
    $this->getJson('/api/v1/crm/sales-quotas?user_id='.$rep->id)->assertOk()->assertJsonCount(2, 'data');
});

test('read-only roles cannot write territories and partners', function (): void {
    crmExtActAs('accountant_readonly');
    $this->getJson('/api/v1/crm/territories')->assertOk();
    $this->postJson('/api/v1/crm/territories', ['code' => 'X', 'name' => 'X'])->assertForbidden();
    $this->postJson('/api/v1/crm/partners', ['name' => 'P', 'partner_type' => 'reseller', 'status' => 'active'])->assertForbidden();
});

test('partner tiers follow the revenue of won deal registrations of the last twelve months', function (): void {
    $silver = $this->postJson('/api/v1/crm/partner-tiers', ['code' => 'silver', 'name' => 'Silver', 'rank' => 1, 'min_annual_revenue' => 0, 'discount_percent' => 5, 'mdf_percent' => 1])->assertCreated()->json('data');
    $gold = $this->postJson('/api/v1/crm/partner-tiers', ['code' => 'gold', 'name' => 'Gold', 'rank' => 2, 'min_annual_revenue' => 50000, 'discount_percent' => 12, 'mdf_percent' => 3])->assertCreated()->json('data');
    $partner = $this->postJson('/api/v1/crm/partners', ['name' => 'Alpine Resellers', 'partner_type' => 'reseller', 'status' => 'active'])->assertCreated()->json('data');

    $this->postJson("/api/v1/crm/partners/{$partner['id']}/evaluate-tier")->assertOk()->assertJsonPath('data.tier_id', $silver['id']);

    DealRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'partner_id' => $partner['id'], 'status' => 'won', 'expected_amount' => '30000.00', 'registered_on' => now()->subMonths(2)->toDateString()]);
    DealRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'partner_id' => $partner['id'], 'status' => 'won', 'expected_amount' => '25000.00', 'registered_on' => now()->subMonths(5)->toDateString()]);
    DealRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'partner_id' => $partner['id'], 'status' => 'won', 'expected_amount' => '900000.00', 'registered_on' => now()->subMonths(18)->toDateString()]);

    $this->postJson('/api/v1/crm/partners/evaluate-tiers')->assertOk()->assertJsonPath('data.changed', 1);
    $this->getJson("/api/v1/crm/partners/{$partner['id']}")->assertJsonPath('data.tier_id', $gold['id'])->assertJsonPath('data.closed_revenue', '55000.00')->assertJsonPath('data.tier.code', 'gold');
    expect(PartnerTier::count())->toBe(2);
});

test('deal registration protects the first approved registration of a customer until it expires', function (): void {
    $first = Partner::factory()->create(['tenant_id' => $this->tenant->id]);
    $second = Partner::factory()->create(['tenant_id' => $this->tenant->id]);

    $one = $this->postJson('/api/v1/crm/deal-registrations', ['partner_id' => $first->id, 'customer_name' => 'Acme S.p.A.', 'expected_amount' => 40000, 'registered_on' => now()->subDays(10)->toDateString()])
        ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
    $this->postJson("/api/v1/crm/deal-registrations/{$one['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

    $two = $this->postJson('/api/v1/crm/deal-registrations', ['partner_id' => $second->id, 'customer_name' => 'ACME spa'])
        ->assertCreated()->assertJsonPath('data.conflicts_with_id', $one['id'])->json('data');
    $this->postJson("/api/v1/crm/deal-registrations/{$two['id']}/approve")->assertUnprocessable()->assertJsonValidationErrors('status');

    $this->postJson("/api/v1/crm/deal-registrations/{$two['id']}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/crm/deal-registrations/{$two['id']}/reject", ['reason' => 'Customer already covered'])->assertOk()->assertJsonPath('data.status', 'rejected');

    DealRegistration::query()->whereKey($one['id'])->update(['expires_on' => now()->subDay()->toDateString()]);
    $this->postJson('/api/v1/crm/deal-registrations/expire')->assertOk()->assertJsonPath('data.expired', 1);
    $this->getJson("/api/v1/crm/deal-registrations/{$one['id']}")->assertJsonPath('data.status', 'expired');

    $inactive = Partner::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'suspended']);
    $this->postJson('/api/v1/crm/deal-registrations', ['partner_id' => $inactive->id, 'customer_name' => 'Beta'])->assertUnprocessable()->assertJsonValidationErrors('partner_id');
});

test('only approvers decide deal registrations and a won registration needs a won opportunity', function (): void {
    $partner = Partner::factory()->create(['tenant_id' => $this->tenant->id]);
    $stage = OpportunityStage::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'qualified']);
    $opportunity = Opportunity::factory()->create(['tenant_id' => $this->tenant->id, 'account_id' => Account::factory()->create(['tenant_id' => $this->tenant->id])->id, 'stage_id' => $stage->id, 'status' => 'open']);
    $registration = $this->postJson('/api/v1/crm/deal-registrations', ['partner_id' => $partner->id, 'customer_name' => 'Gamma', 'opportunity_id' => $opportunity->id])->assertCreated()->json('data');

    crmExtActAs('sales');
    $this->postJson("/api/v1/crm/deal-registrations/{$registration['id']}/approve")->assertForbidden();

    crmExtActAs('admin');
    $this->postJson("/api/v1/crm/deal-registrations/{$registration['id']}/approve")->assertOk();
    $this->postJson("/api/v1/crm/deal-registrations/{$registration['id']}/won")->assertUnprocessable()->assertJsonValidationErrors('opportunity_id');
    $opportunity->forceFill(['status' => 'won'])->save();
    $this->postJson("/api/v1/crm/deal-registrations/{$registration['id']}/won")->assertOk()->assertJsonPath('data.status', 'won');
});

test('mdf funds accrue from the tier and claims never exceed what is left', function (): void {
    $tier = PartnerTier::factory()->create(['tenant_id' => $this->tenant->id, 'mdf_percent' => '5.00']);
    $partner = Partner::factory()->create(['tenant_id' => $this->tenant->id, 'tier_id' => $tier->id, 'closed_revenue' => '100000.00']);

    $fund = $this->postJson('/api/v1/crm/mdf-funds', ['partner_id' => $partner->id, 'fiscal_year' => 2027])->assertCreated()
        ->assertJsonPath('data.allocated_amount', '5000.00')->assertJsonPath('data.summary.remaining', '5000.00')->json('data');
    $this->postJson('/api/v1/crm/mdf-funds', ['partner_id' => $partner->id, 'fiscal_year' => 2027])->assertUnprocessable()->assertJsonValidationErrors('fiscal_year');

    $claim = $this->postJson("/api/v1/crm/mdf-funds/{$fund['id']}/claims", ['activity' => 'Trade fair booth', 'amount' => 3000])->assertCreated()->json('data');
    $this->postJson("/api/v1/crm/mdf-funds/{$fund['id']}/claims", ['activity' => 'Too much', 'amount' => 6000])->assertUnprocessable()->assertJsonValidationErrors('amount');

    crmExtActAs('sales');
    $this->postJson("/api/v1/crm/mdf-claims/{$claim['id']}/approve")->assertForbidden();

    crmExtActAs('sales_manager');
    $second = $this->postJson("/api/v1/crm/mdf-funds/{$fund['id']}/claims", ['activity' => 'Webinar', 'amount' => 2500])->assertCreated()->json('data');
    $this->postJson("/api/v1/crm/mdf-claims/{$claim['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson("/api/v1/crm/mdf-claims/{$second['id']}/approve")->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->postJson("/api/v1/crm/mdf-claims/{$second['id']}/reject", ['reason' => 'Fund exhausted'])->assertOk();
    $this->postJson("/api/v1/crm/mdf-claims/{$claim['id']}/pay")->assertOk()->assertJsonPath('data.status', 'paid');

    $this->getJson("/api/v1/crm/mdf-funds/{$fund['id']}")->assertJsonPath('data.summary.committed', '3000.00')->assertJsonPath('data.summary.remaining', '2000.00');
    $this->deleteJson("/api/v1/crm/mdf-funds/{$fund['id']}")->assertUnprocessable();
    expect(MdfFund::count())->toBe(1);
});
