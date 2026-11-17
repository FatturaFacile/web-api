<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\ContactAddress;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Invoicing\Models\CustomerCreditProfile;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'FatturaFacile Italy SRL', 'legal_name' => 'FatturaFacile Italy SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);
});

function registry()
{
    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

function makeCustomer(array $attributes = []): Contact
{
    return Contact::create([
        'tenant_id' => test()->tenant->id,
        'type' => 'client',
        'country' => 'IT',
        'company_name' => 'Acme SRL',
        ...$attributes,
    ]);
}

// ---------------------------------------------------------------------------------------------------------------
// Fiscal validation blocks saving
// ---------------------------------------------------------------------------------------------------------------

test('saving an italian customer with a wrong partita iva or codice fiscale is blocked', function (): void {
    registry()->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'IT', 'company_name' => 'X', 'vat_number' => '01234567890'])
        ->assertUnprocessable()->assertJsonValidationErrors('vat_number');

    registry()->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'IT', 'company_name' => 'X', 'fiscal_code' => 'RSSMRA85T10A562X'])
        ->assertUnprocessable()->assertJsonValidationErrors('fiscal_code');

    expect(Contact::count())->toBe(0);
});

test('a valid italian customer is saved with normalised identifiers and a formally checked sdi code', function (): void {
    registry()->postJson('/api/v1/crm/contacts', [
        'type' => 'client', 'country' => 'IT', 'company_name' => 'Acme SRL',
        'vat_number' => 'it 01234567897', 'fiscal_code' => 'rssmra85t10a562s', 'sdi_code' => 'm5uxcr1', 'pec' => 'acme@pec.it',
    ])->assertCreated()
        ->assertJsonPath('data.vat_number', 'IT01234567897')
        ->assertJsonPath('data.fiscal_code', 'RSSMRA85T10A562S')
        ->assertJsonPath('data.sdi_code', 'M5UXCR1');

    registry()->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'IT', 'company_name' => 'Y', 'sdi_code' => 'M5UX-R1'])
        ->assertUnprocessable()->assertJsonValidationErrors('sdi_code');

    registry()->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'IT', 'company_name' => 'Z', 'pec' => 'not-an-email'])
        ->assertUnprocessable()->assertJsonValidationErrors('pec');
});

test('swiss customers are validated on the uid checksum and stored in canonical format', function (): void {
    registry()->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'CH', 'company_name' => 'Bern AG', 'uid_number' => 'CHE-123.456.789'])
        ->assertUnprocessable()->assertJsonValidationErrors('uid_number');

    registry()->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'CH', 'company_name' => 'Bern AG', 'uid_number' => 'che123456788'])
        ->assertCreated()->assertJsonPath('data.uid_number', 'CHE-123.456.788');

    // CH customers are not forced to carry an Italian codice fiscale or partita iva.
    registry()->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'CH', 'company_name' => 'Zurich AG', 'fiscal_code' => 'whatever'])
        ->assertCreated();
});

test('updating a contact applies the fiscal rules of its stored country', function (): void {
    $customer = makeCustomer();

    registry()->putJson("/api/v1/crm/contacts/{$customer->id}", ['vat_number' => '12345678901'])
        ->assertUnprocessable()->assertJsonValidationErrors('vat_number');

    registry()->putJson("/api/v1/crm/contacts/{$customer->id}", ['vat_number' => '01234567897'])->assertOk();
});

// ---------------------------------------------------------------------------------------------------------------
// Registry lookup (VIES / Zefix)
// ---------------------------------------------------------------------------------------------------------------

test('italian lookup autofills the customer from VIES', function (): void {
    Http::fake(['*vies*' => Http::response(['isValid' => true, 'name' => 'ACME SRL', 'address' => "VIA ROMA 1\n20100 MILANO MI"])]);

    registry()->postJson('/api/v1/crm/fiscal/lookup', ['country' => 'IT', 'vat_number' => 'IT01234567897'])
        ->assertOk()
        ->assertJsonPath('data.registry_verified', true)
        ->assertJsonPath('data.source', 'vies')
        ->assertJsonPath('data.data.company_name', 'ACME SRL')
        ->assertJsonPath('data.data.address.street', 'VIA ROMA 1')
        ->assertJsonPath('data.data.address.postal_code', '20100')
        ->assertJsonPath('data.data.address.city', 'MILANO')
        ->assertJsonPath('data.data.address.state_canton', 'MI');
});

