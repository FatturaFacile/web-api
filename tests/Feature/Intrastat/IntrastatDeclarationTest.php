<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PassiveInvoice;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Intrastat\Models\IntrastatDeclaration;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-05-12 09:00:00');
    $this->tenant = Tenant::create(['name' => 'Meccanica Rossi', 'legal_name' => 'Meccanica Rossi Srl', 'vat_number' => 'IT12345678903', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->accountant = intrastatMemberOf($this->tenant, 'accountant');
});

afterEach(fn () => app(TenantContext::class)->clear());

function intrastatMemberOf(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@example.it', 'password' => bcrypt('secret123'), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function intrastatActAs(User $user, Tenant $tenant): mixed
{
    Sanctum::actingAs($user);
    test()->flushHeaders();
    app(TenantContext::class)->clear();

    return test()->withHeader('X-Tenant-Id', $tenant->id);
}

function intrastatContact(Tenant $tenant, string $name, string $country, ?string $vatNumber, string $type = 'customer'): Contact
{
    return Contact::create(['tenant_id' => $tenant->id, 'type' => $type, 'company_name' => $name, 'country' => $country, 'vat_number' => $vatNumber]);
}

function intrastatProduct(Tenant $tenant, string $sku, ?string $hsCode = null): Product
{
    return Product::create(['tenant_id' => $tenant->id, 'sku' => $sku, 'name' => ucfirst($sku), 'hs_code' => $hsCode, 'track_inventory' => false]);
}

/**
 * @param  list<array{0: Product|null, 1: string, 2: string}>  $items  product, quantity, net line total
 * @param  array<string, mixed>  $attributes
 */
function intrastatInvoice(Tenant $tenant, Contact $contact, string $number, string $date, array $items, array $attributes = []): Invoice
{
    $net = array_sum(array_map(static fn (array $item): float => (float) $item[2], $items));
    $invoice = Invoice::create([
        'tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'invoice_type' => 'standard', 'invoice_number' => $number, 'date' => $date,
        'currency' => 'EUR', 'total_net' => $net, 'total_tax' => 0, 'total_gross' => $net, 'payment_status' => 'unpaid', 'jurisdiction' => 'IT',
        ...$attributes,
    ]);

    foreach ($items as [$product, $quantity, $total]) {
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'product_id' => $product?->id, 'description' => $product?->name ?? 'Servizio', 'quantity' => $quantity,
            'unit_price' => round((float) $total / (float) $quantity, 4), 'tax_rate' => 0, 'discount_percentage' => 0, 'line_total' => $total,
        ]);
    }

    return $invoice;
}

/**
 * Sales of April 2027: goods and a service to a German customer, a sale to a French private person, an Italian sale, a
 * credit note and a line nobody classified.
 */
function intrastatSalesFixture(Tenant $tenant, User $user): void
{
    $widget = intrastatProduct($tenant, 'widget');
    $gadget = intrastatProduct($tenant, 'gadget');
    $consulting = intrastatProduct($tenant, 'consulting');
    $mystery = intrastatProduct($tenant, 'mystery');

    intrastatActAs($user, $tenant);
    test()->putJson("/api/v1/intrastat/product-profiles/{$widget->id}", ['supply_kind' => 'goods', 'cn8_code' => '84212100', 'net_mass_kg' => 2.5, 'origin_country_code' => 'IT', 'origin_province_code' => 'MI'])->assertOk();
    test()->putJson("/api/v1/intrastat/product-profiles/{$gadget->id}", ['supply_kind' => 'goods', 'net_mass_kg' => 1, 'origin_country_code' => 'IT', 'origin_province_code' => 'MI'])->assertOk();
    test()->putJson("/api/v1/intrastat/product-profiles/{$consulting->id}", ['supply_kind' => 'services', 'service_code' => '620111'])->assertOk();
    app(TenantContext::class)->clear();

    $german = intrastatContact($tenant, 'Kunde GmbH', 'DE', 'DE 123456789');
    $french = intrastatContact($tenant, 'Jeanne Martin', 'FR', null);
    $italian = intrastatContact($tenant, 'Cliente Srl', 'IT', '01234567897');

    intrastatInvoice($tenant, $german, 'A-1', '2027-04-03', [[$widget, '10', '1000.40'], [$consulting, '1', '500.49'], [$mystery, '1', '50.00']]);
    intrastatInvoice($tenant, $german, 'A-2', '2027-04-20', [[$widget, '2', '200.10'], [$gadget, '3', '90.00']]);
    intrastatInvoice($tenant, $german, 'A-0', '2027-03-28', [[$widget, '1', '100.00']]);
    intrastatInvoice($tenant, $french, 'A-3', '2027-04-21', [[$widget, '1', '100.00']]);
    intrastatInvoice($tenant, $italian, 'A-4', '2027-04-22', [[$widget, '1', '100.00']]);
    intrastatInvoice($tenant, $german, 'NC-1', '2027-04-25', [[$widget, '1', '100.00']], ['invoice_type' => 'credit_note']);
}

