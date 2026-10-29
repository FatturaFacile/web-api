<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Support\Str;
use Modules\CRM\Contracts\OpportunityServiceInterface;
use Modules\CRM\DTOs\CreateOpportunityDTO;
use Modules\CRM\DTOs\UpdateOpportunityStageDTO;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;
use Modules\Invoicing\Models\SalesQuote;
use Modules\Projects\Models\Project;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'CRM Opp Test Tenant', 'legal_name' => 'CRM Opp Test Tenant SpA']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::firstOrCreate(
        ['email' => 'oppmgr@example.com'],
        ['id' => (string) Str::uuid7(), 'name' => 'Opportunity Manager', 'password' => bcrypt('secret')]
    );

    $this->account = Account::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'name' => 'Industrial Robotics SpA',
        'account_number' => 'ACC-ROB-01',
        'tier' => 'enterprise',
        'status' => 'active',
        'customer_health_index' => 95.00,
    ]);

    $this->contact = Contact::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'company_name' => $this->account->name,
        'first_name' => 'Paolo',
        'last_name' => 'Galli',
        'email' => 'paolo.galli@industrialrobotics.it',
        'type' => 'client',
        'country' => 'IT',
    ]);

    $this->account->update(['contact_id' => $this->contact->id]);

    $this->stageDiscovery = OpportunityStage::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'code' => 'discovery'],
        [
            'id' => (string) Str::uuid7(),
            'name' => 'Discovery',
            'sequence' => 10,
            'default_probability' => 20.00,
            'is_won' => false,
            'is_lost' => false,
            'is_active' => true,
        ]
    );

    $this->stageProposal = OpportunityStage::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'code' => 'proposal'],
        [
            'id' => (string) Str::uuid7(),
            'name' => 'Proposal',
            'sequence' => 30,
            'default_probability' => 60.00,
            'is_won' => false,
            'is_lost' => false,
            'is_active' => true,
        ]
    );

    $this->stageWon = OpportunityStage::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'code' => 'closed_won'],
        [
            'id' => (string) Str::uuid7(),
            'name' => 'Closed Won',
            'sequence' => 50,
            'default_probability' => 100.00,
            'is_won' => true,
            'is_lost' => false,
            'is_active' => true,
        ]
    );

    $this->stageLost = OpportunityStage::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'code' => 'closed_lost'],
        [
            'id' => (string) Str::uuid7(),
            'name' => 'Closed Lost',
            'sequence' => 60,
            'default_probability' => 0.00,
            'is_won' => false,
            'is_lost' => true,
            'is_active' => true,
        ]
    );

    $this->opportunityService = app(OpportunityServiceInterface::class);
});

test('creates opportunity with expected revenue calculation and line items', function (): void {
    $opp = $this->opportunityService->createOpportunity(new CreateOpportunityDTO(
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id,
        accountId: (string) $this->account->id,
        contactId: (string) $this->contact->id,
        name: 'Warehouse Automation Core',
        amount: 80000.00,
        currency: 'EUR',
        stageCode: 'discovery',
        expectedCloseDate: '2027-04-30',
        lineItems: [
            ['description' => 'Autonomous Guided Vehicle Server', 'quantity' => 2.0, 'unit_price' => 30000.00, 'discount_percent' => 0.0],
            ['description' => 'System Integration License', 'quantity' => 1.0, 'unit_price' => 20000.00, 'discount_percent' => 0.0],
        ]
    ), (string) $this->user->id);

    expect($opp)->not->toBeNull();
    expect((float) $opp->amount)->toBe(80000.00);
    expect((float) $opp->probability)->toBe(20.00);
    // Expected revenue = 80000 * 0.20 = 16000
    expect((float) $opp->expected_revenue)->toBe(16000.00);
    expect($opp->lineItems->count())->toBe(2);

    $outbox = TransactionalOutbox::where('event_type', 'crm.opportunity.created')
        ->where('aggregate_id', (string) $opp->id)
        ->first();
    expect($outbox)->not->toBeNull();
});

test('advances stage to closed_won and dispatches OpportunityWonEvent', function (): void {
    $opp = Opportunity::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'account_id' => (string) $this->account->id,
        'contact_id' => (string) $this->contact->id,
        'stage_id' => (string) $this->stageProposal->id,
        'opportunity_number' => 'OPP-ADV-001',
        'name' => 'Plant Modernization',
        'amount' => 50000.00,
        'expected_revenue' => 30000.00,
        'currency' => 'EUR',
        'probability' => 60.00,
        'expected_close_date' => date('Y-m-d'),
        'status' => 'open',
    ]);

    $updated = $this->opportunityService->changeStage(
        $opp,
        new UpdateOpportunityStageDTO(targetStageCode: 'closed_won'),
        (string) $this->user->id
    );

    expect($updated->status)->toBe('won');
    expect((float) $updated->probability)->toBe(100.00);
    expect((float) $updated->expected_revenue)->toBe(50000.00);
    expect($updated->actual_close_date)->not->toBeNull();

    $outbox = TransactionalOutbox::where('event_type', 'crm.opportunity.won')
        ->where('aggregate_id', (string) $opp->id)
        ->first();
    expect($outbox)->not->toBeNull();
});