test('lookup rejects a formally wrong identifier without calling any registry', function (): void {
    Http::fake();

    registry()->postJson('/api/v1/crm/fiscal/lookup', ['country' => 'IT', 'vat_number' => '01234567890'])->assertUnprocessable();
    registry()->postJson('/api/v1/crm/fiscal/lookup', ['country' => 'CH', 'uid_number' => 'CHE-123.456.789'])->assertUnprocessable();

    Http::assertNothingSent();
});

test('lookup degrades to the checksum when the registry is down', function (): void {
    Http::fake(['*vies*' => Http::response([], 503)]);

    registry()->postJson('/api/v1/crm/fiscal/lookup', ['country' => 'IT', 'vat_number' => '01234567897'])
        ->assertOk()->assertJsonPath('data.registry_verified', false)->assertJsonPath('data.source', 'checksum');
});

test('swiss lookup queries zefix when credentials are configured and falls back to the checksum otherwise', function (): void {
    registry()->postJson('/api/v1/crm/fiscal/lookup', ['country' => 'CH', 'uid_number' => 'CHE-123.456.788'])
        ->assertOk()->assertJsonPath('data.source', 'checksum')->assertJsonPath('data.registry_verified', false);

    config(['services.zefix.username' => 'user', 'services.zefix.password' => 'secret']);
    Http::fake(['*zefix*' => Http::response(['list' => [['name' => 'Bern AG', 'legalSeat' => 'Bern', 'status' => 'ACTIVE']]])]);

    registry()->postJson('/api/v1/crm/fiscal/lookup', ['country' => 'CH', 'uid_number' => 'CHE123456788'])
        ->assertOk()
        ->assertJsonPath('data.source', 'zefix')
        ->assertJsonPath('data.registry_verified', true)
        ->assertJsonPath('data.data.company_name', 'Bern AG')
        ->assertJsonPath('data.data.uid_number', 'CHE-123.456.788');
});

