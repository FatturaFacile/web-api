<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Hse\Domain\Enums\CertificateType;
use Modules\Hse\Domain\Events\LostTimeWorkplaceAccidentReportedEvent;
use Modules\Hse\Infrastructure\Models\AccidentClaimDraft;
use Modules\Hse\Infrastructure\Models\PpeDistributionRecord;
use Modules\Hse\Infrastructure\Models\WorkplaceIncidentReport;

require_once __DIR__.'/HseTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::statement('PRAGMA defer_foreign_keys = ON');
});

function hseIncidentPayload(string $employeeId, array $overrides = []): array
{
    return [
        'incident_type' => 'lost_time_accident_inail_or_suva', 'incident_date' => '2027-11-20T10:15:00+01:00', 'location_or_site_id' => 'LUG-PLANT-02',
        'involved_employee_ids' => [$employeeId], 'witness_employee_ids' => [], 'description' => 'Hand caught while setting up the CNC.',
        'prognosis_days' => 10,
        'injury_dynamics' => ['activityAtTimeOfAccident' => 'CNC milling setup on 5-axis machining center', 'deviatingEvent' => '41', 'materialAgent' => '10.02', 'injuredBodyPartCode' => '53', 'lesionNatureCode' => '010'],
        ...$overrides,
    ];
}

it('turns a Swiss lost-time accident into the SUVA Sunet payload and a linked draft in Mod 074', function (): void {
    $tenant = hseTenant('CH');
    $tenant->update(['settings' => ['hse' => ['suva_customer_number' => '12345-67.8', 'operating_unit_code' => 'TI-01', 'municipality_bfs' => '5192', 'canton' => 'TI']]]);
    hseActAs($tenant, 'hse');
    $employee = hseEmployee($tenant, ['first_name' => 'Marco', 'last_name' => 'Rossi', 'birth_date' => '1988-04-15', 'hire_date' => '2022-03-01', 'nationality' => 'CH', 'fte_ratio' => 1, 'base_annual_salary' => 84500]);
    hseMatrix($tenant, ['job_position_id' => null, 'work_center_id' => $wc = (string) Str::uuid(), 'suva_hazard_class' => '41A']);
    PpeDistributionRecord::create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'product_id' => (string) Str::uuid(), 'warehouse_id' => (string) Str::uuid(), 'quantity' => 1,
        'handed_over_at' => '2027-01-10 08:00:00', 'employee_signature_hash' => str_repeat('d', 64), 'delivery_status' => 'delivered_signed',
    ]);
    Event::fake([LostTimeWorkplaceAccidentReportedEvent::class]);

    $response = $this->postJson('/api/v1/hse/incidents', hseIncidentPayload((string) $employee->id, ['work_center_id' => $wc]))->assertCreated();
    $id = $response->json('data.id');

    expect($response->json('data.protocol_number'))->toBe('INC-CH-2027-0001')->and($response->json('data.status'))->toBe('under_investigation');
    Event::assertDispatched(LostTimeWorkplaceAccidentReportedEvent::class, fn ($event): bool => $event->incidentReportId === $id && $event->jurisdiction === 'CH');

    $draft = AccidentClaimDraft::query()->where('incident_report_id', $id)->firstOrFail();
    $payload = $draft->payload_json;

    expect($response->json('data.linked_insurance_claim_id'))->toBe($draft->id)
        ->and($payload['schemaVersion'])->toBe('SUVA-SUNET-2026.1')->and($payload['sourceModule'])->toBe('MOD-099')
        ->and($payload['employer'])->toMatchArray(['uid' => 'CHE-123.456.789', 'suvaCustomerNumber' => '12345-67.8', 'suvaHazardClass' => '41A', 'operatingUnitCode' => 'TI-01'])
        ->and($payload['insuredPerson'])->toMatchArray(['lastName' => 'Rossi', 'firstName' => 'Marco', 'birthDate' => '1988-04-15', 'employmentStartDate' => '2022-03-01', 'weeklyContractHours' => 42.0, 'suvaInsuredAnnualSalaryChf' => 84500.0])
        ->and($payload['accidentDetails'])->toMatchArray([
            'accidentCategory' => 'BU', 'occurrenceDateTime' => '2027-11-20T10:15:00+01:00', 'deviatingEventCode' => '41', 'materialAgentCode' => '10.02',
            'injuredBodyPartCode' => '53', 'lesionNatureCode' => '010', 'ppeWornAtTimeOfAccident' => true, 'expectedIncapacityDays' => 10,
        ])
        ->and($payload['accidentDetails']['workplaceLocation'])->toMatchArray(['siteCode' => 'LUG-PLANT-02', 'municipalityBfs' => '5192', 'canton' => 'TI'])
        ->and($payload['accidentDetails']['verifiedPpeRecordIds'])->toHaveCount(1);
});

