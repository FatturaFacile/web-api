<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\PartyLinkLog;
use Modules\CRM\Services\PartyMaster\AccountContactBackfill;
use Modules\CRM\Services\PartyMaster\PartyKeyNormalizer;

uses(RefreshDatabase::class);

/**
 * The contact is the single party master: CRM accounts reference it (backfilled for existing data), account
 * endpoints read and write master data through it, and lead conversion links existing contacts.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Party Master Tenant', 'legal_name' => 'Party Master Tenant SpA']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::create(['name' => 'Sales Admin', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    $this->actingAs($this->user);
    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id);

    $this->insertAccount = function (array $attributes, ?string $tenantId = null): string {
        $id = (string) Str::uuid7();
        DB::table('crm_accounts')->insert([
            'id' => $id,
            'tenant_id' => $tenantId ?? $this->tenant->id,
            'account_number' => 'ACC-'.strtoupper(Str::random(6)),
            'name' => $attributes['name'],
            'metadata' => isset($attributes['metadata']) ? json_encode($attributes['metadata']) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    };
});

test('normalises party keys so formatting differences still match', function (): void {
    expect(PartyKeyNormalizer::vat('IT 01234567890'))->toBe('01234567890')
        ->and(PartyKeyNormalizer::vat('01234567890'))->toBe('01234567890')
        ->and(PartyKeyNormalizer::vat('CHE-123.456.789'))->toBe('CHE123456789')
        ->and(PartyKeyNormalizer::email('  Mario.Rossi@Example.COM '))->toBe('mario.rossi@example.com')
        ->and(PartyKeyNormalizer::phone('+39 02 1234567'))->toBe(PartyKeyNormalizer::phone('02 1234567'))
        ->and(PartyKeyNormalizer::phone('0039 02 1234567'))->toBe('021234567')
        ->and(PartyKeyNormalizer::fiscalCode('rssmra85m01h501q'))->toBe('RSSMRA85M01H501Q')
        ->and(PartyKeyNormalizer::name('Acme S.r.l.'))->toBe(PartyKeyNormalizer::name('ACME srl'));
});

test('backfill links accounts by VAT, primary role or name, creates missing contacts and logs ambiguous ones', function (): void {
    $byVat = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'company_name' => 'Different Name SpA', 'vat_number' => 'IT01234567890']);
    $byName = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'company_name' => 'Rossi Impianti S.r.l.', 'vat_number' => null, 'email' => 'info@rossi-impianti.it']);
    Contact::factory()->count(2)->create(['tenant_id' => $this->tenant->id, 'company_name' => 'Twin Corp', 'vat_number' => null]);
    $roleContact = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'company_name' => 'Role Person', 'vat_number' => null]);

    $vatAccount = ($this->insertAccount)(['name' => 'Totally Other Label', 'metadata' => ['vat_number' => '01234567890']]);
    $nameAccount = ($this->insertAccount)(['name' => 'ROSSI IMPIANTI SRL']);
    $ambiguousAccount = ($this->insertAccount)(['name' => 'Twin Corp']);
    $roleAccount = ($this->insertAccount)(['name' => 'No Match By Name Ltd']);
    DB::table('crm_contact_roles')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'account_id' => $roleAccount, 'contact_id' => $roleContact->id, 'role' => 'decision_maker', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
    $newAccount = ($this->insertAccount)(['name' => 'Brand New Customer SA', 'metadata' => ['email' => 'Hello@BrandNew.ch', 'country' => 'CH']]);

    $stats = app(AccountContactBackfill::class)->run();

    expect($stats)->toBe(['linked' => 3, 'created' => 1, 'ambiguous' => 1])
        ->and(DB::table('crm_accounts')->where('id', $vatAccount)->value('contact_id'))->toBe($byVat->id)
        ->and(DB::table('crm_accounts')->where('id', $nameAccount)->value('contact_id'))->toBe($byName->id)
        ->and(DB::table('crm_accounts')->where('id', $roleAccount)->value('contact_id'))->toBe($roleContact->id)
        ->and(DB::table('crm_accounts')->where('id', $ambiguousAccount)->value('contact_id'))->toBeNull();

    $created = Contact::find(DB::table('crm_accounts')->where('id', $newAccount)->value('contact_id'));
    expect($created->company_name)->toBe('Brand New Customer SA')
        ->and($created->email)->toBe('hello@brandnew.ch')
        ->and($created->country)->toBe('CH');

    $ambiguousLog = PartyLinkLog::where('account_id', $ambiguousAccount)->first();
    expect($ambiguousLog->outcome)->toBe('ambiguous')
        ->and($ambiguousLog->candidate_contact_ids)->toHaveCount(2)
        ->and(PartyLinkLog::where('account_id', $vatAccount)->value('matched_on'))->toBe('vat_number');
});

test('backfill is idempotent and never crosses tenants', function (): void {
    $otherTenant = Tenant::create(['name' => 'Other', 'legal_name' => 'Other SpA']);
    $foreignContact = app(TenantContext::class)->runWithTenant($otherTenant, fn () => Contact::factory()->create(['company_name' => 'Shared Name Srl', 'vat_number' => null]));
    $account = ($this->insertAccount)(['name' => 'Shared Name Srl']);
    $ambiguous = ($this->insertAccount)(['name' => 'Dup Name']);
    Contact::factory()->count(2)->create(['tenant_id' => $this->tenant->id, 'company_name' => 'Dup Name', 'vat_number' => null]);

    $first = app(AccountContactBackfill::class)->run();
    $contactsAfterFirst = DB::table('contacts')->count();
    $linkedTo = DB::table('crm_accounts')->where('id', $account)->value('contact_id');

    $second = app(AccountContactBackfill::class)->run();

    expect($first)->toBe(['linked' => 0, 'created' => 1, 'ambiguous' => 1])
        ->and($linkedTo)->not->toBe($foreignContact->id)
        ->and($second)->toBe(['linked' => 0, 'created' => 0, 'ambiguous' => 1])
        ->and(DB::table('contacts')->count())->toBe($contactsAfterFirst)
        ->and(DB::table('crm_accounts')->where('id', $account)->value('contact_id'))->toBe($linkedTo)
        ->and(PartyLinkLog::where('account_id', $ambiguous)->count())->toBe(1);
});

test('the consolidation migration can run again without changing anything', function (): void {
    ($this->insertAccount)(['name' => 'Migrated Customer Srl']);
    $migration = require base_path('database/migrations/2027_04_12_000100_link_crm_accounts_to_party_master.php');

    $migration->up();
    $linked = DB::table('crm_accounts')->whereNotNull('contact_id')->count();
    $contacts = DB::table('contacts')->count();
    $migration->up();

    expect($linked)->toBe(1)
        ->and(DB::table('crm_accounts')->whereNotNull('contact_id')->count())->toBe(1)
        ->and(DB::table('contacts')->count())->toBe($contacts);

    $engagement = require base_path('database/migrations/2027_04_12_000200_create_crm_engagement_tables.php');
    $engagement->up();
    expect(true)->toBeTrue();
});

test('creating an account reuses the contact with the same VAT number and exposes its master data', function (): void {
    $existing = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bianchi Logistica SpA', 'vat_number' => '09876543210', 'sdi_code' => 'ABC1234']);

    $response = $this->postJson('/api/v1/crm/accounts', ['name' => 'Bianchi Logistica', 'vat_number' => 'IT09876543210', 'tier' => 'enterprise']);

    $response->assertCreated()
        ->assertJsonPath('contact_id', $existing->id)
        ->assertJsonPath('contact.sdi_code', 'ABC1234')
        ->assertJsonPath('name', 'Bianchi Logistica SpA');
    expect(Contact::where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

test('creating an account without a match creates its contact; updates write master data to the contact', function (): void {
    $response = $this->postJson('/api/v1/crm/accounts', ['name' => 'Verdi Meccanica Srl', 'email' => 'Info@VerdiMeccanica.it', 'country' => 'IT']);
    $response->assertCreated();
    $contactId = $response->json('contact_id');

    expect(Contact::find($contactId)->company_name)->toBe('Verdi Meccanica Srl');

    $this->putJson('/api/v1/crm/accounts/'.$response->json('id'), ['name' => 'Verdi Meccanica SpA', 'vat_number' => 'IT11122233344', 'tier' => 'smb'])
        ->assertOk()
        ->assertJsonPath('contact.vat_number', 'IT11122233344')
        ->assertJsonPath('name', 'Verdi Meccanica SpA')
        ->assertJsonPath('tier', 'smb');

    app(TenantContext::class)->setTenant($this->tenant);
    $contact = Contact::find($contactId);
    expect($contact->company_name)->toBe('Verdi Meccanica SpA')
        ->and($contact->vat_number)->toBe('IT11122233344')
        ->and(Account::find($response->json('id'))->name)->toBe('Verdi Meccanica SpA');
});

test('an account cannot be linked to a contact of another tenant', function (): void {
    $otherTenant = Tenant::create(['name' => 'Other', 'legal_name' => 'Other SpA']);
    $foreign = app(TenantContext::class)->runWithTenant($otherTenant, fn () => Contact::factory()->create());

    $this->postJson('/api/v1/crm/accounts', ['contact_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('contact_id');
});

test('ambiguous decisions are listed and resolved by relinking the account', function (): void {
    $candidates = Contact::factory()->count(2)->create(['tenant_id' => $this->tenant->id, 'company_name' => 'Same Name Srl', 'vat_number' => null]);
    $account = ($this->insertAccount)(['name' => 'Same Name Srl']);
    app(AccountContactBackfill::class)->run();

    $this->getJson('/api/v1/crm/accounts/party-links?outcome=ambiguous')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.account_id', $account);

    $this->putJson("/api/v1/crm/accounts/{$account}", ['contact_id' => $candidates[1]->id])
        ->assertOk()
        ->assertJsonPath('contact_id', $candidates[1]->id);

    app(TenantContext::class)->setTenant($this->tenant);
    expect(PartyLinkLog::where('account_id', $account)->value('outcome'))->toBe('resolved');
});

test('lead conversion links the existing contact with the same email instead of duplicating it', function (): void {
    $existing = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'giulia@ferrari-arredi.it', 'company_name' => 'Ferrari Arredi Srl']);
    $lead = Lead::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'first_name' => 'Giulia', 'last_name' => 'Ferrari', 'company_name' => 'Ferrari Arredi', 'email' => 'GIULIA@ferrari-arredi.it', 'status' => 'qualified']);

    $response = $this->postJson("/api/v1/crm/leads/{$lead->id}/convert", ['create_opportunity' => false]);

    $response->assertOk()
        ->assertJsonPath('contact_id', $existing->id)
        ->assertJsonPath('contact_linked', true)
        ->assertJsonPath('contact.company_name', 'Ferrari Arredi Srl')
        ->assertJsonPath('account.contact_id', $existing->id);
    expect(Contact::where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

test('lead conversion accepts an explicit contact of the tenant only', function (): void {
    $lead = Lead::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'first_name' => 'Ugo', 'company_name' => 'Ugo Srl', 'status' => 'qualified']);
    $mine = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherTenant = Tenant::create(['name' => 'Other', 'legal_name' => 'Other SpA']);
    $foreign = app(TenantContext::class)->runWithTenant($otherTenant, fn () => Contact::factory()->create());

    $this->postJson("/api/v1/crm/leads/{$lead->id}/convert", ['existing_contact_id' => $foreign->id])->assertUnprocessable();

    $this->postJson("/api/v1/crm/leads/{$lead->id}/convert", ['existing_contact_id' => $mine->id, 'create_opportunity' => false])
        ->assertOk()
        ->assertJsonPath('contact_id', $mine->id)
        ->assertJsonPath('contact_linked', true);
});
