<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\CRM\Contracts\LeadServiceInterface;
use Modules\CRM\DTOs\ConvertLeadDTO;
use Modules\CRM\Exceptions\LeadAlreadyConvertedException;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\ContactRole;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Opportunity;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Conversion', 'legal_name' => 'Conversion Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::create(['name' => 'Closer', 'email' => Str::lower(Str::random(12)).'@conversion.test', 'password' => bcrypt('secret')]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    Sanctum::actingAs($this->user);

    $this->makeLead = fn (array $attributes = []): Lead => Lead::create(array_merge([
        'tenant_id' => (string) $this->tenant->id,
        'first_name' => 'Pietro',
        'last_name' => 'Galli',
        'company_name' => 'Galli Meccanica Srl',
        'email' => 'pietro@galli-meccanica.it',
        'phone' => '+39 035 123456',
        'vat_number' => 'IT09876543210',
        'industry' => 'Manufacturing',
        'employee_count' => 85,
        'annual_revenue_estimate' => 12000000,
        'country' => 'IT',
        'status' => 'qualified',
        'estimated_budget' => 40000,
    ], $attributes));
});

test('converts a lead into contact, account and opportunity in one call and returns 409 the second time', function (): void {
    $lead = ($this->makeLead)();

    $response = $this->postJson("/api/v1/crm/leads/{$lead->id}/convert", [
        'create_opportunity' => true,
        'opportunity_name' => 'MES rollout',
        'opportunity_amount' => 40000,
    ])->assertOk()
        ->assertJsonStructure(['account' => ['id', 'name'], 'contact_id', 'opportunity' => ['id']]);

    app(TenantContext::class)->setTenant($this->tenant);
    $fresh = $lead->fresh();
    $account = Account::findOrFail($response->json('account.id'));
    $contact = Contact::findOrFail($response->json('contact_id'));

    expect($fresh->status)->toBe('converted')
        ->and($fresh->converted_account_id)->toBe((string) $account->id)
        ->and($fresh->converted_contact_id)->toBe((string) $contact->id)
        ->and($fresh->converted_opportunity_id)->toBe($response->json('opportunity.id'))
        ->and($account->name)->toBe('Galli Meccanica Srl')
        ->and($account->industry)->toBe('Manufacturing')
        ->and($account->employee_count)->toBe(85)
        ->and((float) $account->annual_revenue)->toBe(12000000.0)
        ->and($contact->vat_number)->toBe('IT09876543210')
        ->and(ContactRole::where('account_id', $account->id)->where('contact_id', $contact->id)->count())->toBe(1);

    $this->postJson("/api/v1/crm/leads/{$lead->id}/convert", [])
        ->assertStatus(409)
        ->assertJsonStructure(['message']);

    app(TenantContext::class)->setTenant($this->tenant);
    expect(Account::count())->toBe(1)
        ->and(Opportunity::count())->toBe(1);
});

test('reuses the contact and account matching the lead VAT number', function (): void {
    $contact = Contact::create([
        'tenant_id' => (string) $this->tenant->id,
        'type' => 'client',
        'country' => 'IT',
        'company_name' => 'Galli Meccanica',
        'vat_number' => '09876543210',
    ]);
    $account = Account::create([
        'tenant_id' => (string) $this->tenant->id,
        'account_number' => 'ACC-GALLI',
        'name' => 'Galli Meccanica',
        'contact_id' => $contact->id,
    ]);
    $lead = ($this->makeLead)();

    $this->postJson("/api/v1/crm/leads/{$lead->id}/convert", ['create_opportunity' => false])
        ->assertOk()
        ->assertJsonPath('account.id', (string) $account->id)
        ->assertJsonPath('contact_id', (string) $contact->id)
        ->assertJsonPath('opportunity', null);

    app(TenantContext::class)->setTenant($this->tenant);

    expect(Contact::count())->toBe(1)
        ->and(Account::count())->toBe(1)
        ->and($contact->fresh()->email)->toBe('pietro@galli-meccanica.it');
});

test('links an explicitly chosen existing contact', function (): void {
    $contact = Contact::create(['tenant_id' => (string) $this->tenant->id, 'type' => 'client', 'country' => 'IT', 'company_name' => 'Someone Else', 'email' => 'other@else.it']);
    $lead = ($this->makeLead)(['vat_number' => null, 'email' => 'nobody@nowhere.it']);

    $this->postJson("/api/v1/crm/leads/{$lead->id}/convert", ['existing_contact_id' => (string) $contact->id, 'create_opportunity' => false])
        ->assertOk()
        ->assertJsonPath('contact_id', (string) $contact->id);

    $this->postJson('/api/v1/crm/leads/'.(($this->makeLead)(['email' => 'x@y.it', 'vat_number' => null]))->id.'/convert', ['existing_contact_id' => 'not-a-uuid'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['existing_contact_id']);
});

test('rolls back the whole conversion when creating the opportunity fails', function (): void {
    $lead = ($this->makeLead)(['email' => 'rollback@galli.it', 'vat_number' => 'IT11111111111']);
    Opportunity::creating(function (): void {
        throw new RuntimeException('Simulated failure while creating the opportunity');
    });

    $this->postJson("/api/v1/crm/leads/{$lead->id}/convert", ['create_opportunity' => true])->assertStatus(500);

    app(TenantContext::class)->setTenant($this->tenant);

    expect($lead->fresh()->status)->toBe('qualified')
        ->and($lead->fresh()->converted_contact_id)->toBeNull()
        ->and(Contact::where('email', 'rollback@galli.it')->exists())->toBeFalse()
        ->and(Account::count())->toBe(0)
        ->and(ContactRole::count())->toBe(0);
});

test('the service throws LeadAlreadyConvertedException which stays an InvalidArgumentException', function (): void {
    $lead = ($this->makeLead)(['status' => 'converted']);

    expect(fn () => app(LeadServiceInterface::class)->convertLead($lead, new ConvertLeadDTO, (string) $this->user->id))
        ->toThrow(LeadAlreadyConvertedException::class);

    expect(new LeadAlreadyConvertedException('x'))->toBeInstanceOf(\InvalidArgumentException::class);
});

test('cannot convert a lead of another tenant', function (): void {
    $otherTenant = Tenant::create(['name' => 'Other Conversion', 'legal_name' => 'Other Conversion Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($otherTenant);
    $foreign = Lead::create(['tenant_id' => (string) $otherTenant->id, 'email' => 'foreign@lead.it', 'status' => 'qualified']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->postJson("/api/v1/crm/leads/{$foreign->id}/convert", [])->assertNotFound();
});
