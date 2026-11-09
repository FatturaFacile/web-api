<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-04-15 10:00:00');
    $this->tenant = complianceTenant();
    actingAsMember($this->tenant);
    $this->document = $this->postJson('/api/v1/fiscal-retail/commercial-documents', ['lines' => [['description' => 'Visita', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0, 'vat_nature' => 'N4']]])->assertCreated()->json('data');
    $this->putJson('/api/v1/tessera-sanitaria/settings', ['is_enabled' => true, 'owner_fiscal_code' => 'RSSMRA80A01H501U'])->assertOk();
});

function healthPayload(array $overrides = []): array
{
    return [
        'document_type' => 'commercial_document', 'document_id' => test()->document['id'], 'expense_type' => 'SR',
        'payment_date' => '2027-04-15', 'patient_fiscal_code' => 'VRDLGU70A01H501X', 'payment_traceable' => true, 'vat_nature' => 'N4', ...$overrides,
    ];
}

test('the flow is off by default and refuses flags until enabled', function (): void {
    actingAsMember($other = complianceTenant('Beta'));
    expect($this->getJson('/api/v1/tessera-sanitaria/settings')->assertOk()->json('data.is_enabled'))->toBeFalse();

    $document = $this->postJson('/api/v1/fiscal-retail/commercial-documents', ['lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1, 'tax_rate' => 22]]])->json('data');
    $this->postJson('/api/v1/tessera-sanitaria/expenses', healthPayload(['document_id' => $document['id']]))->assertUnprocessable()->assertJsonValidationErrors('tessera_sanitaria');
});

test('fiscal codes are encrypted at rest and never returned by the API', function (): void {
    $response = $this->postJson('/api/v1/tessera-sanitaria/expenses', healthPayload())->assertCreated();

    expect($response->getContent())->not->toContain('VRDLGU70A01H501X')
        ->and($this->getJson('/api/v1/tessera-sanitaria/settings')->getContent())->not->toContain('RSSMRA80A01H501U')
        ->and(DB::table('health_expenses')->value('patient_fiscal_code'))->not->toContain('VRDLGU70A01H501X')
        ->and(DB::table('health_expense_settings')->value('owner_fiscal_code'))->not->toContain('RSSMRA80A01H501U');
});

test('the export uses the published field names and states that the format is unverified', function (): void {
    $this->postJson('/api/v1/tessera-sanitaria/expenses', healthPayload())->assertCreated();

    $data = $this->getJson('/api/v1/tessera-sanitaria/export?year=2027')->assertOk()->json('data');

    expect($data['format_status'])->toBe('unverified_data_layer')
        ->and($data['rows'][0])->toMatchArray([
            'pIva' => '00811720580', 'tipoSpesa' => 'SR', 'importo' => '100.00', 'cfCittadino' => 'VRDLGU70A01H501X',
            'pagamentoTracciato' => 'SI', 'tipoDocumento' => 'D', 'flagOpposizione' => '0', 'flagOperazione' => 'I', 'naturaIVA' => 'N4',
        ]);
});

test('an opposition drops the fiscal code of the patient', function (): void {
    $this->postJson('/api/v1/tessera-sanitaria/expenses', healthPayload(['patient_opposition' => true]))->assertCreated();

    $row = $this->getJson('/api/v1/tessera-sanitaria/export?year=2027')->json('data.rows.0');

    expect($row['cfCittadino'])->toBe('')->and($row['flagOpposizione'])->toBe('1')
        ->and(DB::table('health_expenses')->value('patient_fiscal_code'))->toBeNull();
});

test('untraced payments are exported as NO and the CSV is readable', function (): void {
    $this->postJson('/api/v1/tessera-sanitaria/expenses', healthPayload(['payment_traceable' => false]))->assertCreated();

    $csv = $this->get('/api/v1/tessera-sanitaria/export?year=2027&format=csv')->assertOk();

    expect($csv->headers->get('X-Format-Status'))->toBe('unverified_data_layer')
        ->and($csv->getContent())->toContain('"NO"')->toContain('"pIva";"dataEmissione"');
});

test('invalid flags are refused', function (array $overrides, string $field): void {
    $this->postJson('/api/v1/tessera-sanitaria/expenses', healthPayload($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown type' => [['expense_type' => 'ZZ'], 'expense_type'],
    'missing fiscal code' => [['patient_fiscal_code' => null], 'patient_fiscal_code'],
    'payment before issue' => [['payment_date' => '2027-04-01'], 'payment_date'],
    'rate and nature' => [['tax_rate' => 22], 'tax_rate'],
]);

test('documents and expenses of another tenant stay out of reach', function (): void {
    $expense = $this->postJson('/api/v1/tessera-sanitaria/expenses', healthPayload())->assertCreated()->json('data');

    actingAsMember(complianceTenant('Beta'));
    $this->putJson('/api/v1/tessera-sanitaria/settings', ['is_enabled' => true])->assertOk();

    $this->postJson('/api/v1/tessera-sanitaria/expenses', healthPayload())->assertNotFound();
    $this->deleteJson("/api/v1/tessera-sanitaria/expenses/{$expense['id']}")->assertNotFound();
    expect($this->getJson('/api/v1/tessera-sanitaria/export?year=2027')->json('data.rows'))->toBe([]);
});

test('health data is closed to members and to the read-only accountant', function (string $role): void {
    actingAsMember($this->tenant, $role);

    $this->getJson('/api/v1/tessera-sanitaria/export?year=2027')->assertForbidden();
})->with(['member', 'accountant_readonly']);

test('writes on health data are closed to roles outside the module', function (string $role): void {
    actingAsMember($this->tenant, $role);

    $this->putJson('/api/v1/tessera-sanitaria/settings', ['is_enabled' => true])->assertForbidden();
    $this->postJson('/api/v1/tessera-sanitaria/expenses', healthPayload())->assertForbidden();
    $this->getJson('/api/v1/tessera-sanitaria/expenses')->assertForbidden();
})->with(['member', 'accountant_readonly']);

test('a document is flagged once: the second flag is a conflict', function (): void {
    $this->postJson('/api/v1/tessera-sanitaria/expenses', healthPayload())->assertCreated();

    $this->postJson('/api/v1/tessera-sanitaria/expenses', healthPayload())->assertStatus(409);
    expect(DB::table('health_expenses')->count())->toBe(1);
});

test('an expense is shown without the fiscal code and is invisible to another tenant', function (): void {
    $expense = $this->postJson('/api/v1/tessera-sanitaria/expenses', healthPayload())->assertCreated()->json('data');

    $response = $this->getJson("/api/v1/tessera-sanitaria/expenses/{$expense['id']}")->assertOk();
    expect($response->json('data.id'))->toBe($expense['id'])->and($response->getContent())->not->toContain('VRDLGU70A01H501X');

    $this->getJson('/api/v1/tessera-sanitaria/expenses/'.Str::uuid())->assertNotFound();

    actingAsMember(complianceTenant('Beta'));
    $this->getJson("/api/v1/tessera-sanitaria/expenses/{$expense['id']}")->assertNotFound();
});

test('the summary totals the year by expense type in exact cents and counts oppositions', function (): void {
    $second = $this->postJson('/api/v1/fiscal-retail/commercial-documents', ['lines' => [['description' => 'Controllo', 'quantity' => 3, 'unit_price' => '33.33', 'tax_rate' => 0, 'vat_nature' => 'N4']]])->assertCreated()->json('data');
    $this->postJson('/api/v1/tessera-sanitaria/expenses', healthPayload())->assertCreated();
    $this->postJson('/api/v1/tessera-sanitaria/expenses', healthPayload(['document_id' => $second['id'], 'expense_type' => 'CT', 'patient_opposition' => true]))->assertCreated();

    $data = $this->getJson('/api/v1/tessera-sanitaria/summary?year=2027')->assertOk()->json('data');

    expect($data['count'])->toBe(2)
        ->and($data['total_amount'])->toBe('199.99')
        ->and($data['opposed_count'])->toBe(1)
        ->and(collect($data['by_expense_type'])->pluck('total_amount', 'expense_type')->all())->toBe(['CT' => '99.99', 'SR' => '100.00']);
    expect($this->getJson('/api/v1/tessera-sanitaria/summary?year=2026')->json('data.count'))->toBe(0);
    $this->getJson('/api/v1/tessera-sanitaria/summary')->assertUnprocessable()->assertJsonValidationErrors('year');

    actingAsMember($this->tenant, 'member');
    $this->getJson('/api/v1/tessera-sanitaria/summary?year=2027')->assertForbidden();
});