test('advances stage to closed_lost and dispatches OpportunityLostEvent', function (): void {
    $opp = Opportunity::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'account_id' => (string) $this->account->id,
        'stage_id' => (string) $this->stageProposal->id,
        'opportunity_number' => 'OPP-LOST-001',
        'name' => 'Failed Tender',
        'amount' => 40000.00,
        'expected_revenue' => 24000.00,
        'currency' => 'EUR',
        'probability' => 60.00,
        'expected_close_date' => date('Y-m-d'),
        'status' => 'open',
    ]);

    $updated = $this->opportunityService->changeStage(
        $opp,
        new UpdateOpportunityStageDTO(
            targetStageCode: 'closed_lost',
            winLossReason: 'Competitor undercut by 25%',
            competitor: 'Rival ERP Inc'
        ),
        (string) $this->user->id
    );

    expect($updated->status)->toBe('lost');
    expect((float) $updated->probability)->toBe(0.00);
    expect((float) $updated->expected_revenue)->toBe(0.00);
    expect($updated->win_loss_reason)->toBe('Competitor undercut by 25%');

    $outbox = TransactionalOutbox::where('event_type', 'crm.opportunity.lost')
        ->where('aggregate_id', (string) $opp->id)
        ->first();
    expect($outbox)->not->toBeNull();
});

test('requestQuote creates SalesQuote in Module 05 and links sales_quote_id', function (): void {
    $opp = Opportunity::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'account_id' => (string) $this->account->id,
        'contact_id' => (string) $this->contact->id,
        'stage_id' => (string) $this->stageProposal->id,
        'opportunity_number' => 'OPP-QUOTE-001',
        'name' => 'Consulting & Implementation Package',
        'amount' => 25000.00,
        'expected_revenue' => 15000.00,
        'currency' => 'EUR',
        'probability' => 60.00,
        'expected_close_date' => date('Y-m-d', strtotime('+30 days')),
        'status' => 'open',
    ]);

    $quote = $this->opportunityService->requestQuote($opp, (string) $this->user->id);

    expect($quote)->toBeInstanceOf(SalesQuote::class);
    expect($opp->fresh()->sales_quote_id)->toBe((string) $quote->id);

    $outbox = TransactionalOutbox::where('event_type', 'crm.quote.requested')
        ->where('aggregate_id', (string) $opp->id)
        ->first();
    expect($outbox)->not->toBeNull();
});

test('convertToProject creates Project in Module 15 and links project_id', function (): void {
    $opp = Opportunity::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'account_id' => (string) $this->account->id,
        'contact_id' => (string) $this->contact->id,
        'stage_id' => (string) $this->stageWon->id,
        'opportunity_number' => 'OPP-PRJ-001',
        'name' => 'Automated Warehouse Implementation',
        'amount' => 120000.00,
        'expected_revenue' => 120000.00,
        'currency' => 'EUR',
        'probability' => 100.00,
        'expected_close_date' => date('Y-m-d', strtotime('+90 days')),
        'status' => 'won',
    ]);

    $result = $this->opportunityService->convertToProject($opp, (string) $this->user->id);

    expect($result['project_id'])->not->toBeEmpty();
    expect($opp->fresh()->project_id)->toBe($result['project_id']);

    $project = Project::find($result['project_id']);
    expect($project)->not->toBeNull();
    expect((float) $project->contract_value)->toBe(120000.00);

    $outbox = TransactionalOutbox::where('event_type', 'crm.project_opportunity.created')
        ->where('aggregate_id', (string) $opp->id)
        ->first();
    expect($outbox)->not->toBeNull();
});

test('identifies workflow requirement for high value opportunity and large discount', function (): void {
    $highValOpp = Opportunity::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'account_id' => (string) $this->account->id,
        'stage_id' => (string) $this->stageDiscovery->id,
        'opportunity_number' => 'OPP-WF-001',
        'name' => 'Mega Enterprise Deal',
        'amount' => 150000.00,
        'discount_percentage' => 5.0,
        'expected_close_date' => date('Y-m-d'),
    ]);

    $reqHighVal = $this->opportunityService->checkWorkflowRequirement($highValOpp, 'discovery');
    expect($reqHighVal)->toBe('HIGH_VALUE_OPPORTUNITY_APPROVAL');

    $discountOpp = Opportunity::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'account_id' => (string) $this->account->id,
        'stage_id' => (string) $this->stageDiscovery->id,
        'opportunity_number' => 'OPP-WF-002',
        'name' => 'Discounted Standard Deal',
        'amount' => 20000.00,
        'discount_percentage' => 25.0,
        'expected_close_date' => date('Y-m-d'),
    ]);

    $reqDiscount = $this->opportunityService->checkWorkflowRequirement($discountOpp, 'discovery');
    expect($reqDiscount)->toBe('LARGE_DISCOUNT_APPROVAL');
});