function intrastatGenerateSales(): mixed
{
    return test()->postJson('/api/v1/intrastat/declarations/generate', [
        'flow' => 'sales', 'year' => 2027, 'periodicity' => 'M', 'period' => 4, 'includes_statistical_data' => true,
        'defaults' => ['transaction_nature_a' => '1'],
    ]);
}

test('a monthly sales list is drafted from the invoices of the period, summing rows with the same characteristics', function (): void {
    intrastatSalesFixture($this->tenant, $this->accountant);
    intrastatActAs($this->accountant, $this->tenant);

    $declaration = intrastatGenerateSales()
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.model', 'INTRA-1')
        ->assertJsonPath('data.obligated_vat_number', '12345678903')
        ->assertJsonPath('data.reference_number', 1)
        ->assertJsonPath('data.generation_report.period_from', '2027-04-01')
        ->assertJsonPath('data.generation_report.period_to', '2027-04-30')
        ->assertJsonPath('data.sections.0.count', 2)
        ->assertJsonPath('data.sections.2.count', 1)
        ->json('data');

    $goods = collect($declaration['lines'])->where('section', 1)->values();
    $services = collect($declaration['lines'])->where('section', 3)->values();

    // 1000.40 + 200.10 = 1200.50, rounded up to 1201; 12 units of 2.5 kg.
    expect($goods[0])->toMatchArray([
        'line_number' => 1, 'partner_country_code' => 'DE', 'partner_vat_number' => '123456789', 'amount_eur' => 1201,
        'transaction_nature_a' => '1', 'cn8_code' => '84212100', 'net_mass_kg' => 30, 'goods_country_code' => 'DE',
        'origin_country_code' => 'IT', 'province_code' => 'MI', 'delivery_terms' => null, 'transport_mode' => null,
    ])
        ->and(collect($goods[0]['source_documents'])->pluck('number')->all())->toBe(['A-1', 'A-2'])
        // The gadget has no combined nomenclature code: its row stays apart and empty, never filled with a placeholder.
        ->and($goods[1])->toMatchArray(['line_number' => 2, 'amount_eur' => 90, 'cn8_code' => null, 'net_mass_kg' => 3])
        ->and($services[0])->toMatchArray([
            'line_number' => 1, 'partner_country_code' => 'DE', 'partner_vat_number' => '123456789', 'amount_eur' => 500,
            'service_code' => '620111', 'invoice_number' => 'A-1', 'invoice_date' => '2027-04-03', 'payment_country_code' => null,
        ]);

    $report = $declaration['generation_report'];
    expect(collect($report['skipped'])->pluck('reason', 'document.number')->all())->toBe(['A-3' => 'customer_without_vat_number', 'NC-1' => 'credit_note'])
        ->and(collect($report['unclassified'])->pluck('document.number')->all())->toBe(['A-1'])
        ->and($report['unclassified'][0]['amount'])->toBe('50.00');
});

