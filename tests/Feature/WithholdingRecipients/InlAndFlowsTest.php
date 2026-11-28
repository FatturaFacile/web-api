<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\WithholdingRecipients\Models\OccasionalInlNotification;

require_once __DIR__.'/WithholdingRecipientsSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    wrSetUp($this);
});

it('generates the preventive INL communication with payload and XML for an occasional worker', function (): void {
    $worker = wrRecipient($this, 'occasional_worker', ['birth_date' => '1980-01-01']);

    $response = $this->postJson(WR_API."/recipients/{$worker->id}/inl-notifications", [
        'work_start_date' => '2026-07-01', 'work_end_date' => '2026-07-15', 'activity_description' => 'Consulenza organizzativa', 'work_place' => 'Torino', 'estimated_compensation' => 2500,
    ])->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.sent_before_start', false)
        ->assertJsonPath('data.payload.riferimento_normativo', 'Art. 14 D.Lgs. 81/2008')
        ->assertJsonPath('data.payload.committente.denominazione', 'Compensi Srl')
        ->assertJsonPath('data.payload.committente.partita_iva', '00811720580')
        ->assertJsonPath('data.payload.prestatore.nominativo', 'Luca Occasionale')
        ->assertJsonPath('data.payload.prestatore.data_nascita', '1980-01-01')
        ->assertJsonPath('data.payload.prestazione.data_inizio', '2026-07-01')
        ->assertJsonPath('data.payload.prestazione.compenso_presunto', '2500.00');

    $id = $response->json('data.id');
    $xml = $this->get(WR_API."/inl-notifications/{$id}/download?format=xml")->assertOk()->assertHeader('Content-Type', 'application/xml')->getContent();
    expect($xml)->toContain('<ComunicazioneLavoroAutonomoOccasionale>')->and($xml)->toContain('<Denominazione>Compensi Srl</Denominazione>')->and($xml)->toContain('<DataInizio>2026-07-01</DataInizio>');

    $json = $this->get(WR_API."/inl-notifications/{$id}/download?format=json")->assertOk()->assertHeader('Content-Type', 'application/json')->getContent();
    expect(json_decode($json, true)['prestazione']['luogo'])->toBe('Torino');

    $pdf = $this->get(WR_API."/inl-notifications/{$id}/download?format=pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
    expect(str_starts_with($pdf, '%PDF'))->toBeTrue();
    $this->get(WR_API."/inl-notifications/{$id}/download")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->getJson(WR_API."/inl-notifications/{$id}/download?format=docx")->assertStatus(422)->assertJsonValidationErrors(['format']);

    $this->getJson(WR_API."/recipients/{$worker->id}/inl-notifications")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(WR_API."/recipients/{$worker->id}")->assertOk()->assertJsonCount(1, 'data.inl_notifications');
});

it('refuses the communication for other regimes and validates its dates', function (): void {
    $author = wrRecipient($this, 'author_royalty');
    $worker = wrRecipient($this, 'occasional_worker');
    $payload = ['work_start_date' => '2026-07-01', 'work_end_date' => '2026-07-15', 'activity_description' => 'Docenza'];

    $this->postJson(WR_API."/recipients/{$author->id}/inl-notifications", $payload)->assertStatus(422)->assertJsonPath('code', 'inl_not_applicable');
    $this->postJson(WR_API."/recipients/{$worker->id}/inl-notifications", array_merge($payload, ['work_end_date' => '2026-06-01']))->assertStatus(422)->assertJsonValidationErrors(['work_end_date']);
    $this->postJson(WR_API."/recipients/{$worker->id}/inl-notifications", [])->assertStatus(422)->assertJsonValidationErrors(['work_start_date', 'work_end_date', 'activity_description']);
});

it('records the dispatch with its protocol, flags a late one, and cannot send a cancelled communication', function (): void {
    $worker = wrRecipient($this, 'occasional_worker');
    $make = fn (string $start) => $this->postJson(WR_API."/recipients/{$worker->id}/inl-notifications", ['work_start_date' => $start, 'work_end_date' => '2026-12-31', 'activity_description' => 'Docenza'])->assertCreated()->json('data.id');

    $onTime = $make('2026-06-20');
    $this->postJson(WR_API."/inl-notifications/{$onTime}/mark-sent", ['protocol_reference' => 'INL-2026-0042'])->assertOk()
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.protocol_reference', 'INL-2026-0042')
        ->assertJsonPath('data.sent_before_start', true);

    $late = $make('2026-06-01');
    $this->postJson(WR_API."/inl-notifications/{$late}/mark-sent", [])->assertOk()->assertJsonPath('data.sent_before_start', false);

    $cancelled = $make('2026-09-01');
    $this->postJson(WR_API."/inl-notifications/{$cancelled}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson(WR_API."/inl-notifications/{$cancelled}/mark-sent", [])->assertStatus(422)->assertJsonPath('code', 'inl_cancelled');
    expect(OccasionalInlNotification::query()->where('status', 'sent')->count())->toBe(2);
});

it('does not expose the INL communications of another company', function (): void {
    $worker = wrRecipient($this, 'occasional_worker');
    $id = wrInlSent($this, $worker);

    treasuryActor($this, 'accountant', $this->otherTenant);
    $this->getJson(WR_API."/recipients/{$worker->id}/inl-notifications")->assertNotFound();
    $this->get(WR_API."/inl-notifications/{$id}/download?format=json")->assertNotFound();
    $this->postJson(WR_API."/inl-notifications/{$id}/mark-sent", [])->assertNotFound();
});

/**
 * One June payment for each kind of withholding: royalty (1040), commission (1038) and occasional work above the INPS
 * threshold (1040 + C10).
 */
function wrJunePayments(object $test): void
{
    $author = wrRecipient($test, 'author_royalty');
    $agent = wrRecipient($test, 'agent_25bis');
    $worker = wrRecipient($test, 'occasional_worker', ['third_party_ytd_income' => 4000, 'third_party_income_year' => 2026]);
    wrInlSent($test, $worker);

    $test->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 1000, 'payment_date' => '2026-06-15'])->assertCreated();
    $test->postJson(WR_API.'/settlements', ['recipient_profile_id' => $agent->id, 'gross_amount' => 10000, 'payment_date' => '2026-06-16'])->assertCreated();
    $test->postJson(WR_API.'/settlements', ['recipient_profile_id' => $worker->id, 'gross_amount' => 2000, 'payment_date' => '2026-06-17', 'service_date' => '2026-06-10'])->assertCreated();
}

