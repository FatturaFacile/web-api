<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Support\Str;
use Modules\CRM\Contracts\CustomerHealthServiceInterface;
use Modules\CRM\DTOs\EvaluateHealthDTO;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;
use Modules\Invoicing\Events\InvoicePostedEvent;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'CRM Health Test Tenant', 'legal_name' => 'CRM Health Test Tenant SpA']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->contact = Contact::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'company_name' => 'Nordic BioTech AB',
        'type' => 'client',
        'country' => 'IT',
    ]);

    $this->account = Account::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'contact_id' => (string) $this->contact->id,
        'account_number' => 'ACC-BIO-01',
        'name' => 'Nordic BioTech AB',
        'tier' => 'enterprise',
        'status' => 'active',
        'customer_health_index' => 100.00,
        'churn_risk' => 'low',
        'last_activity_at' => now(),
    ]);

    $this->healthService = app(CustomerHealthServiceInterface::class);
});

test('evaluates healthy customer with recent activities and low overdue receivables', function (): void {
    $stage = OpportunityStage::firstOrCreate(
        ['tenant_id' => (string) $this->tenant->id, 'code' => 'closed_won'],
        [
            'id' => (string) Str::uuid7(),
            'name' => 'Closed Won',
            'sequence' => 50,
            'default_probability' => 100.0,
            'is_won' => true,
            'is_active' => true,
        ]
    );

    Opportunity::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'account_id' => (string) $this->account->id,
        'stage_id' => (string) $stage->id,
        'opportunity_number' => 'OPP-HLT-001',
        'name' => 'BioTech Automation Deal',
        'amount' => 50000.00,
        'expected_revenue' => 50000.00,
        'currency' => 'EUR',
        'status' => 'won',
        'expected_close_date' => date('Y-m-d'),
    ]);

    for ($i = 0; $i < 3; $i++) {
        Activity::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $this->tenant->id,
            'subject' => "Customer Review {$i}",
            'type' => 'meeting',
            'status' => 'completed',
            'related_type' => 'account',
            'related_id' => (string) $this->account->id,
            'completed_at' => now()->subDays(2),
        ]);
    }

    $profile = $this->healthService->evaluateHealth($this->account, new EvaluateHealthDTO(
        accountId: (string) $this->account->id,
        openReceivableAmount: 5000.00,
        overdueReceivableAmount: 0.00,
        completedActivitiesCount: 5,
        daysSinceLastInteraction: 2
    ));

    expect($profile)->not->toBeNull();
    expect((float) $profile->health_index)->toBeGreaterThanOrEqual(70.00);
    expect(in_array($profile->churn_risk_indicator, ['low', 'medium'], true))->toBeTrue();

    // Verify account synced
    expect((float) $this->account->fresh()->customer_health_index)->toBe((float) $profile->health_index);
    expect(in_array($this->account->fresh()->churn_risk, ['low', 'medium'], true))->toBeTrue();

    // Verify outbox
    $outbox = TransactionalOutbox::where('event_type', 'crm.customer_health.changed')
        ->where('aggregate_id', (string) $this->account->id)
        ->first();
    expect($outbox)->not->toBeNull();
});

test('detects high churn risk when customer is inactive and has high overdue receivables', function (): void {
    $profile = $this->healthService->evaluateHealth($this->account, new EvaluateHealthDTO(
        accountId: (string) $this->account->id,
        openReceivableAmount: 30000.00,
        overdueReceivableAmount: 25000.00,
        completedActivitiesCount: 0,
        daysSinceLastInteraction: 120
    ));

    expect((float) $profile->risk_score)->toBeGreaterThanOrEqual(75.00);
    expect($profile->churn_risk_indicator)->toBe('critical');
    expect((float) $profile->health_index)->toBeLessThan(60.00);
    expect($this->account->fresh()->churn_risk)->toBe('critical');
});

test('recalculates health when invoice is posted via InvoicePostedEvent', function (): void {
    $event = new InvoicePostedEvent(
        invoiceId: (string) Str::uuid7(),
        invoiceNumber: 'INV-CRM-001',
        customerId: (string) $this->contact->id,
        date: date('Y-m-d'),
        dueDate: date('Y-m-d', strtotime('+30 days')),
        currency: 'EUR',
        exchangeRate: 1.0,
        totalNet: 10000.00,
        totalTax: 2200.00,
        totalGross: 12200.00,
        revenueLines: [],
        taxLines: [],
        glJournalEntryId: null,
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    );

    event($event);

    $freshAccount = $this->account->fresh();
    expect($freshAccount->healthProfile)->not->toBeNull();
    expect((float) $freshAccount->healthProfile->open_receivable_amount)->toBe(12200.00);
});