test('rows lacking mandatory data are reported as validation errors and the list stays a draft until they are fixed', function (): void {
    intrastatSalesFixture($this->tenant, $this->accountant);
    intrastatActAs($this->accountant, $this->tenant);
    $declaration = intrastatGenerateSales()->assertCreated()->json('data');
    $lines = collect($declaration['lines']);
    $gadgetRow = $lines->firstWhere('cn8_code', null);
    $serviceRow = $lines->firstWhere('section', 3);

    $errors = $this->postJson("/api/v1/intrastat/declarations/{$declaration['id']}/validate")
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->json('invalid_params');

    expect(collect($errors)->map(fn (array $error): string => "{$error['section']}.{$error['line_number']}.{$error['field']}")->sort()->values()->all())
        ->toBe(['1.2.cn8_code', '3.1.payment_country_code']);
    $this->getJson("/api/v1/intrastat/declarations/{$declaration['id']}")->assertJsonPath('data.status', 'draft');

    $this->putJson("/api/v1/intrastat/declarations/{$declaration['id']}/lines/{$gadgetRow['id']}", ['cn8_code' => '85176200'])->assertOk();
    $this->putJson("/api/v1/intrastat/declarations/{$declaration['id']}/lines/{$serviceRow['id']}", ['payment_country_code' => 'DE'])->assertOk();

    $this->postJson("/api/v1/intrastat/declarations/{$declaration['id']}/validate")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'validated');

    // Any change sends the list back to draft.
    $this->putJson("/api/v1/intrastat/declarations/{$declaration['id']}/lines/{$gadgetRow['id']}", ['net_mass_kg' => 4])->assertOk();
    $this->getJson("/api/v1/intrastat/declarations/{$declaration['id']}")->assertJsonPath('data.status', 'draft');
});