it('previews the F24 of a month grouped by tax code and schedules it for the 16th of the next month', function (): void {
    wrJunePayments($this);

    $response = $this->getJson(WR_API.'/flows/f24?accrual_month=2026-06')->assertOk()
        ->assertJsonPath('data.month', '2026-06')
        ->assertJsonPath('data.due_date', '2026-07-16');

    $lines = collect($response->json('data.lines'))->keyBy(fn (array $line): string => $line['tax_code']);

    // 1040: royalty 120.00 + occasional 400.00; 1038: commission 1,150.00; C10: 1,000 above the threshold at 33.72%
    expect($lines['1040']['amount'])->toBe('520.00')
        ->and($lines['1040']['settlements'])->toBe(2)
        ->and($lines['1040']['section'])->toBe('erario')
        ->and($lines['1038']['amount'])->toBe('1150.00')
        ->and($lines['C10']['amount'])->toBe('337.20')
        ->and($lines['C10']['section'])->toBe('inps')
        ->and($lines['C10']['reference_month'])->toBe('06')
        ->and($response->json('data.total'))->toBe('2007.20');

    $this->getJson(WR_API.'/flows/f24?accrual_month=2026-05')->assertOk()->assertJsonPath('data.lines', [])->assertJsonPath('data.total', '0.00')->assertJsonPath('data.due_date', null);
    $this->getJson(WR_API.'/flows/f24')->assertStatus(422)->assertJsonValidationErrors(['accrual_month']);
    $this->getJson(WR_API.'/flows/f24?accrual_month=2026-6')->assertStatus(422)->assertJsonValidationErrors(['accrual_month']);
});