it('turns an Italian accident of more than 3 days into the INAIL denuncia payload', function (): void {
    $tenant = hseTenant('IT');
    hseActAs($tenant, 'hse');
    $tenant->update(['settings' => ['hse' => ['inail_company_code' => '12345678', 'inail_pat' => '98765432-10', 'inail_tariff_item' => '6212', 'ateco2007' => '25.62.00', 'istat_municipality' => '012092', 'ccnl_code' => 'METALMECCANICO_INDUSTRIA']]]);
    $employee = hseEmployee($tenant, ['first_name' => 'Giuseppe', 'last_name' => 'Bianchi', 'birth_date' => '1985-08-10', 'gender' => 'M', 'tax_code' => 'BNCGPP85M10E734K', 'base_annual_salary' => 43234.25]);
    $witness = hseEmployee($tenant, ['first_name' => 'Luca', 'last_name' => 'Verdi']);

    $id = $this->postJson('/api/v1/hse/incidents', hseIncidentPayload((string) $employee->id, [
        'incident_date' => '2027-11-20T14:30:00+01:00', 'prognosis_days' => 15, 'witness_employee_ids' => [(string) $witness->id], 'location_or_site_id' => 'Reparto Carpenteria - Linea Saldatura B',
    ]))->assertCreated()->json('data.id');

    $payload = AccidentClaimDraft::query()->where('incident_report_id', $id)->firstOrFail()->payload_json;

    expect($payload['schemaVersion'])->toBe('INAIL-DENUNCIA-2026.1')->and($payload['declarationType'])->toBe('DENUNCIA_INFORTUNIO')
        ->and($payload['protocolNumber'])->toBe('INC-IT-2027-0001')
        ->and($payload['employer'])->toMatchArray(['codiceFiscale' => $tenant->fiscal_code, 'codiceDittaInail' => '12345678', 'patInail' => '98765432-10', 'voceTariffaInail' => '6212', 'ateco2007' => '25.62.00'])
        ->and($payload['worker'])->toMatchArray(['codiceFiscale' => 'BNCGPP85M10E734K', 'lastName' => 'Bianchi', 'gender' => 'M', 'birthDate' => '1985-08-10', 'retribuzioneMediaGiornalieraEur' => 118.45])
        ->and($payload['eventDetails'])->toMatchArray(['eventDateTime' => '2027-11-20T14:30:00+01:00', 'prognosisStartDate' => '2027-11-20', 'prognosisEndDate' => '2027-12-05', 'prognosisDays' => 15, 'municipalityIstatCode' => '012092'])
        ->and($payload['eventDetails']['esawCodes'])->toMatchArray(['deviation' => '41', 'materialAgent' => '10.02', 'injuredBodyPart' => '53', 'natureOfInjury' => '010'])
        ->and(array_column($payload['eventDetails']['witnesses'], 'fullName'))->toBe(['Luca Verdi']);
});