test('verifying a stored customer stamps the certification source and date', function (): void {
    Http::fake(['*vies*' => Http::response(['isValid' => true, 'name' => 'ACME SRL', 'address' => ''])]);
    $customer = makeCustomer(['vat_number' => '01234567897']);

    registry()->postJson("/api/v1/crm/contacts/{$customer->id}/fiscal/verify")->assertOk();

    expect($customer->refresh()->fiscal_verification_source)->toBe('vies')->and($customer->fiscal_verified_at)->not->toBeNull();

    $empty = makeCustomer(['company_name' => 'No VAT']);
    registry()->postJson("/api/v1/crm/contacts/{$empty->id}/fiscal/verify")->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------
// Hierarchy
// ---------------------------------------------------------------------------------------------------------------

test('customers form a hierarchy and loops are rejected', function (): void {
    $holding = makeCustomer(['company_name' => 'Holding SpA', 'hierarchy_type' => 'holding']);
    $subsidiary = makeCustomer(['company_name' => 'Controllata SRL', 'parent_id' => $holding->id, 'hierarchy_type' => 'subsidiary']);

    registry()->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'IT', 'company_name' => 'Filiale Roma', 'parent_id' => $subsidiary->id, 'hierarchy_type' => 'branch'])
        ->assertCreated()->assertJsonPath('data.parent_id', $subsidiary->id);

    $tree = registry()->getJson("/api/v1/crm/contacts/{$holding->id}/hierarchy")->assertOk();
    expect($tree->json('data.tree.children.0.name'))->toBe('Controllata SRL')
        ->and($tree->json('data.tree.children.0.children.0.name'))->toBe('Filiale Roma');

    $ancestors = registry()->getJson("/api/v1/crm/contacts/{$subsidiary->id}/hierarchy")->json('data.ancestors');
    expect($ancestors)->toHaveCount(1)->and($ancestors[0]['name'])->toBe('Holding SpA');

    // The holding cannot become a child of its own subsidiary nor of itself.
    registry()->putJson("/api/v1/crm/contacts/{$holding->id}", ['parent_id' => $subsidiary->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    registry()->putJson("/api/v1/crm/contacts/{$holding->id}", ['parent_id' => $holding->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    registry()->putJson("/api/v1/crm/contacts/{$holding->id}", ['parent_id' => (string) Str::uuid()])->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------
// Contact persons
// ---------------------------------------------------------------------------------------------------------------

test('contact persons are classified by role and exactly one is primary', function (): void {
    $customer = makeCustomer();

    $first = registry()->postJson("/api/v1/crm/contacts/{$customer->id}/persons", ['last_name' => 'Rossi', 'role' => 'administration', 'email' => 'ROSSI@acme.it'])
        ->assertCreated()->assertJsonPath('data.is_primary', true)->assertJsonPath('data.email', 'rossi@acme.it');
    $second = registry()->postJson("/api/v1/crm/contacts/{$customer->id}/persons", ['last_name' => 'Bianchi', 'role' => 'purchasing', 'is_primary' => true])->assertCreated();

    expect($customer->persons()->where('is_primary', true)->pluck('last_name')->all())->toBe(['Bianchi']);

    registry()->postJson("/api/v1/crm/contacts/{$customer->id}/persons", ['last_name' => 'Verdi', 'role' => 'logistics'])->assertCreated();
    expect(registry()->getJson("/api/v1/crm/contacts/{$customer->id}/persons?role=logistics")->json('data'))->toHaveCount(1);

    registry()->postJson("/api/v1/crm/contacts/{$customer->id}/persons", ['last_name' => 'Neri', 'role' => 'wizard'])->assertUnprocessable()->assertJsonValidationErrors('role');

    registry()->deleteJson("/api/v1/crm/contacts/{$customer->id}/persons/{$second->json('data.id')}")->assertOk();
    expect($customer->persons()->where('is_primary', true)->count())->toBe(1);

    registry()->putJson("/api/v1/crm/contacts/{$customer->id}/persons/{$first->json('data.id')}", ['job_title' => 'CFO'])
        ->assertOk()->assertJsonPath('data.job_title', 'CFO');

    expect(registry()->getJson("/api/v1/crm/contacts/{$customer->id}")->json('data.persons'))->not->toBeEmpty();
});

// ---------------------------------------------------------------------------------------------------------------
// Addresses
// ---------------------------------------------------------------------------------------------------------------

function addressPayload(string $type, array $extra = []): array
{
    return ['type' => $type, 'street' => 'Via Roma', 'house_number' => '1', 'postal_code' => '20100', 'city' => 'Milano', 'state_canton' => 'MI', 'country' => 'it', ...$extra];
}

test('a customer holds unlimited addresses with one default per type', function (): void {
    $customer = makeCustomer();
    $url = "/api/v1/crm/contacts/{$customer->id}/addresses";

    $legal = registry()->postJson($url, addressPayload('legal'))->assertCreated()->assertJsonPath('data.is_default', true)->assertJsonPath('data.country', 'IT');
    $ship1 = registry()->postJson($url, addressPayload('shipping', ['label' => 'Magazzino']))->assertCreated()->assertJsonPath('data.is_default', true);
    $ship2 = registry()->postJson($url, addressPayload('shipping', ['label' => 'Cantiere Nord', 'street' => 'Via Torino']))->assertCreated()->assertJsonPath('data.is_default', false);
    registry()->postJson($url, addressPayload('site', ['label' => 'Cantiere A']))->assertCreated();
    registry()->postJson($url, addressPayload('billing'))->assertCreated();

    // Defaults are independent per type: shipping default switches without touching the legal one.
    registry()->postJson("{$url}/{$ship2->json('data.id')}/default")->assertOk()->assertJsonPath('data.is_default', true);

    expect(ContactAddress::find($ship1->json('data.id'))->is_default)->toBeFalse()
        ->and(ContactAddress::find($legal->json('data.id'))->is_default)->toBeTrue()
        ->and($customer->addresses()->where('type', 'shipping')->where('is_default', true)->count())->toBe(1);

    // Deleting the default hands the flag over to a remaining address of the same type.
    registry()->deleteJson("{$url}/{$ship2->json('data.id')}")->assertOk();
    expect(ContactAddress::find($ship1->json('data.id'))->is_default)->toBeTrue();

    expect(registry()->getJson("{$url}?type=shipping")->json('data'))->toHaveCount(1)
        ->and(registry()->getJson($url)->json('data'))->toHaveCount(4);

    registry()->postJson($url, addressPayload('warehouse'))->assertUnprocessable()->assertJsonValidationErrors('type');
});

test('creating a customer accepts several typed addresses at once', function (): void {
    $response = registry()->postJson('/api/v1/crm/contacts', [
        'type' => 'client', 'country' => 'IT', 'company_name' => 'Multi SRL',
        'addresses' => [
            ['type' => 'legal', 'street' => 'Via A', 'postal_code' => '20100', 'city' => 'Milano', 'country' => 'IT'],
            ['type' => 'shipping', 'street' => 'Via B', 'postal_code' => '00100', 'city' => 'Roma', 'country' => 'IT'],
            ['type' => 'shipping', 'street' => 'Via C', 'postal_code' => '10100', 'city' => 'Torino', 'country' => 'IT', 'is_default' => true],
        ],
    ])->assertCreated();

    $customer = Contact::findOrFail($response->json('data.id'));
    expect($customer->addresses)->toHaveCount(3)
        ->and($customer->addresses()->where('type', 'shipping')->where('is_default', true)->value('street'))->toBe('Via C');
});

test('legacy single address payload still creates a default billing address', function (): void {
    $response = registry()->postJson('/api/v1/crm/contacts', [
        'type' => 'client', 'country' => 'CH', 'company_name' => 'Legacy AG',
        'address' => ['street' => 'Bahnhofstrasse', 'postal_code' => '8001', 'city' => 'Zürich', 'country' => 'CH'],
    ])->assertCreated();

    expect($response->json('data.addresses.0.type'))->toBe('billing')->and($response->json('data.addresses.0.is_default'))->toBeTrue();
});

// ---------------------------------------------------------------------------------------------------------------
// Privacy (regime + consents from the CRM consent register)
// ---------------------------------------------------------------------------------------------------------------

test('the overview reports the privacy regime of the country and the effective consents', function (): void {
    $swiss = makeCustomer(['country' => 'CH', 'company_name' => 'Bern AG']);
    $italian = makeCustomer();

    registry()->postJson('/api/v1/crm/consents', ['contact_id' => $italian->id, 'channel' => 'email', 'purpose' => 'marketing', 'status' => 'granted', 'source' => 'web_form'])->assertSuccessful();

    $privacyIt = registry()->getJson("/api/v1/crm/contacts/{$italian->id}/overview")->json('data.privacy');
    $privacyCh = registry()->getJson("/api/v1/crm/contacts/{$swiss->id}/overview")->json('data.privacy');

    expect($privacyIt['regime'])->toBe('gdpr')
        ->and($privacyIt['consents'])->toHaveCount(1)
        ->and($privacyIt['consents'][0]['purpose'])->toBe('marketing')
        ->and($privacyIt['consents'][0]['status'])->toBe('granted')
        ->and($privacyCh['regime'])->toBe('nlpd')
        ->and($privacyCh['consents'])->toBe([]);
});

// ---------------------------------------------------------------------------------------------------------------
// 360 overview
// ---------------------------------------------------------------------------------------------------------------

test('the 360 overview aggregates open balance, credit headroom, documents, tickets and interactions', function (): void {
    $this->travelTo('2026-03-15 10:00:00');
    $customer = makeCustomer(['vat_number' => '01234567897']);

    Invoice::create([
        'tenant_id' => $this->tenant->id, 'contact_id' => $customer->id, 'invoice_type' => 'standard', 'invoice_number' => 'FT-1',
        'date' => '2026-01-10', 'due_date' => '2026-02-10', 'currency' => 'EUR', 'total_net' => 1000, 'total_tax' => 220, 'total_gross' => 1220,
        'total_paid' => 220, 'payment_status' => 'partially_paid', 'jurisdiction' => 'IT',
    ]);
    SalesOrder::create(['tenant_id' => $this->tenant->id, 'contact_id' => $customer->id, 'order_number' => 'SO-1', 'date' => '2026-03-01', 'currency' => 'EUR', 'total_net' => 2000, 'total_tax' => 0, 'total_gross' => 2000, 'status' => 'confirmed']);
    CustomerCreditProfile::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'customer_id' => $customer->id, 'credit_limit' => 5000]);

    registry()->postJson("/api/v1/crm/contacts/{$customer->id}/interactions", ['type' => 'call', 'subject' => 'Sollecito pagamento', 'outcome' => 'promised', 'duration_minutes' => 5])->assertCreated();

    $overview = registry()->getJson("/api/v1/crm/contacts/{$customer->id}/overview")->assertOk();

    expect($overview->json('data.receivables.total_due'))->toBe(1000)
        ->and($overview->json('data.receivables.overdue_total'))->toBe(1000)
        ->and($overview->json('data.credit.credit_limit'))->toBe(5000)
        ->and($overview->json('data.credit.pending_orders'))->toBe(2000)
        ->and($overview->json('data.credit.exposure'))->toBe(3000)
        ->and($overview->json('data.credit.residual'))->toBe(2000)
        ->and($overview->json('data.credit.over_limit'))->toBeFalse()
        ->and($overview->json('data.orders_in_progress.count'))->toBe(1)
        ->and($overview->json('data.invoices.count'))->toBe(1)
        ->and($overview->json('data.recent_interactions.0.subject'))->toBe('Sollecito pagamento');
});

test('the group overview consolidates the subsidiaries', function (): void {
    $holding = makeCustomer(['company_name' => 'Holding']);
    $child = makeCustomer(['company_name' => 'Child', 'parent_id' => $holding->id]);

    foreach ([$holding, $child] as $i => $customer) {
        Invoice::create([
            'tenant_id' => $this->tenant->id, 'contact_id' => $customer->id, 'invoice_type' => 'standard', 'invoice_number' => "FT-{$i}",
            'date' => today()->toDateString(), 'due_date' => today()->addDays(30)->toDateString(), 'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 0,
            'total_gross' => 100, 'total_paid' => 0, 'payment_status' => 'unpaid', 'jurisdiction' => 'IT',
        ]);
    }

    expect(registry()->getJson("/api/v1/crm/contacts/{$holding->id}/overview")->json('data.receivables.total_due'))->toBe(100)
        ->and(registry()->getJson("/api/v1/crm/contacts/{$holding->id}/overview?group=1")->json('data.receivables.total_due'))->toBe(200);
});

test('the overview reports no residual when no credit limit was granted', function (): void {
    $customer = makeCustomer();

    $credit = registry()->getJson("/api/v1/crm/contacts/{$customer->id}/overview")->json('data.credit');

    expect($credit['credit_limit'])->toBeNull()->and($credit['residual'])->toBeNull()->and($credit['over_limit'])->toBeFalse();
});

// ---------------------------------------------------------------------------------------------------------------
// Interactions (web and mobile share the same store)
// ---------------------------------------------------------------------------------------------------------------

test('interactions are logged as completed and can be synced incrementally', function (): void {
    $customer = makeCustomer();
    $url = "/api/v1/crm/contacts/{$customer->id}/interactions";

    $this->travelTo('2026-03-01 09:00:00');
    registry()->postJson($url, ['type' => 'email', 'subject' => 'Offerta inviata', 'occurred_at' => '2026-02-28 16:00:00'])
        ->assertCreated()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.related_type', 'contact');

    $this->travelTo('2026-03-02 09:00:00');
    registry()->postJson($url, ['type' => 'meeting', 'subject' => 'Visita in sede', 'location' => 'Milano'])->assertCreated();
    registry()->postJson($url, ['type' => 'task', 'subject' => 'Not an interaction'])->assertUnprocessable();

    expect(registry()->getJson($url)->json('data'))->toHaveCount(2)
        ->and(registry()->getJson("{$url}?type=meeting")->json('data'))->toHaveCount(1)
        ->and(registry()->getJson($url.'?updated_since='.urlencode('2026-03-01 12:00:00'))->json('data'))->toHaveCount(1);
});

test('another tenant cannot read or modify the customer registry', function (): void {
    $customer = makeCustomer();

    $other = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $stranger = User::create(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $stranger->tenants()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($stranger);

    $api = test()->withHeader('X-Tenant-Id', $other->id);

    $api->getJson("/api/v1/crm/contacts/{$customer->id}/overview")->assertNotFound();
    $api->postJson("/api/v1/crm/contacts/{$customer->id}/persons", ['last_name' => 'Spy'])->assertNotFound();
    $api->getJson("/api/v1/crm/contacts/{$customer->id}/addresses")->assertNotFound();
    $api->getJson("/api/v1/crm/contacts/{$customer->id}/interactions")->assertNotFound();
    $api->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'IT', 'company_name' => 'Hijack', 'parent_id' => $customer->id])->assertUnprocessable();
});