it('exports the F24 lines of a month as CSV', function (): void {
    wrJunePayments($this);

    $response = $this->get(WR_API.'/flows/f24/export?accrual_month=2026-06')->assertOk();
    $csv = $response->getContent();

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('f24-2026-06.csv')
        ->and($csv)->toContain('section;tax_code;reference_month;reference_year;amount_debit;due_date')
        ->and($csv)->toContain('erario;1038;06;2026;1150.00;2026-07-16')
        ->and($csv)->toContain('inps;C10;06;2026;337.20;2026-07-16');
});

it('builds the UniEmens Gestione Separata flow only with the payments that owe INPS', function (): void {
    wrJunePayments($this);

    $response = $this->get(WR_API.'/flows/uniemens?accrual_month=2026-06')->assertOk()->assertHeader('Content-Type', 'application/xml');
    $xml = simplexml_load_string($response->getContent());

    expect($xml)->not->toBeFalse()
        ->and((string) $xml->Periodo)->toBe('2026-06')
        ->and((string) $xml->Committente->Denominazione)->toBe('Compensi Srl')
        ->and(count($xml->ListaPosGS->PosGS))->toBe(1)
        ->and((string) $xml->ListaPosGS->PosGS[0]->Nominativo)->toBe('Luca Occasionale')
        ->and((string) $xml->ListaPosGS->PosGS[0]->ImponibileContributivo)->toBe('1000.00')
        ->and((string) $xml->ListaPosGS->PosGS[0]->ContributoTotale)->toBe('337.20')
        ->and((string) $xml->ListaPosGS->PosGS[0]->QuotaLavoratore)->toBe('112.40')
        ->and((string) $xml->ListaPosGS->PosGS[0]->QuotaCommittente)->toBe('224.80');

    $empty = simplexml_load_string($this->get(WR_API.'/flows/uniemens?accrual_month=2026-05')->assertOk()->getContent());
    expect(count($empty->ListaPosGS->PosGS))->toBe(0);
});

it('aggregates the posted compensations of a year into the CU records with causal and non-taxable code', function (): void {
    wrJunePayments($this);
    $author = wrRecipient($this, 'author_royalty', ['display_name' => 'Secondo Autore', 'tax_code' => 'BRNSND70A01H501P', 'birth_date' => '1970-01-01']);
    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 1000, 'payment_date' => '2026-03-10'])->assertCreated();
    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 2000, 'payment_date' => '2026-09-10'])->assertCreated();

    $records = $this->postJson(WR_API.'/annual-returns/generate', ['tax_year' => 2026])->assertCreated()->assertJsonCount(4, 'data')->json('data');
    $byName = collect($records)->keyBy('recipient_name');

    expect($byName['Secondo Autore'])->toMatchArray(['return_type' => 'CU', 'causal' => 'B', 'gross_total' => '3000.00', 'non_taxable_total' => '750.00', 'non_taxable_code' => '22', 'taxable_total' => '2250.00', 'withholding_total' => '450.00', 'settlement_count' => 2, 'tax_year' => 2026])
        ->and($byName['Mario Agente'])->toMatchArray(['causal' => 'Q', 'gross_total' => '10000.00', 'withholding_total' => '1150.00', 'enasarco_total' => '875.00'])
        ->and($byName['Luca Occasionale'])->toMatchArray(['causal' => 'M', 'gross_total' => '2000.00', 'withholding_total' => '400.00', 'inps_worker_total' => '112.40', 'inps_company_total' => '224.80']);

    $this->getJson(WR_API.'/annual-returns?tax_year=2026')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('meta.tax_year', 2026);
    $this->getJson(WR_API.'/annual-returns?tax_year=2025')->assertOk()->assertJsonCount(0, 'data');
});