it('picks the Italian declaration type from the days of prognosis', function (int $days, string $incidentType, string $expected): void {
    $tenant = hseTenant('IT');
    hseActAs($tenant, 'hse');
    $employee = hseEmployee($tenant);

    $id = $this->postJson('/api/v1/hse/incidents', hseIncidentPayload((string) $employee->id, ['incident_type' => $incidentType, 'prognosis_days' => $days]))->assertCreated()->json('data.id');

    expect(AccidentClaimDraft::query()->where('incident_report_id', $id)->value('declaration_type'))->toBe($expected);
})->with([
    'communication 1-3 days' => [2, 'lost_time_accident_inail_or_suva', 'COMUNICAZIONE_1_3_GG'],
    'denuncia above 3 days' => [4, 'lost_time_accident_inail_or_suva', 'DENUNCIA_INFORTUNIO'],
    'fatal' => [30, 'fatal_or_severe', 'INFORTUNIO_MORTALE'],
]);

it('creates a draft for a first-aid injury only above the statutory threshold of the jurisdiction', function (string $country, int $days, bool $drafted): void {
    $tenant = hseTenant($country);
    hseActAs($tenant, 'hse');
    $employee = hseEmployee($tenant);

    $this->postJson('/api/v1/hse/incidents', hseIncidentPayload((string) $employee->id, ['incident_type' => 'first_aid_injury', 'prognosis_days' => $days]))->assertCreated();

    expect(AccidentClaimDraft::query()->count())->toBe($drafted ? 1 : 0);
})->with([
    'IT one day' => ['IT', 1, false], 'IT two days' => ['IT', 2, true], 'CH three days' => ['CH', 3, false], 'CH four days' => ['CH', 4, true],
]);

it('logs a near miss without a claim and numbers the protocols per year and jurisdiction', function (): void {
    $tenant = hseTenant('CH');
    hseActAs($tenant, 'member');
    hseActAs($tenant, 'hse');

    foreach ([1, 2] as $expected) {
        $response = $this->postJson('/api/v1/hse/incidents', ['incident_type' => 'near_miss', 'incident_date' => '2027-05-05T08:00:00+02:00', 'description' => 'Spilled oil near the press.'])->assertCreated();
        expect($response->json('data.protocol_number'))->toBe('INC-CH-2027-'.str_pad((string) $expected, 4, '0', STR_PAD_LEFT))->and($response->json('data.status'))->toBe('draft');
    }

    expect(AccidentClaimDraft::query()->count())->toBe(0);
});

it('refuses a reportable accident without an injured employee and stores nothing', function (): void {
    $tenant = hseTenant('CH');
    hseActAs($tenant, 'hse');

    $this->postJson('/api/v1/hse/incidents', hseIncidentPayload('', ['involved_employee_ids' => []]))->assertUnprocessable();

    expect(WorkplaceIncidentReport::query()->count())->toBe(0);
});

it('verifies the training and PPE of the injured worker in the Italian declaration', function (): void {
    $tenant = hseTenant('IT');
    hseActAs($tenant, 'hse');
    $employee = hseEmployee($tenant);
    $wc = (string) Str::uuid();
    hseMatrix($tenant, ['job_position_id' => null, 'work_center_id' => $wc, 'required_training_types_json' => [['type' => CertificateType::GeneralSafety->value]], 'required_ppe_product_ids_json' => [(string) Str::uuid()]]);

    $id = $this->postJson('/api/v1/hse/incidents', hseIncidentPayload((string) $employee->id, ['work_center_id' => $wc]))->assertCreated()->json('data.id');
    $details = AccidentClaimDraft::query()->where('incident_report_id', $id)->firstOrFail()->payload_json['eventDetails'];

    expect($details['mandatoryTrainingVerified'])->toBeFalse()->and($details['ppeDeliveredAndSignedVerified'])->toBeFalse();
});

it('serves the claim draft of an incident and hides it from other tenants', function (): void {
    $tenant = hseTenant('CH');
    hseActAs($tenant, 'hse');
    $employee = hseEmployee($tenant);
    $id = $this->postJson('/api/v1/hse/incidents', hseIncidentPayload((string) $employee->id))->assertCreated()->json('data.id');

    $this->getJson("/api/v1/hse/incidents/{$id}/claim-draft")->assertOk()->assertJsonPath('data.schema_version', 'SUVA-SUNET-2026.1');

    $other = hseTenant('CH');
    hseActAs($other, 'hse');
    $this->getJson("/api/v1/hse/incidents/{$id}/claim-draft")->assertNotFound();
});
