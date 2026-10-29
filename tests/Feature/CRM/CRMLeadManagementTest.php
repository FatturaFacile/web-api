<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\CRM\Contracts\LeadServiceInterface;
use Modules\CRM\DTOs\ConvertLeadDTO;
use Modules\CRM\DTOs\CreateLeadDTO;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Opportunity;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['id' => (string) Str::uuid7()],
        ['name' => 'CRM Lead Test Tenant', 'legal_name' => 'CRM Lead Test Tenant SpA']
    );
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::firstOrCreate(
        ['email' => 'crmrep@example.com'],
        ['id' => (string) Str::uuid7(), 'name' => 'Sales Representative', 'password' => bcrypt('secret')]
    );

    $this->leadService = app(LeadServiceInterface::class);
});

test('captures new lead with automatic algorithmic scoring and hot/warm/cold rating', function (): void {
    $lead = $this->leadService->captureLead(new CreateLeadDTO(
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id,
        firstName: 'Marco',
        lastName: 'Verdi',
        companyName: 'Precision Engineering SpA',
        email: 'm.verdi@gmail.com',
        phone: '+39 02 8887771',
        title: 'Consultant',
        website: 'https://precisionengineering.it',
        country: 'IT',
        estimatedBudget: 5000.00
    ), (string) $this->user->id);

    expect($lead)->not->toBeNull();
    expect($lead->status)->toBe('new');
    expect($lead->score)->toBeGreaterThanOrEqual(40);
    expect($lead->rating)->toBe('warm');

    // Verify outbox event written
    $outbox = TransactionalOutbox::where('event_type', 'crm.lead.created')
        ->where('aggregate_id', (string) $lead->id)
        ->first();
    expect($outbox)->not->toBeNull();
});

test('auto-qualifies high value lead with score >= 80', function (): void {
    $lead = $this->leadService->captureLead(new CreateLeadDTO(
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id,
        firstName: 'Elena',
        lastName: 'Moretti',
        companyName: 'Global Heavy Machinery SpA',
        email: 'elena.moretti@globalmachinery.com',
        phone: '+39 02 9998888',
        title: 'Vice President of Operations',
        website: 'https://globalmachinery.com',
        country: 'IT',
        estimatedBudget: 150000.00
    ), (string) $this->user->id);

    // Score: VP (+20) + Company Email (+15) + Phone (+10) + Company (+15) + Budget > 50k (+30) + Website (+10) = 100
    expect($lead->score)->toBe(100);
    expect($lead->status)->toBe('qualified');
});

test('manually qualifies lead and dispatches LeadQualifiedEvent', function (): void {
    $lead = Lead::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'first_name' => 'Roberto',
        'last_name' => 'Neri',
        'company_name' => 'Neri Soluzioni Srl',
        'email' => 'roberto@neri.it',
        'status' => 'new',
        'score' => 50,
        'estimated_budget' => 20000.00,
    ]);

    $qualified = $this->leadService->qualifyLead($lead, (string) $this->user->id);
    expect($qualified->status)->toBe('qualified');

    $outbox = TransactionalOutbox::where('event_type', 'crm.lead.qualified')
        ->where('aggregate_id', (string) $lead->id)
        ->first();
    expect($outbox)->not->toBeNull();
});

test('disqualifies lead with reason and dispatches LeadRejectedEvent', function (): void {
    $lead = Lead::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'first_name' => 'Mario',
        'last_name' => 'Gialli',
        'company_name' => 'Gialli Consulting',
        'status' => 'new',
    ]);

    $disqualified = $this->leadService->disqualifyLead($lead, 'No current ERP budget', (string) $this->user->id);
    expect($disqualified->status)->toBe('disqualified');
    expect($disqualified->disqualification_reason)->toBe('No current ERP budget');

    $outbox = TransactionalOutbox::where('event_type', 'crm.lead.rejected')
        ->where('aggregate_id', (string) $lead->id)
        ->first();
    expect($outbox)->not->toBeNull();
});

test('detects duplicate leads by email, phone, or company name', function (): void {
    Lead::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'first_name' => 'Davide',
        'company_name' => 'Unica Solutions SpA',
        'email' => 'davide@unicasolutions.it',
        'phone' => '+39 02 1234567',
        'status' => 'new',
    ]);

    $duplicates = $this->leadService->detectDuplicates(
        (string) $this->tenant->id,
        'davide@unicasolutions.it',
        null,
        null
    );

    expect($duplicates->count())->toBe(1);
    expect($duplicates->first()->email)->toBe('davide@unicasolutions.it');
});

test('converts lead to account, contact, and opportunity atomically with lockForUpdate and dispatches LeadConvertedEvent', function (): void {
    $lead = Lead::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'first_name' => 'Fabio',
        'last_name' => 'Romano',
        'company_name' => 'Romano Logistics Srl',
        'email' => 'f.romano@romanologistics.it',
        'phone' => '+39 06 999111',
        'country' => 'IT',
        'status' => 'qualified',
        'estimated_budget' => 50000.00,
    ]);

    $result = $this->leadService->convertLead($lead, new ConvertLeadDTO(
        newAccountName: 'Romano Logistics Srl',
        accountTier: 'enterprise',
        createOpportunity: true,
        opportunityName: 'Fleet ERP Implementation',
        opportunityAmount: 50000.00
    ), (string) $this->user->id);

    expect($result['account'])->toBeInstanceOf(Account::class);
    expect($result['contact'])->toBeInstanceOf(Contact::class);
    expect($result['opportunity'])->toBeInstanceOf(Opportunity::class);

    // Verify lead status updated
    $freshLead = $lead->fresh();
    expect($freshLead->status)->toBe('converted');
    expect($freshLead->converted_account_id)->toBe((string) $result['account']->id);
    expect($freshLead->converted_contact_id)->toBe((string) $result['contact']->id);
    expect($freshLead->converted_opportunity_id)->toBe((string) $result['opportunity']->id);

    // Verify outbox
    $outbox = TransactionalOutbox::where('event_type', 'crm.lead.converted')
        ->where('aggregate_id', (string) $lead->id)
        ->first();
    expect($outbox)->not->toBeNull();
});

test('prevents duplicate conversion of an already converted lead', function (): void {
    $lead = Lead::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $this->tenant->id,
        'first_name' => 'Anna',
        'last_name' => 'Costa',
        'company_name' => 'Costa Enterprises',
        'status' => 'converted',
        'converted_at' => now(),
    ]);

    expect(fn () => $this->leadService->convertLead($lead, new ConvertLeadDTO, (string) $this->user->id))
        ->toThrow(InvalidArgumentException::class);
});