it('regenerates the records of a year without duplicating them and reflects later payments', function (): void {
    $author = wrRecipient($this, 'author_royalty');
    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 1000, 'payment_date' => '2026-03-10'])->assertCreated();

    $this->postJson(WR_API.'/annual-returns/generate', ['tax_year' => 2026])->assertCreated()->assertJsonPath('data.0.gross_total', '1000.00');
    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 500, 'payment_date' => '2026-04-10'])->assertCreated();
    $this->postJson(WR_API.'/annual-returns/generate', ['tax_year' => 2026, 'recipient_profile_id' => $author->id])->assertCreated()->assertJsonCount(1, 'data')->assertJsonPath('data.0.gross_total', '1500.00');

    $this->getJson(WR_API.'/annual-returns?tax_year=2026')->assertOk()->assertJsonCount(1, 'data');
});

it('reports Swiss compensations in an AVS and source tax statement instead of a CU', function (): void {
    $artist = wrRecipient($this, 'swiss_foreign_artist');
    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $artist->id, 'gross_amount' => 4000, 'payment_date' => '2026-06-15', 'performance_days' => 2])->assertCreated();

    $this->postJson(WR_API.'/annual-returns/generate', ['tax_year' => 2026])->assertCreated()
        ->assertJsonPath('data.0.return_type', 'AVS_QST')
        ->assertJsonPath('data.0.causal', null)
        ->assertJsonPath('data.0.currency', 'CHF')
        ->assertJsonPath('data.0.source_tax_total', '100.00')
        ->assertJsonPath('data.0.avs_total', '424.00');
});

it('assigns a payment made by 12 January to the previous year in the annual return', function (): void {
    $author = wrRecipient($this, 'author_royalty');
    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 1000, 'payment_date' => '2027-01-10', 'service_date' => '2026-12-20'])->assertCreated();

    $this->postJson(WR_API.'/annual-returns/generate', ['tax_year' => 2026])->assertCreated()->assertJsonCount(1, 'data');
    $this->postJson(WR_API.'/annual-returns/generate', ['tax_year' => 2027])->assertCreated()->assertJsonCount(0, 'data');
});

it('exports the annual records as CSV', function (): void {
    wrJunePayments($this);
    $this->postJson(WR_API.'/annual-returns/generate', ['tax_year' => 2026])->assertCreated();

    $response = $this->get(WR_API.'/annual-returns/export?tax_year=2026')->assertOk();
    $csv = $response->getContent();

    expect($response->headers->get('Content-Disposition'))->toContain('cu-2026.csv')
        ->and($csv)->toContain('tax_year;return_type;recipient;tax_code;avs_number;causal;currency;gross;non_taxable')
        ->and($csv)->toContain('2026;CU;"Mario Agente";GNTMRA70A01H501K')
        ->and(substr_count($csv, "\n"))->toBe(4);
    $this->getJson(WR_API.'/annual-returns/export')->assertStatus(422)->assertJsonValidationErrors(['tax_year']);
});

it('keeps the annual returns of a company private', function (): void {
    wrJunePayments($this);
    $this->postJson(WR_API.'/annual-returns/generate', ['tax_year' => 2026])->assertCreated();

    treasuryActor($this, 'accountant', $this->otherTenant);
    $this->getJson(WR_API.'/annual-returns?tax_year=2026')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson(WR_API.'/annual-returns/generate', ['tax_year' => 2026])->assertCreated()->assertJsonCount(0, 'data');
    $this->getJson(WR_API.'/flows/f24?accrual_month=2026-06')->assertOk()->assertJsonPath('data.lines', []);
    expect($this->get(WR_API.'/annual-returns/export?tax_year=2026')->getContent())->not->toContain('Mario Agente');
});