test('code lists, VAT code lengths and the columns of each section are checked', function (): void {
    intrastatActAs($this->accountant, $this->tenant);
    $id = $this->postJson('/api/v1/intrastat/declarations', ['flow' => 'sales', 'year' => 2027, 'periodicity' => 'T', 'period' => 1])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/intrastat/declarations/{$id}/lines", ['section' => 1, 'delivery_terms' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('delivery_terms');
    $this->postJson("/api/v1/intrastat/declarations/{$id}/lines", ['section' => 1, 'partner_country_code' => 'GR', 'partner_vat_number' => '12345', 'amount_eur' => 10, 'net_mass_kg' => 5])->assertCreated();
    $this->postJson("/api/v1/intrastat/declarations/{$id}/lines", ['section' => 1, 'partner_country_code' => 'AT', 'partner_vat_number' => 'U1234', 'amount_eur' => 10])->assertCreated();
    $this->postJson("/api/v1/intrastat/declarations/{$id}/lines", ['section' => 3, 'partner_country_code' => 'XI', 'partner_vat_number' => '123456789', 'amount_eur' => 10, 'service_code' => '620111', 'payment_country_code' => 'GB'])->assertCreated();

    $errors = collect($this->postJson("/api/v1/intrastat/declarations/{$id}/validate")->assertUnprocessable()->json('invalid_params'))
        ->map(fn (array $error): string => "{$error['section']}.{$error['line_number']}.{$error['field']}")->sort()->values()->all();

    // Greece is EL in Tabella A, the quarterly INTRA-1 bis has no net mass, an Austrian VAT code has 9 characters,
    // services with Northern Ireland are excluded.
    expect($errors)->toBe(['1.1.net_mass_kg', '1.1.partner_country_code', '1.2.partner_vat_number', '3.1.partner_country_code']);

    $this->postJson('/api/v1/intrastat/declarations', ['flow' => 'purchases', 'year' => 2027, 'periodicity' => 'T', 'period' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('periodicity');
});

test('the telematic file follows the positions of the technical specifications', function (): void {
    intrastatSalesFixture($this->tenant, $this->accountant);
    intrastatActAs($this->accountant, $this->tenant);
    $declaration = intrastatGenerateSales()->json('data');
    $lines = collect($declaration['lines']);
    $this->deleteJson("/api/v1/intrastat/declarations/{$declaration['id']}/lines/{$lines->firstWhere('cn8_code', null)['id']}")->assertNoContent();
    $this->putJson("/api/v1/intrastat/declarations/{$declaration['id']}/lines/{$lines->firstWhere('section', 3)['id']}", ['payment_country_code' => 'DE'])->assertOk();

    $this->postJson('/api/v1/intrastat/exports', ['declaration_ids' => [$declaration['id']], 'channel' => 'entratel', 'customs_section_code' => '123456'])
        ->assertUnprocessable()
        ->assertJsonPath('invalid_params.0.field', 'status');

    $this->postJson("/api/v1/intrastat/declarations/{$declaration['id']}/validate")->assertOk();
    $response = $this->post('/api/v1/intrastat/exports', ['declaration_ids' => [$declaration['id']], 'channel' => 'entratel', 'customs_section_code' => '123456'], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="ZENT0512.I01"');

    $content = $response->getContent();
    expect($content)->toEndWith("\r\n");
    $records = explode("\r\n", substr($content, 0, -2));
    expect($records)->toHaveCount(4);

    $fixed = static fn (string $type, string $line): string => 'EUROX'.'12345678903'.'000001'.$type.$line;

    // Record di testa (Entratel): ZENT, flow name, customs section, VAT number left aligned, 001, record count.
    expect($records[0])->toBe('ZENT'.str_repeat(' ', 12).'ZENT0512.I01'.str_repeat(' ', 12).'123456'.str_repeat(' ', 4).'12345678903'.str_repeat(' ', 5).'001'.' '.'00004')
        ->and(strlen($records[0]))->toBe(75);

    // Frontespizio INTRA-1: C, year, M, period, obliged party, content 0, case 0, no delegate, counts and totals.
    expect($records[1])->toBe($fixed('0', '00000').'C'.'27'.'M'.'04'.'12345678903'.'0'.'0'.'00000000000'
        .'00001'.'0000000001201'.'00000'.'0000000000000'.'00001'.'0000000000500'.'00000'.'0000000000000'.'00000')
        ->and(strlen($records[1]))->toBe(135)
        ->and(substr($records[1], 28, 1))->toBe('C')
        ->and(substr($records[1], 63, 13))->toBe('0000000001201')
        ->and(substr($records[1], 99, 13))->toBe('0000000000500');

    // INTRA-1 bis monthly: state 29, VAT 31, amount 43, natura A 56, CN8 57, mass 65, units 75, statistical value 85,
    // delivery 98, transport 99, destination 100, province 102, natura B 104, origin 105.
    expect($records[2])->toBe($fixed('1', '00001').'DE'.'123456789   '.'0000000001201'.'1'.'84212100'.'0000000030'.'0000000000'.'0000000000000'.' '.'0'.'DE'.'MI'.' '.'IT')
        ->and(strlen($records[2]))->toBe(106)
        ->and(substr($records[2], 56, 8))->toBe('84212100')
        ->and(substr($records[2], 64, 10))->toBe('0000000030')
        ->and(substr($records[2], 104, 2))->toBe('IT');

    // INTRA-1 quater: state 29, VAT 31, amount 43, invoice 56, date 71 (ggmmaa), service 77, supply 83, payment 84,
    // country of payment 85.
    expect($records[3])->toBe($fixed('3', '00001').'DE'.'123456789   '.'0000000000500'.'A-1            '.'030427'.'620111'.' '.' '.'DE')
        ->and(strlen($records[3]))->toBe(86)
        ->and(substr($records[3], 70, 6))->toBe('030427')
        ->and(substr($records[3], 76, 6))->toBe('620111');

    $this->getJson("/api/v1/intrastat/declarations/{$declaration['id']}")->assertJsonPath('data.last_export_file_name', 'ZENT0512.I01');
});

test('a negative total of the corrections of section 2 is encoded in its last character', function (): void {
    intrastatActAs($this->accountant, $this->tenant);
    $id = $this->postJson('/api/v1/intrastat/declarations', ['flow' => 'sales', 'year' => 2027, 'periodicity' => 'M', 'period' => 4, 'reference_number' => 41])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/intrastat/declarations/{$id}/lines", [
        'section' => 2, 'corrected_month' => 2, 'corrected_quarter' => 0, 'corrected_year' => 2027, 'partner_country_code' => 'FR',
        'partner_vat_number' => '12345678901', 'sign' => '-', 'amount_eur' => 91234, 'transaction_nature_a' => '1', 'cn8_code' => '84212100',
    ])->assertCreated();
    $this->postJson("/api/v1/intrastat/declarations/{$id}/validate")->assertOk();

    $content = $this->post('/api/v1/intrastat/exports', ['declaration_ids' => [$id], 'channel' => 'customs', 'user_code' => 'AB12', 'customs_section_code' => '91000', 'site_progressive' => 2, 'sequence' => '03'], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="AB120512.I03"')
        ->getContent();
    [$header, $frontespizio, $correction] = explode("\r\n", substr($content, 0, -2));

    expect(substr($header, 40, 6))->toBe('091000')
        ->and(substr($header, 66, 3))->toBe('002')
        ->and(substr($frontespizio, 76, 5))->toBe('00001')
        ->and(substr($frontespizio, 81, 13))->toBe('000000009123t')
        // INTRA-1 ter monthly: month 29, quarter 31, year 32, state 34, VAT 36, sign 48, amount 49, natura A 62, CN8 63,
        // statistical value 71.
        ->and($correction)->toBe('EUROX12345678903000041200001'.'02'.'0'.'27'.'FR'.'12345678901 '.'-'.'0000000091234'.'1'.'84212100'.'0000000000000')
        ->and(strlen($correction))->toBe(83);
});

test('a monthly purchases list is drafted from the received invoices with their integration documents', function (): void {
    $french = intrastatContact($this->tenant, 'Fournisseur SA', 'FR', 'FR12345678901', 'supplier');
    $spanish = intrastatContact($this->tenant, 'Proveedor SL', 'ES', 'ESB1234567X', 'supplier');
    $goods = PassiveInvoice::create(['tenant_id' => $this->tenant->id, 'supplier_id' => $french->id, 'invoice_number' => 'F-77', 'date' => '2027-04-10', 'total_amount' => 2500.5, 'total_net' => 2500.5, 'currency' => 'EUR', 'document_type' => 'TD01']);
    $services = PassiveInvoice::create(['tenant_id' => $this->tenant->id, 'supplier_id' => $spanish->id, 'invoice_number' => 'S-9', 'date' => '2027-04-11', 'total_amount' => 300, 'total_net' => 300, 'currency' => 'EUR', 'document_type' => 'TD01']);
    PassiveInvoice::create(['tenant_id' => $this->tenant->id, 'supplier_id' => $spanish->id, 'invoice_number' => 'S-10', 'date' => '2027-04-12', 'total_amount' => 40, 'total_net' => 40, 'currency' => 'EUR', 'document_type' => 'TD01']);
    intrastatInvoice($this->tenant, $french, 'AF-1', '2027-04-10', [], ['document_type' => 'TD18', 'source_passive_invoice_id' => $goods->id, 'total_net' => 2500.5]);
    intrastatInvoice($this->tenant, $spanish, 'AF-2', '2027-04-11', [], ['document_type' => 'TD17', 'source_passive_invoice_id' => $services->id, 'total_net' => 300]);

    intrastatActAs($this->accountant, $this->tenant);
    $declaration = $this->postJson('/api/v1/intrastat/declarations/generate', ['flow' => 'purchases', 'year' => 2027, 'periodicity' => 'M', 'period' => 4, 'defaults' => ['service_code' => '620111']])
        ->assertCreated()
        ->assertJsonPath('data.model', 'INTRA-2')
        ->assertJsonPath('data.sections.0.count', 1)
        ->assertJsonPath('data.sections.2.count', 1)
        ->assertJsonPath('data.generation_report.unclassified.0.document.number', 'S-10')
        ->json('data');

    $lines = collect($declaration['lines']);
    expect($lines->firstWhere('section', 1))->toMatchArray(['partner_country_code' => 'FR', 'partner_vat_number' => '12345678901', 'amount_eur' => 2501, 'cn8_code' => null, 'goods_country_code' => 'FR'])
        ->and($lines->firstWhere('section', 3))->toMatchArray(['partner_country_code' => 'ES', 'partner_vat_number' => 'B1234567X', 'amount_eur' => 300, 'service_code' => '620111', 'invoice_number' => 'S-9']);

    $errors = collect($this->postJson("/api/v1/intrastat/declarations/{$declaration['id']}/validate")->assertUnprocessable()->json('invalid_params'))
        ->where('section', 1)->pluck('field')->sort()->values()->all();
    expect($errors)->toBe(['cn8_code', 'net_mass_kg', 'origin_country_code', 'province_code', 'transaction_nature_a']);

    $goodsRow = $lines->firstWhere('section', 1);
    $this->putJson("/api/v1/intrastat/declarations/{$declaration['id']}/lines/{$goodsRow['id']}", [
        'transaction_nature_a' => '1', 'cn8_code' => '39269097', 'net_mass_kg' => 120, 'origin_country_code' => 'CN', 'province_code' => 'BG', 'transport_mode' => '3',
    ])->assertOk();
    $this->postJson("/api/v1/intrastat/declarations/{$declaration['id']}/validate")->assertOk();

    $content = $this->post('/api/v1/intrastat/exports', ['declaration_ids' => [$declaration['id']], 'channel' => 'entratel', 'customs_section_code' => '123456'], ['Accept' => 'application/json'])->assertOk()->getContent();
    [, $frontespizio, $goodsRecord, $servicesRecord] = explode("\r\n", substr($content, 0, -2));

    // INTRA-2 frontespizio has no field 22: it ends at position 130.
    expect(strlen($frontespizio))->toBe(130)
        ->and(substr($frontespizio, 28, 1))->toBe('A')
        // INTRA-2 bis monthly: state 29, VAT 31, amount 43, currency 56, natura A 69, CN8 70, mass 78, units 88,
        // statistical value 98, delivery 111, transport 112, provenance 113, origin 115, province 117, natura B 119.
        ->and($goodsRecord)->toBe('EUROX12345678903000001100001'.'FR'.'12345678901 '.'0000000002501'.'0000000000000'.'1'.'39269097'.'0000000120'.'0000000000'.'0000000000000'.' '.'3'.'FR'.'CN'.'BG'.' ')
        ->and(strlen($goodsRecord))->toBe(119)
        // INTRA-2 quater: state 29, VAT 31, amount 43, currency 56, invoice 69, date 84, service 90, supply 96,
        // payment 97, country of payment 98.
        ->and($servicesRecord)->toBe('EUROX12345678903000001300001'.'ES'.'B1234567X   '.'0000000000300'.'0000000000000'.'S-9            '.'110427'.'620111'.' '.' '.'  ')
        ->and(strlen($servicesRecord))->toBe(99);
});

test('a submitted list keeps its protocol and can no longer change', function (): void {
    intrastatActAs($this->accountant, $this->tenant);
    $id = $this->postJson('/api/v1/intrastat/declarations', ['flow' => 'sales', 'year' => 2027, 'periodicity' => 'T', 'period' => 1])->json('data.id');
    $line = $this->postJson("/api/v1/intrastat/declarations/{$id}/lines", ['section' => 1, 'partner_country_code' => 'AT', 'partner_vat_number' => 'U12345678', 'amount_eur' => 15000])->json('data.id');

    $this->postJson("/api/v1/intrastat/declarations/{$id}/submit", ['protocol_number' => '24123456789'])->assertUnprocessable();
    $this->postJson("/api/v1/intrastat/declarations/{$id}/validate")->assertOk();
    $this->postJson("/api/v1/intrastat/declarations/{$id}/submit", ['protocol_number' => '24123456789'])
        ->assertOk()
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.protocol_number', '24123456789');

    $this->putJson("/api/v1/intrastat/declarations/{$id}/lines/{$line}", ['amount_eur' => 1])->assertUnprocessable();
    $this->putJson("/api/v1/intrastat/declarations/{$id}", ['notes' => 'x'])->assertUnprocessable();
    $this->deleteJson("/api/v1/intrastat/declarations/{$id}")->assertUnprocessable();
});

test('lists and invoices of another company are never visible or used', function (): void {
    $other = Tenant::create(['name' => 'Altra', 'legal_name' => 'Altra Spa', 'vat_number' => '09876543210', 'country' => 'IT', 'default_currency' => 'EUR']);
    $otherAccountant = intrastatMemberOf($other, 'accountant');
    $foreign = intrastatContact($other, 'Klant BV', 'NL', 'NL123456789B01');
    intrastatInvoice($other, $foreign, 'X-1', '2027-04-05', [[null, '1', '999.00']], ['vat_nature' => 'N3.2']);

    intrastatActAs($otherAccountant, $other);
    $otherList = $this->postJson('/api/v1/intrastat/declarations', ['flow' => 'sales', 'year' => 2027, 'periodicity' => 'M', 'period' => 4])->assertCreated()->json('data.id');

    intrastatActAs($this->accountant, $this->tenant);
    $this->getJson('/api/v1/intrastat/declarations')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/intrastat/declarations/{$otherList}")->assertNotFound();
    $this->putJson("/api/v1/intrastat/declarations/{$otherList}", ['notes' => 'x'])->assertNotFound();
    $this->postJson('/api/v1/intrastat/exports', ['declaration_ids' => [$otherList], 'channel' => 'entratel', 'customs_section_code' => '1'])->assertUnprocessable()->assertJsonValidationErrors('declaration_ids.0');

    $mine = intrastatGenerateSales()->assertCreated()->json('data');
    expect($mine['lines'])->toBe([])->and($mine['generation_report']['documents'])->toBe(0);

    intrastatActAs($otherAccountant, $other);
    $theirs = intrastatGenerateSales()->assertCreated()->assertJsonPath('data.sections.0.count', 1)->json('data');
    expect($theirs['lines'][0]['partner_vat_number'])->toBe('123456789B01');
    expect(IntrastatDeclaration::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

test('the read-only accountant reads the lists but never writes, and ordinary members stay out', function (): void {
    intrastatActAs($this->accountant, $this->tenant);
    $id = $this->postJson('/api/v1/intrastat/declarations', ['flow' => 'sales', 'year' => 2027, 'periodicity' => 'M', 'period' => 4])->json('data.id');

    intrastatActAs(intrastatMemberOf($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->getJson('/api/v1/intrastat/declarations')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/intrastat/declarations/{$id}")->assertOk();
    $this->getJson('/api/v1/intrastat/codes')->assertOk()->assertJsonPath('data.member_states.EL.vat_lengths', [9]);
    intrastatGenerateSales()->assertForbidden();
    $this->postJson("/api/v1/intrastat/declarations/{$id}/lines", ['section' => 1])->assertForbidden();
    $this->postJson("/api/v1/intrastat/declarations/{$id}/validate")->assertForbidden();
    $this->deleteJson("/api/v1/intrastat/declarations/{$id}")->assertForbidden();

    intrastatActAs(intrastatMemberOf($this->tenant, 'member'), $this->tenant);
    $this->getJson('/api/v1/intrastat/declarations')->assertForbidden();
});

test('the periodicity follows the quarterly totals of the four previous quarters', function (): void {
    $german = intrastatContact($this->tenant, 'Kunde GmbH', 'DE', 'DE123456789');
    intrastatInvoice($this->tenant, $german, 'P-1', '2027-02-10', [[null, '1', '60000.00']], ['vat_nature' => 'N3.2']);
    intrastatInvoice($this->tenant, $german, 'P-2', '2026-03-31', [[null, '1', '500000.00']], ['vat_nature' => 'N3.2']);

    intrastatActAs($this->accountant, $this->tenant);
    $advice = $this->getJson('/api/v1/intrastat/periodicity?year=2027&quarter=2')->assertOk()->json('data');

    // 2026-Q1 is outside the four quarters before 2027-Q2.
    expect($advice['quarters_measured'])->toBe(['2026-Q2', '2026-Q3', '2026-Q4', '2027-Q1'])
        ->and($advice['sales_goods']['quarterly_totals'])->toBe(['2026-Q2' => '0.00', '2026-Q3' => '0.00', '2026-Q4' => '0.00', '2027-Q1' => '60000.00'])
        ->and($advice['sales_goods']['periodicity'])->toBe('M')
        ->and($advice['sales_goods']['statistical_data_required'])->toBeFalse()
        ->and($advice['sales_services']['periodicity'])->toBe('T')
        ->and($advice['purchases_goods']['monthly_threshold'])->toBe(2_000_000)
        ->and($advice['purchases_goods']['monthly_list_required'])->toBeFalse()
        ->and($advice['annual_flows_above_20_million']['dispatches'])->toBe('500000.00');
});
